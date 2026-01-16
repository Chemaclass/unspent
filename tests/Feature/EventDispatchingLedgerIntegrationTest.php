<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Feature;

use Chemaclass\Unspent\CoinbaseTx;
use Chemaclass\Unspent\Event\CoinbaseApplied;
use Chemaclass\Unspent\Event\EventDispatchingLedger;
use Chemaclass\Unspent\Event\LedgerEvent;
use Chemaclass\Unspent\Event\OutputCreated;
use Chemaclass\Unspent\Event\OutputSpent;
use Chemaclass\Unspent\Event\TransactionApplied;
use Chemaclass\Unspent\Event\ValidationFailed;
use Chemaclass\Unspent\Exception\InsufficientSpendsException;
use Chemaclass\Unspent\Exception\OutputAlreadySpentException;
use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\Tx;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EventDispatchingLedgerIntegrationTest extends TestCase
{
    #[Test]
    public function dispatches_events_on_successful_transaction(): void
    {
        $events = [];
        $dispatcher = static function (LedgerEvent $event) use (&$events): void {
            $events[] = $event;
        };

        $ledger = EventDispatchingLedger::wrap(
            Ledger::withGenesis(Output::open(1000, 'genesis')),
            $dispatcher,
        );

        $ledger->apply(Tx::create(
            spendIds: ['genesis'],
            outputs: [
                Output::open(600, 'alice'),
                Output::open(390, 'bob'),
            ],
            id: 'tx-001',
        ));

        self::assertCount(4, $events);

        // First event: TransactionApplied
        self::assertInstanceOf(TransactionApplied::class, $events[0]);
        self::assertSame('tx-001', $events[0]->transaction->id->value);
        self::assertSame(10, $events[0]->fee);
        self::assertSame(1000, $events[0]->inputTotal);
        self::assertSame(990, $events[0]->outputTotal);
        self::assertGreaterThan(0, $events[0]->timestamp);

        // Second event: OutputSpent
        self::assertInstanceOf(OutputSpent::class, $events[1]);
        self::assertSame('genesis', $events[1]->outputId->value);
        self::assertSame(1000, $events[1]->amount);
        self::assertSame('tx-001', $events[1]->spentBy->value);

        // Third event: OutputCreated (alice)
        self::assertInstanceOf(OutputCreated::class, $events[2]);
        self::assertSame('alice', $events[2]->output->id->value);
        self::assertSame(600, $events[2]->output->amount);
        self::assertSame('tx-001', $events[2]->createdBy->value);

        // Fourth event: OutputCreated (bob)
        self::assertInstanceOf(OutputCreated::class, $events[3]);
        self::assertSame('bob', $events[3]->output->id->value);
        self::assertSame(390, $events[3]->output->amount);
    }

    #[Test]
    public function dispatches_events_on_coinbase_transaction(): void
    {
        $events = [];
        $dispatcher = static function (LedgerEvent $event) use (&$events): void {
            $events[] = $event;
        };

        $ledger = EventDispatchingLedger::wrap(
            Ledger::inMemory(),
            $dispatcher,
        );

        $ledger->applyCoinbase(CoinbaseTx::create(
            outputs: [
                Output::open(50, 'miner-reward'),
                Output::open(10, 'dev-fund'),
            ],
            id: 'block-1',
        ));

        self::assertCount(3, $events);

        // First event: CoinbaseApplied
        self::assertInstanceOf(CoinbaseApplied::class, $events[0]);
        self::assertSame('block-1', $events[0]->coinbase->id->value);
        self::assertSame(60, $events[0]->mintedAmount);
        self::assertSame(60, $events[0]->totalMinted);
        self::assertGreaterThan(0, $events[0]->timestamp);

        // Second event: OutputCreated (miner-reward)
        self::assertInstanceOf(OutputCreated::class, $events[1]);
        self::assertSame('miner-reward', $events[1]->output->id->value);
        self::assertSame(50, $events[1]->output->amount);
        self::assertSame('block-1', $events[1]->createdBy->value);

        // Third event: OutputCreated (dev-fund)
        self::assertInstanceOf(OutputCreated::class, $events[2]);
        self::assertSame('dev-fund', $events[2]->output->id->value);
        self::assertSame(10, $events[2]->output->amount);
    }

    #[Test]
    public function dispatches_validation_failed_event_on_invalid_transaction(): void
    {
        $events = [];
        $dispatcher = static function (LedgerEvent $event) use (&$events): void {
            $events[] = $event;
        };

        $ledger = EventDispatchingLedger::wrap(
            Ledger::withGenesis(Output::open(100, 'genesis')),
            $dispatcher,
        );

        $invalidTx = Tx::create(
            spendIds: ['genesis'],
            outputs: [Output::open(200, 'too-much')], // More than available
            id: 'invalid-tx',
        );

        $exceptionThrown = false;
        try {
            $ledger->apply($invalidTx);
        } catch (InsufficientSpendsException) {
            $exceptionThrown = true;
        }

        self::assertTrue($exceptionThrown, 'Expected InsufficientSpendsException to be thrown');
        self::assertCount(1, $events);
        self::assertInstanceOf(ValidationFailed::class, $events[0]);
        self::assertSame('invalid-tx', $events[0]->transaction->id->value);
        self::assertInstanceOf(InsufficientSpendsException::class, $events[0]->exception);
    }

    #[Test]
    public function dispatches_validation_failed_on_double_spend(): void
    {
        $events = [];
        $dispatcher = static function (LedgerEvent $event) use (&$events): void {
            $events[] = $event;
        };

        $baseLedger = Ledger::withGenesis(Output::open(100, 'funds'))
            ->apply(Tx::create(
                spendIds: ['funds'],
                outputs: [Output::open(100, 'spent')],
                id: 'tx-1',
            ));

        $ledger = EventDispatchingLedger::wrap($baseLedger, $dispatcher);

        $doubleSpendTx = Tx::create(
            spendIds: ['funds'], // Already spent
            outputs: [Output::open(100, 'stolen')],
            id: 'double-spend',
        );

        $exceptionThrown = false;
        try {
            $ledger->apply($doubleSpendTx);
        } catch (OutputAlreadySpentException) {
            $exceptionThrown = true;
        }

        self::assertTrue($exceptionThrown);
        self::assertCount(1, $events);
        self::assertInstanceOf(ValidationFailed::class, $events[0]);
        self::assertInstanceOf(OutputAlreadySpentException::class, $events[0]->exception);
    }

    #[Test]
    public function handles_multiple_inputs_and_outputs(): void
    {
        $events = [];
        $dispatcher = static function (LedgerEvent $event) use (&$events): void {
            $events[] = $event;
        };

        $ledger = EventDispatchingLedger::wrap(
            Ledger::withGenesis(
                Output::open(100, 'in-1'),
                Output::open(200, 'in-2'),
                Output::open(300, 'in-3'),
            ),
            $dispatcher,
        );

        $ledger->apply(Tx::create(
            spendIds: ['in-1', 'in-2', 'in-3'],
            outputs: [
                Output::open(150, 'out-1'),
                Output::open(150, 'out-2'),
                Output::open(150, 'out-3'),
                Output::open(140, 'out-4'),
            ],
            id: 'multi-tx',
        ));

        // 1 TransactionApplied + 3 OutputSpent + 4 OutputCreated = 8 events
        self::assertCount(8, $events);

        self::assertInstanceOf(TransactionApplied::class, $events[0]);
        self::assertSame(10, $events[0]->fee); // 600 - 590 = 10

        // 3 OutputSpent events
        self::assertInstanceOf(OutputSpent::class, $events[1]);
        self::assertInstanceOf(OutputSpent::class, $events[2]);
        self::assertInstanceOf(OutputSpent::class, $events[3]);

        // 4 OutputCreated events
        self::assertInstanceOf(OutputCreated::class, $events[4]);
        self::assertInstanceOf(OutputCreated::class, $events[5]);
        self::assertInstanceOf(OutputCreated::class, $events[6]);
        self::assertInstanceOf(OutputCreated::class, $events[7]);
    }

    #[Test]
    public function event_types_return_correct_strings(): void
    {
        $events = [];
        $dispatcher = static function (LedgerEvent $event) use (&$events): void {
            $events[] = $event;
        };

        $ledger = EventDispatchingLedger::wrap(
            Ledger::withGenesis(Output::open(100, 'genesis')),
            $dispatcher,
        );

        // Apply coinbase to get CoinbaseApplied
        $ledger = $ledger->applyCoinbase(CoinbaseTx::create(
            outputs: [Output::open(50, 'minted')],
            id: 'coinbase-1',
        ));

        // Apply transaction to get TransactionApplied, OutputSpent, OutputCreated
        $ledger->apply(Tx::create(
            spendIds: ['genesis'],
            outputs: [Output::open(100, 'transferred')],
            id: 'tx-1',
        ));

        $eventTypes = array_map(static fn (LedgerEvent $e): string => $e->eventType(), $events);

        self::assertContains('coinbase.applied', $eventTypes);
        self::assertContains('transaction.applied', $eventTypes);
        self::assertContains('output.spent', $eventTypes);
        self::assertContains('output.created', $eventTypes);
    }

    #[Test]
    public function chain_of_transactions_dispatches_events_in_order(): void
    {
        $events = [];
        $dispatcher = static function (LedgerEvent $event) use (&$events): void {
            $events[] = $event;
        };

        $ledger = EventDispatchingLedger::wrap(
            Ledger::withGenesis(Output::open(1000, 'genesis')),
            $dispatcher,
        );

        $ledger = $ledger->apply(Tx::create(
            spendIds: ['genesis'],
            outputs: [Output::open(990, 'a')],
            id: 'tx-1',
        ));

        $ledger = $ledger->apply(Tx::create(
            spendIds: ['a'],
            outputs: [Output::open(980, 'b')],
            id: 'tx-2',
        ));

        $ledger->apply(Tx::create(
            spendIds: ['b'],
            outputs: [Output::open(970, 'c')],
            id: 'tx-3',
        ));

        // 3 transactions * (1 TransactionApplied + 1 OutputSpent + 1 OutputCreated) = 9 events
        self::assertCount(9, $events);
        $counter = \count($events);

        // Verify chronological order
        for ($i = 1; $i < $counter; ++$i) {
            self::assertGreaterThanOrEqual(
                $events[$i - 1]->timestamp,
                $events[$i]->timestamp,
                'Events should be in chronological order',
            );
        }
    }

    #[Test]
    public function psr14_compatible_dispatcher_pattern(): void
    {
        // Simulate a PSR-14 event dispatcher
        $eventStore = new class() {
            /** @var array<string, list<LedgerEvent>> */
            public array $events = [];

            public function dispatch(LedgerEvent $event): void
            {
                $type = $event->eventType();
                if (!isset($this->events[$type])) {
                    $this->events[$type] = [];
                }
                $this->events[$type][] = $event;
            }
        };

        $ledger = EventDispatchingLedger::wrap(
            Ledger::withGenesis(Output::open(1000, 'genesis')),
            $eventStore->dispatch(...),
        );

        $ledger->apply(Tx::create(
            spendIds: ['genesis'],
            outputs: [
                Output::open(500, 'alice'),
                Output::open(490, 'bob'),
            ],
            id: 'tx-001',
        ));

        // Events should be grouped by type
        self::assertCount(1, $eventStore->events['transaction.applied']);
        self::assertCount(1, $eventStore->events['output.spent']);
        self::assertCount(2, $eventStore->events['output.created']);
    }

    #[Test]
    public function unwrap_returns_underlying_ledger(): void
    {
        $baseLedger = Ledger::withGenesis(Output::open(1000, 'genesis'));
        $events = [];
        $dispatcher = static function (LedgerEvent $event) use (&$events): void {
            $events[] = $event;
        };

        $wrappedLedger = EventDispatchingLedger::wrap($baseLedger, $dispatcher);
        $unwrapped = $wrappedLedger->unwrap();

        self::assertSame(1000, $unwrapped->totalUnspentAmount());
        self::assertInstanceOf(Ledger::class, $unwrapped);
    }

    #[Test]
    public function read_only_methods_do_not_dispatch_events(): void
    {
        $events = [];
        $dispatcher = static function (LedgerEvent $event) use (&$events): void {
            $events[] = $event;
        };

        $ledger = EventDispatchingLedger::wrap(
            Ledger::withGenesis(Output::open(1000, 'genesis')),
            $dispatcher,
        );

        // Call various read-only methods
        $ledger->totalUnspentAmount();
        $ledger->unspent();
        $ledger->unspent()->count();
        $ledger->totalFeesCollected();
        $ledger->totalMinted();
        $ledger->allTxFees();

        self::assertCount(0, $events);
    }
}
