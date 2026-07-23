<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Event;

use Chemaclass\Unspent\CoinbaseTx;
use Chemaclass\Unspent\DelegatesLedgerReads;
use Chemaclass\Unspent\Exception\UnspentException;
use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\LedgerInterface;
use Chemaclass\Unspent\Tx;
use Closure;

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
    use DelegatesLedgerReads;

    /**
     * @param Closure(LedgerEvent): void $dispatcher
     */
    private function __construct(private Ledger $ledger, private Closure $dispatcher)
    {
    }

    /**
     * @param Closure(LedgerEvent): void $dispatcher
     */
    public static function wrap(Ledger $ledger, Closure $dispatcher): self
    {
        return new self($ledger, $dispatcher);
    }

    public function apply(Tx $tx): static
    {
        // Spent outputs are removed from the unspent set by apply(), so their
        // amounts must be captured now to build OutputSpent events afterward.
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

            $this->dispatch(new TransactionApplied(
                transaction: $tx,
                fee: $fee,
                inputTotal: $inputTotal,
                outputTotal: $outputTotal,
            ));

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

        $this->dispatch(new CoinbaseApplied(
            coinbase: $coinbase,
            mintedAmount: $mintedAmount,
            totalMinted: $this->ledger->totalMinted(),
        ));

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

    public function consolidate(string $owner, int $fee = 0, ?string $txId = null): static
    {
        $this->ledger->consolidate($owner, $fee, $txId);

        return $this;
    }

    public function batchTransfer(string $from, array $recipients, int $fee = 0, ?string $txId = null): static
    {
        $this->ledger->batchTransfer($from, $recipients, $fee, $txId);

        return $this;
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
