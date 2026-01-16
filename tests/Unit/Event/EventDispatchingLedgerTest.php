<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit\Event;

use Chemaclass\Unspent\CoinbaseTx;
use Chemaclass\Unspent\Event\CoinbaseApplied;
use Chemaclass\Unspent\Event\EventDispatchingLedger;
use Chemaclass\Unspent\Event\LedgerEvent;
use Chemaclass\Unspent\Event\OutputCreated;
use Chemaclass\Unspent\Event\OutputSpent;
use Chemaclass\Unspent\Event\TransactionApplied;
use Chemaclass\Unspent\Event\ValidationFailed;
use Chemaclass\Unspent\Exception\InsufficientSpendsException;
use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\Tx;
use PHPUnit\Framework\TestCase;

final class EventDispatchingLedgerTest extends TestCase
{
    /** @var list<LedgerEvent> */
    private array $dispatchedEvents = [];

    protected function setUp(): void
    {
        $this->dispatchedEvents = [];
    }

    public function test_wrap_creates_event_dispatching_ledger(): void
    {
        $ledger = Ledger::inMemory();
        $eventLedger = EventDispatchingLedger::wrap($ledger, $this->captureDispatcher());

        self::assertInstanceOf(EventDispatchingLedger::class, $eventLedger);
    }

    public function test_unwrap_returns_underlying_ledger(): void
    {
        $ledger = Ledger::inMemory();
        $eventLedger = EventDispatchingLedger::wrap($ledger, $this->captureDispatcher());

        self::assertSame($ledger, $eventLedger->unwrap());
    }

    public function test_apply_dispatches_transaction_applied_event(): void
    {
        $eventLedger = $this->createLedgerWithBalance('alice', 100);

        $tx = Tx::create(
            spendIds: [$this->getFirstOutputId($eventLedger)],
            outputs: [Output::ownedBy('bob', 90)],
            signedBy: 'alice',
        );

        $eventLedger->apply($tx);

        $transactionAppliedEvents = $this->filterEvents(TransactionApplied::class);
        self::assertCount(1, $transactionAppliedEvents);

        $event = $transactionAppliedEvents[0];
        self::assertSame($tx, $event->transaction);
        self::assertSame(10, $event->fee);
        self::assertSame(100, $event->inputTotal);
        self::assertSame(90, $event->outputTotal);
    }

    public function test_apply_dispatches_output_spent_event(): void
    {
        $eventLedger = $this->createLedgerWithBalance('alice', 100);
        $spendId = $this->getFirstOutputId($eventLedger);

        $tx = Tx::create(
            spendIds: [$spendId],
            outputs: [Output::ownedBy('bob', 100)],
            signedBy: 'alice',
        );

        $eventLedger->apply($tx);

        $outputSpentEvents = $this->filterEvents(OutputSpent::class);
        self::assertCount(1, $outputSpentEvents);

        $event = $outputSpentEvents[0];
        self::assertSame($spendId, $event->outputId->value);
        self::assertSame(100, $event->amount);
        self::assertSame($tx->id, $event->spentBy);
    }

    public function test_apply_dispatches_output_created_events(): void
    {
        $eventLedger = $this->createLedgerWithBalance('alice', 100);

        $tx = Tx::create(
            spendIds: [$this->getFirstOutputId($eventLedger)],
            outputs: [
                Output::ownedBy('bob', 50),
                Output::ownedBy('charlie', 40),
            ],
            signedBy: 'alice',
        );

        $eventLedger->apply($tx);

        $outputCreatedEvents = $this->filterEvents(OutputCreated::class);
        self::assertCount(2, $outputCreatedEvents);
    }

    public function test_apply_dispatches_validation_failed_on_error(): void
    {
        $eventLedger = $this->createLedgerWithBalance('alice', 100);

        $tx = Tx::create(
            spendIds: [$this->getFirstOutputId($eventLedger)],
            outputs: [Output::ownedBy('bob', 200)], // More than available
            signedBy: 'alice',
        );

        try {
            $eventLedger->apply($tx);
            self::fail('Expected InsufficientSpendsException');
        } catch (InsufficientSpendsException) {
            // Expected
        }

        $validationFailedEvents = $this->filterEvents(ValidationFailed::class);
        self::assertCount(1, $validationFailedEvents);

        $event = $validationFailedEvents[0];
        self::assertSame($tx, $event->transaction);
        self::assertInstanceOf(InsufficientSpendsException::class, $event->exception);
    }

    public function test_apply_coinbase_dispatches_coinbase_applied_event(): void
    {
        $ledger = Ledger::inMemory();
        $eventLedger = EventDispatchingLedger::wrap($ledger, $this->captureDispatcher());

        $coinbase = CoinbaseTx::create(
            outputs: [Output::ownedBy('miner', 50)],
        );

        $eventLedger->applyCoinbase($coinbase);

        $coinbaseAppliedEvents = $this->filterEvents(CoinbaseApplied::class);
        self::assertCount(1, $coinbaseAppliedEvents);

        $event = $coinbaseAppliedEvents[0];
        self::assertSame($coinbase, $event->coinbase);
        self::assertSame(50, $event->mintedAmount);
        self::assertSame(50, $event->totalMinted);
    }

    public function test_apply_coinbase_dispatches_output_created_events(): void
    {
        $ledger = Ledger::inMemory();
        $eventLedger = EventDispatchingLedger::wrap($ledger, $this->captureDispatcher());

        $coinbase = CoinbaseTx::create(
            outputs: [
                Output::ownedBy('miner', 30),
                Output::ownedBy('pool', 20),
            ],
        );

        $eventLedger->applyCoinbase($coinbase);

        $outputCreatedEvents = $this->filterEvents(OutputCreated::class);
        self::assertCount(2, $outputCreatedEvents);
    }

    public function test_transfer_returns_new_event_dispatching_ledger(): void
    {
        $eventLedger = $this->createLedgerWithBalance('alice', 100);

        $newLedger = $eventLedger->transfer('alice', 'bob', 50);

        self::assertInstanceOf(EventDispatchingLedger::class, $newLedger);
        self::assertNotSame($eventLedger, $newLedger);
        self::assertSame(50, $newLedger->totalUnspentByOwner('bob'));
    }

    public function test_debit_returns_new_event_dispatching_ledger(): void
    {
        $eventLedger = $this->createLedgerWithBalance('alice', 100);

        $newLedger = $eventLedger->debit('alice', 30);

        self::assertInstanceOf(EventDispatchingLedger::class, $newLedger);
        self::assertSame(70, $newLedger->totalUnspentByOwner('alice'));
    }

    public function test_credit_returns_new_event_dispatching_ledger(): void
    {
        $ledger = Ledger::inMemory();
        $eventLedger = EventDispatchingLedger::wrap($ledger, $this->captureDispatcher());

        $newLedger = $eventLedger->credit('alice', 100);

        self::assertInstanceOf(EventDispatchingLedger::class, $newLedger);
        self::assertSame(100, $newLedger->totalUnspentByOwner('alice'));
    }

    public function test_read_only_methods_delegate_to_underlying_ledger(): void
    {
        $eventLedger = $this->createLedgerWithBalance('alice', 100);

        self::assertSame(100, $eventLedger->totalUnspentAmount());
        self::assertSame(100, $eventLedger->totalUnspentByOwner('alice'));
        self::assertSame(0, $eventLedger->totalUnspentByOwner('bob'));
        self::assertFalse($eventLedger->unspent()->isEmpty());
        self::assertSame(1, $eventLedger->unspent()->count());
    }

    public function test_to_array_serializes_ledger_state(): void
    {
        $eventLedger = $this->createLedgerWithBalance('alice', 100);

        $array = $eventLedger->toArray();

        self::assertArrayHasKey('unspent', $array);
        self::assertArrayHasKey('version', $array);
    }

    public function test_to_json_serializes_ledger_state(): void
    {
        $eventLedger = $this->createLedgerWithBalance('alice', 100);

        $json = $eventLedger->toJson();

        self::assertJson($json);
        $decoded = json_decode($json, true);
        self::assertArrayHasKey('unspent', $decoded);
    }

    public function test_can_apply_returns_null_for_valid_tx(): void
    {
        $eventLedger = $this->createLedgerWithBalance('alice', 100);

        $tx = Tx::create(
            spendIds: [$this->getFirstOutputId($eventLedger)],
            outputs: [Output::ownedBy('bob', 100)],
            signedBy: 'alice',
        );

        self::assertNull($eventLedger->canApply($tx));
    }

    public function test_can_apply_returns_exception_for_invalid_tx(): void
    {
        $eventLedger = $this->createLedgerWithBalance('alice', 100);

        $tx = Tx::create(
            spendIds: [$this->getFirstOutputId($eventLedger)],
            outputs: [Output::ownedBy('bob', 200)],
            signedBy: 'alice',
        );

        self::assertInstanceOf(InsufficientSpendsException::class, $eventLedger->canApply($tx));
    }

    public function test_apply_returns_new_event_dispatching_ledger(): void
    {
        $eventLedger = $this->createLedgerWithBalance('alice', 100);

        $tx = Tx::create(
            spendIds: [$this->getFirstOutputId($eventLedger)],
            outputs: [Output::ownedBy('bob', 100)],
            signedBy: 'alice',
        );

        $newLedger = $eventLedger->apply($tx);

        self::assertInstanceOf(EventDispatchingLedger::class, $newLedger);
        self::assertNotSame($eventLedger, $newLedger);
    }

    public function test_is_tx_applied_returns_false_initially(): void
    {
        $eventLedger = $this->createLedgerWithBalance('alice', 100);
        $txId = new \Chemaclass\Unspent\TxId('nonexistent');

        self::assertFalse($eventLedger->isTxApplied($txId));
    }

    public function test_total_fees_collected_returns_zero_initially(): void
    {
        $ledger = Ledger::inMemory();
        $eventLedger = EventDispatchingLedger::wrap($ledger, $this->captureDispatcher());

        self::assertSame(0, $eventLedger->totalFeesCollected());
    }

    public function test_fee_for_tx_returns_null_for_unknown_tx(): void
    {
        $eventLedger = $this->createLedgerWithBalance('alice', 100);
        $txId = new \Chemaclass\Unspent\TxId('unknown');

        self::assertNull($eventLedger->feeForTx($txId));
    }

    public function test_all_tx_fees_returns_empty_initially(): void
    {
        $ledger = Ledger::inMemory();
        $eventLedger = EventDispatchingLedger::wrap($ledger, $this->captureDispatcher());

        self::assertSame([], $eventLedger->allTxFees());
    }

    public function test_total_minted_returns_minted_amount(): void
    {
        $ledger = Ledger::inMemory();
        $eventLedger = EventDispatchingLedger::wrap($ledger, $this->captureDispatcher());

        $newLedger = $eventLedger->credit('alice', 100);

        self::assertSame(100, $newLedger->totalMinted());
    }

    public function test_is_coinbase_returns_true_for_coinbase_tx(): void
    {
        $ledger = Ledger::inMemory();
        $eventLedger = EventDispatchingLedger::wrap($ledger, $this->captureDispatcher());

        $coinbase = CoinbaseTx::create(
            outputs: [Output::ownedBy('miner', 50)],
        );

        $newLedger = $eventLedger->applyCoinbase($coinbase);

        self::assertTrue($newLedger->isCoinbase($coinbase->id));
    }

    public function test_coinbase_amount_returns_amount_for_coinbase_tx(): void
    {
        $ledger = Ledger::inMemory();
        $eventLedger = EventDispatchingLedger::wrap($ledger, $this->captureDispatcher());

        $coinbase = CoinbaseTx::create(
            outputs: [Output::ownedBy('miner', 50)],
        );

        $newLedger = $eventLedger->applyCoinbase($coinbase);

        self::assertSame(50, $newLedger->coinbaseAmount($coinbase->id));
    }

    public function test_output_created_by_returns_tx_id(): void
    {
        $eventLedger = $this->createLedgerWithBalance('alice', 100);
        $outputId = $eventLedger->unspent()->outputIds()[0];

        $createdBy = $eventLedger->outputCreatedBy($outputId);

        self::assertNotNull($createdBy);
    }

    public function test_output_spent_by_returns_null_for_unspent(): void
    {
        $eventLedger = $this->createLedgerWithBalance('alice', 100);
        $outputId = $eventLedger->unspent()->outputIds()[0];

        self::assertNull($eventLedger->outputSpentBy($outputId));
    }

    public function test_get_output_returns_output(): void
    {
        $eventLedger = $this->createLedgerWithBalance('alice', 100);
        $outputId = $eventLedger->unspent()->outputIds()[0];

        $output = $eventLedger->getOutput($outputId);

        self::assertNotNull($output);
        self::assertSame(100, $output->amount);
    }

    public function test_output_exists_returns_true_for_existing(): void
    {
        $eventLedger = $this->createLedgerWithBalance('alice', 100);
        $outputId = $eventLedger->unspent()->outputIds()[0];

        self::assertTrue($eventLedger->outputExists($outputId));
    }

    public function test_output_exists_returns_false_for_nonexistent(): void
    {
        $ledger = Ledger::inMemory();
        $eventLedger = EventDispatchingLedger::wrap($ledger, $this->captureDispatcher());

        self::assertFalse($eventLedger->outputExists(new \Chemaclass\Unspent\OutputId('nonexistent')));
    }

    public function test_output_history_returns_history(): void
    {
        $eventLedger = $this->createLedgerWithBalance('alice', 100);
        $outputId = $eventLedger->unspent()->outputIds()[0];

        $history = $eventLedger->outputHistory($outputId);

        self::assertNotNull($history);
        self::assertSame(100, $history->amount);
    }

    public function test_history_repository_returns_repository(): void
    {
        $eventLedger = $this->createLedgerWithBalance('alice', 100);

        $repository = $eventLedger->historyRepository();

        self::assertInstanceOf(\Chemaclass\Unspent\Persistence\HistoryRepository::class, $repository);
    }

    public function test_unspent_by_owner_returns_filtered_set(): void
    {
        $eventLedger = $this->createLedgerWithBalance('alice', 100);
        $eventLedger = $eventLedger->credit('bob', 50);

        $aliceUnspent = $eventLedger->unspentByOwner('alice');

        self::assertSame(1, $aliceUnspent->count());
        self::assertSame(100, $aliceUnspent->totalAmount());
    }

    /**
     * @return callable(LedgerEvent): void
     */
    private function captureDispatcher(): callable
    {
        return function (LedgerEvent $event): void {
            $this->dispatchedEvents[] = $event;
        };
    }

    private function createLedgerWithBalance(string $owner, int $amount): EventDispatchingLedger
    {
        $ledger = Ledger::inMemory()->credit($owner, $amount);

        return EventDispatchingLedger::wrap($ledger, $this->captureDispatcher());
    }

    private function getFirstOutputId(EventDispatchingLedger $ledger): string
    {
        $outputIds = $ledger->unspent()->outputIds();

        return $outputIds[0]->value;
    }

    /**
     * @template T of LedgerEvent
     *
     * @param class-string<T> $eventClass
     *
     * @return list<T>
     */
    private function filterEvents(string $eventClass): array
    {
        return array_values(array_filter(
            $this->dispatchedEvents,
            static fn (LedgerEvent $event): bool => $event instanceof $eventClass,
        ));
    }
}
