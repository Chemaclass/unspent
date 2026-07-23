<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Logging;

use Chemaclass\Unspent\CoinbaseTx;
use Chemaclass\Unspent\DelegatesLedgerReads;
use Chemaclass\Unspent\Exception\UnspentException;
use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\LedgerInterface;
use Chemaclass\Unspent\Tx;
use InvalidArgumentException;
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
    use DelegatesLedgerReads;

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

    public function consolidate(string $owner, int $fee = 0, ?string $txId = null): static
    {
        $outputCount = $this->ledger->unspentByOwner($owner)->count();
        $this->logger->info('Consolidating', [
            'owner' => $owner,
            'output_count' => $outputCount,
            'fee' => $fee,
        ]);

        try {
            $this->ledger->consolidate($owner, $fee, $txId);

            $this->logger->info('Consolidation completed', [
                'owner' => $owner,
                'new_output_count' => $this->ledger->unspentByOwner($owner)->count(),
                'fee' => $fee,
            ]);

            return $this;
        } catch (UnspentException $e) {
            $this->logger->warning('Consolidation failed', [
                'owner' => $owner,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function batchTransfer(string $from, array $recipients, int $fee = 0, ?string $txId = null): static
    {
        $totalAmount = array_sum($recipients);
        $this->logger->info('Batch transferring', [
            'from' => $from,
            'recipient_count' => \count($recipients),
            'total_amount' => $totalAmount,
            'fee' => $fee,
        ]);

        try {
            $this->ledger->batchTransfer($from, $recipients, $fee, $txId);

            $this->logger->info('Batch transfer completed', [
                'from' => $from,
                'recipient_count' => \count($recipients),
                'total_amount' => $totalAmount,
                'fee' => $fee,
            ]);

            return $this;
        } catch (UnspentException|InvalidArgumentException $e) {
            $this->logger->warning('Batch transfer failed', [
                'from' => $from,
                'recipient_count' => \count($recipients),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Returns the underlying Ledger instance.
     */
    public function unwrap(): Ledger
    {
        return $this->ledger;
    }
}
