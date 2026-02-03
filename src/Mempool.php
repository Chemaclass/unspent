<?php

declare(strict_types=1);

namespace Chemaclass\Unspent;

use Chemaclass\Unspent\Exception\DuplicateTxException;
use Chemaclass\Unspent\Exception\OutputAlreadySpentException;
use Chemaclass\Unspent\Exception\UnspentException;
use InvalidArgumentException;

/**
 * Transaction mempool for staging transactions before committing.
 *
 * Holds pending transactions and validates them against the ledger.
 * Detects double-spends within the mempool before commit.
 *
 * Usage:
 *     $mempool = new Mempool($ledger);
 *     $mempool->add($tx1);
 *     $mempool->add($tx2);
 *     $mempool->commit(); // Apply all to ledger
 *
 * For RBF (Replace-By-Fee):
 *     $mempool->replace($oldTxId, $newTx);
 */
final class Mempool
{
    /** @var array<string, Tx> Pending transactions indexed by ID */
    private array $pending = [];

    /** @var array<string, string> Maps spent output ID to transaction ID */
    private array $spentBy = [];

    /** @var array<string, int> Cached fee for each pending transaction */
    private array $fees = [];

    public function __construct(
        private readonly LedgerInterface $ledger,
    ) {
    }

    /**
     * Add a transaction to the mempool.
     *
     * Validates the transaction against the current ledger state and
     * checks for conflicts with other pending transactions.
     *
     * @throws DuplicateTxException        If transaction ID already in mempool
     * @throws UnspentException            If transaction is invalid against ledger
     * @throws OutputAlreadySpentException If transaction conflicts with pending tx
     *
     * @return string The transaction ID
     */
    public function add(Tx $tx): string
    {
        $txId = $tx->id->value;

        // Check for duplicate
        if (isset($this->pending[$txId])) {
            throw DuplicateTxException::forId($txId);
        }

        // Validate against ledger
        $error = $this->ledger->canApply($tx);
        if ($error !== null) {
            throw $error;
        }

        // Check for double-spend within mempool
        foreach ($tx->spends as $spendId) {
            $spendIdValue = $spendId->value;
            if (isset($this->spentBy[$spendIdValue])) {
                throw new OutputAlreadySpentException(
                    \sprintf(
                        "Output '%s' conflicts with pending transaction %s",
                        $spendIdValue,
                        $this->spentBy[$spendIdValue],
                    ),
                    OutputAlreadySpentException::CODE,
                );
            }
        }

        // Calculate and cache fee
        $inputTotal = $this->calculateInputTotal($tx);
        $outputTotal = $tx->totalOutputAmount();
        $this->fees[$txId] = $inputTotal - $outputTotal;

        // Add to mempool
        $this->pending[$txId] = $tx;

        // Track spent outputs
        foreach ($tx->spends as $spendId) {
            $this->spentBy[$spendId->value] = $txId;
        }

        return $txId;
    }

    /**
     * Remove a transaction from the mempool.
     *
     * Does nothing if transaction is not in mempool.
     */
    public function remove(string $txId): void
    {
        if (!isset($this->pending[$txId])) {
            return;
        }

        $tx = $this->pending[$txId];

        // Remove spent output tracking
        foreach ($tx->spends as $spendId) {
            unset($this->spentBy[$spendId->value]);
        }

        unset($this->pending[$txId], $this->fees[$txId]);
    }

    /**
     * Replace a pending transaction (RBF - Replace-By-Fee).
     *
     * Removes the old transaction and adds the new one.
     *
     * @throws InvalidArgumentException If old transaction not in mempool
     * @throws UnspentException         If new transaction is invalid
     */
    public function replace(string $oldTxId, Tx $newTx): void
    {
        if (!isset($this->pending[$oldTxId])) {
            throw new InvalidArgumentException("Transaction {$oldTxId} not in mempool");
        }

        $this->remove($oldTxId);
        $this->add($newTx);
    }

    /**
     * Commit all pending transactions to the ledger.
     *
     * Transactions are applied in the order they were added.
     *
     * @return int Number of transactions committed
     */
    public function commit(): int
    {
        $count = 0;

        foreach ($this->pending as $tx) {
            $this->ledger->apply($tx);
            ++$count;
        }

        $this->clear();

        return $count;
    }

    /**
     * Commit a single transaction from the mempool.
     *
     * @throws InvalidArgumentException If transaction not in mempool
     */
    public function commitOne(string $txId): void
    {
        if (!isset($this->pending[$txId])) {
            throw new InvalidArgumentException("Transaction {$txId} not in mempool");
        }

        $tx = $this->pending[$txId];
        $this->remove($txId);
        $this->ledger->apply($tx);
    }

    /**
     * Clear all pending transactions without committing.
     */
    public function clear(): void
    {
        $this->pending = [];
        $this->spentBy = [];
        $this->fees = [];
    }

    /**
     * Check if a transaction is in the mempool.
     */
    public function has(string $txId): bool
    {
        return isset($this->pending[$txId]);
    }

    /**
     * Get a transaction from the mempool.
     */
    public function get(string $txId): ?Tx
    {
        return $this->pending[$txId] ?? null;
    }

    /**
     * Get all pending transactions.
     *
     * @return array<string, Tx>
     */
    public function all(): array
    {
        return $this->pending;
    }

    /**
     * Get the number of pending transactions.
     */
    public function count(): int
    {
        return \count($this->pending);
    }

    /**
     * Get total fees from all pending transactions.
     */
    public function totalPendingFees(): int
    {
        return array_sum($this->fees);
    }

    /**
     * Get the fee for a specific pending transaction.
     */
    public function feeFor(string $txId): ?int
    {
        return $this->fees[$txId] ?? null;
    }

    /**
     * Calculate the total input amount for a transaction.
     */
    private function calculateInputTotal(Tx $tx): int
    {
        $total = 0;

        foreach ($tx->spends as $spendId) {
            $output = $this->ledger->unspent()->get($spendId);
            if ($output !== null) {
                $total += $output->amount;
            }
        }

        return $total;
    }
}
