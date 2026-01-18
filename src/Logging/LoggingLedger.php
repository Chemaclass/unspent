<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Logging;

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
use Psr\Log\LoggerInterface;

/**
 * Logging decorator for Ledger that provides PSR-3 logging.
 *
 * Wraps a Ledger instance and logs all state-changing operations.
 *
 * Usage:
 *     $ledger = LoggingLedger::wrap(Ledger::inMemory(), $psrLogger);
 *     $ledger->credit('alice', 100); // Logs: "Coinbase applied: {txId} minted 100"
 */
final readonly class LoggingLedger implements LedgerInterface
{
    private function __construct(
        private Ledger $ledger,
        private LoggerInterface $logger,
    ) {
    }

    public static function wrap(Ledger $ledger, LoggerInterface $logger): self
    {
        return new self($ledger, $logger);
    }

    public function apply(Tx $tx): static
    {
        $this->logger->info('Applying transaction', [
            'tx_id' => $tx->id->value,
            'spends_count' => \count($tx->spends),
            'outputs_count' => \count($tx->outputs),
            'signed_by' => $tx->signedBy,
        ]);

        $feesBefore = $this->ledger->totalFeesCollected();

        try {
            $this->ledger->apply($tx);

            $fee = $this->ledger->totalFeesCollected() - $feesBefore;

            $this->logger->info('Transaction applied', [
                'tx_id' => $tx->id->value,
                'fee' => $fee,
                'new_unspent_count' => $this->ledger->unspent()->count(),
            ]);

            return $this;
        } catch (UnspentException $e) {
            $this->logger->warning('Transaction failed', [
                'tx_id' => $tx->id->value,
                'error' => $e->getMessage(),
                'exception_type' => $e::class,
            ]);
            throw $e;
        }
    }

    public function applyCoinbase(CoinbaseTx $coinbase): static
    {
        $mintedAmount = $coinbase->totalOutputAmount();

        $this->logger->info('Applying coinbase transaction', [
            'tx_id' => $coinbase->id->value,
            'minted_amount' => $mintedAmount,
            'outputs_count' => \count($coinbase->outputs),
        ]);

        try {
            $this->ledger->applyCoinbase($coinbase);

            $this->logger->info('Coinbase applied', [
                'tx_id' => $coinbase->id->value,
                'minted' => $mintedAmount,
                'total_minted' => $this->ledger->totalMinted(),
            ]);

            return $this;
        } catch (UnspentException $e) {
            $this->logger->warning('Coinbase failed', [
                'tx_id' => $coinbase->id->value,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function transfer(string $from, string $to, int $amount, int $fee = 0, ?string $txId = null): static
    {
        $this->logger->info('Transferring', [
            'from' => $from,
            'to' => $to,
            'amount' => $amount,
            'fee' => $fee,
        ]);

        try {
            $this->ledger->transfer($from, $to, $amount, $fee, $txId);

            $this->logger->info('Transfer completed', [
                'from' => $from,
                'to' => $to,
                'amount' => $amount,
                'fee' => $fee,
            ]);

            return $this;
        } catch (UnspentException $e) {
            $this->logger->warning('Transfer failed', [
                'from' => $from,
                'to' => $to,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function debit(string $owner, int $amount, int $fee = 0, ?string $txId = null): static
    {
        $this->logger->info('Debiting', [
            'owner' => $owner,
            'amount' => $amount,
            'fee' => $fee,
        ]);

        try {
            $this->ledger->debit($owner, $amount, $fee, $txId);

            $this->logger->info('Debit completed', [
                'owner' => $owner,
                'amount' => $amount,
                'fee' => $fee,
            ]);

            return $this;
        } catch (UnspentException $e) {
            $this->logger->warning('Debit failed', [
                'owner' => $owner,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function credit(string $owner, int $amount, ?string $txId = null): static
    {
        $this->logger->info('Crediting', [
            'owner' => $owner,
            'amount' => $amount,
        ]);

        try {
            $this->ledger->credit($owner, $amount, $txId);

            $this->logger->info('Credit completed', [
                'owner' => $owner,
                'amount' => $amount,
                'total_minted' => $this->ledger->totalMinted(),
            ]);

            return $this;
        } catch (UnspentException $e) {
            $this->logger->warning('Credit failed', [
                'owner' => $owner,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    // Read-only methods delegate directly without logging

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
}
