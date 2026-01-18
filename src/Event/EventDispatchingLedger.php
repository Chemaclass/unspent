<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Event;

use Chemaclass\Unspent\CoinbaseTx;
use Chemaclass\Unspent\Exception\UnspentException;
use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\LedgerInterface;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputHistory;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\Persistence\HistoryRepository;
use Chemaclass\Unspent\Tx;
use Chemaclass\Unspent\TxId;
use Chemaclass\Unspent\UnspentSet;

/**
 * Event-dispatching decorator for Ledger.
 *
 * Wraps a Ledger and dispatches events for all state-changing operations.
 * Compatible with PSR-14 event dispatchers.
 *
 * Usage:
 *     $dispatcher = fn(LedgerEvent $event) => $eventBus->dispatch($event);
 *     $ledger = EventDispatchingLedger::wrap(Ledger::inMemory(), $dispatcher);
 *     $ledger->credit('alice', 100);
 *     // Dispatches: CoinbaseApplied, OutputCreated
 */
final readonly class EventDispatchingLedger implements LedgerInterface
{
    /** @var callable(LedgerEvent): void */
    private mixed $dispatcher;

    /**
     * @param callable(LedgerEvent): void $dispatcher
     */
    private function __construct(
        private Ledger $ledger,
        callable $dispatcher,
    ) {
        $this->dispatcher = $dispatcher;
    }

    /**
     * @param callable(LedgerEvent): void $dispatcher
     */
    public static function wrap(Ledger $ledger, callable $dispatcher): self
    {
        return new self($ledger, $dispatcher);
    }

    public function apply(Tx $tx): static
    {
        // Capture output data before mutation
        $inputTotal = 0;
        $spentOutputs = [];
        foreach ($tx->spends as $spendId) {
            $output = $this->ledger->unspent()->get($spendId);
            if ($output !== null) {
                $inputTotal += $output->amount;
                $spentOutputs[$spendId->value] = $output;
            }
        }

        try {
            $this->ledger->apply($tx);
            $outputTotal = $tx->totalOutputAmount();
            $fee = $inputTotal - $outputTotal;

            // Dispatch transaction applied event
            $this->dispatch(new TransactionApplied(
                transaction: $tx,
                fee: $fee,
                inputTotal: $inputTotal,
                outputTotal: $outputTotal,
            ));

            // Dispatch output spent events (using captured data)
            foreach ($tx->spends as $spendId) {
                $output = $spentOutputs[$spendId->value] ?? null;
                if ($output !== null) {
                    $this->dispatch(new OutputSpent(
                        outputId: $spendId,
                        amount: $output->amount,
                        spentBy: $tx->id,
                    ));
                }
            }

            // Dispatch output created events
            foreach ($tx->outputs as $output) {
                $this->dispatch(new OutputCreated(
                    output: $output,
                    createdBy: $tx->id,
                ));
            }

            return $this;
        } catch (UnspentException $e) {
            $this->dispatch(new ValidationFailed(
                transaction: $tx,
                exception: $e,
            ));
            throw $e;
        }
    }

    public function applyCoinbase(CoinbaseTx $coinbase): static
    {
        $this->ledger->applyCoinbase($coinbase);
        $mintedAmount = $coinbase->totalOutputAmount();

        // Dispatch coinbase applied event
        $this->dispatch(new CoinbaseApplied(
            coinbase: $coinbase,
            mintedAmount: $mintedAmount,
            totalMinted: $this->ledger->totalMinted(),
        ));

        // Dispatch output created events
        foreach ($coinbase->outputs as $output) {
            $this->dispatch(new OutputCreated(
                output: $output,
                createdBy: $coinbase->id,
            ));
        }

        return $this;
    }

    public function transfer(string $from, string $to, int $amount, int $fee = 0, ?string $txId = null): static
    {
        $this->ledger->transfer($from, $to, $amount, $fee, $txId);

        return $this;
    }

    public function debit(string $owner, int $amount, int $fee = 0, ?string $txId = null): static
    {
        $this->ledger->debit($owner, $amount, $fee, $txId);

        return $this;
    }

    public function credit(string $owner, int $amount, ?string $txId = null): static
    {
        $this->ledger->credit($owner, $amount, $txId);

        return $this;
    }

    // Read-only methods delegate directly

    public function unspent(): UnspentSet
    {
        return $this->ledger->unspent();
    }

    public function totalUnspentAmount(): int
    {
        return $this->ledger->totalUnspentAmount();
    }

    public function unspentByOwner(string $owner): UnspentSet
    {
        return $this->ledger->unspentByOwner($owner);
    }

    public function totalUnspentByOwner(string $owner): int
    {
        return $this->ledger->totalUnspentByOwner($owner);
    }

    public function canApply(Tx $tx): ?UnspentException
    {
        return $this->ledger->canApply($tx);
    }

    public function isTxApplied(TxId $txId): bool
    {
        return $this->ledger->isTxApplied($txId);
    }

    public function totalFeesCollected(): int
    {
        return $this->ledger->totalFeesCollected();
    }

    public function feeForTx(TxId $txId): ?int
    {
        return $this->ledger->feeForTx($txId);
    }

    public function allTxFees(): array
    {
        return $this->ledger->allTxFees();
    }

    public function totalMinted(): int
    {
        return $this->ledger->totalMinted();
    }

    public function isCoinbase(TxId $id): bool
    {
        return $this->ledger->isCoinbase($id);
    }

    public function coinbaseAmount(TxId $id): ?int
    {
        return $this->ledger->coinbaseAmount($id);
    }

    public function outputCreatedBy(OutputId $id): ?string
    {
        return $this->ledger->outputCreatedBy($id);
    }

    public function outputSpentBy(OutputId $id): ?string
    {
        return $this->ledger->outputSpentBy($id);
    }

    public function getOutput(OutputId $id): ?Output
    {
        return $this->ledger->getOutput($id);
    }

    public function outputExists(OutputId $id): bool
    {
        return $this->ledger->outputExists($id);
    }

    public function outputHistory(OutputId $id): ?OutputHistory
    {
        return $this->ledger->outputHistory($id);
    }

    public function historyRepository(): HistoryRepository
    {
        return $this->ledger->historyRepository();
    }

    public function toArray(): array
    {
        return $this->ledger->toArray();
    }

    public function toJson(int $flags = 0): string
    {
        return $this->ledger->toJson($flags);
    }

    /**
     * Returns the underlying Ledger instance.
     */
    public function unwrap(): Ledger
    {
        return $this->ledger;
    }

    private function dispatch(LedgerEvent $event): void
    {
        ($this->dispatcher)($event);
    }
}
