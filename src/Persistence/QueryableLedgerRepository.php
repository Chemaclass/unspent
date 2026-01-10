<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Persistence;

use Chemaclass\Unspent\Output;

/**
 * Extended repository interface with query capabilities.
 *
 * Provides efficient database-level queries without loading the entire ledger.
 * Returns structured DTOs instead of arrays for type safety.
 */
interface QueryableLedgerRepository extends LedgerRepository
{
    /**
     * Find all unspent outputs owned by a specific owner.
     *
     * @return list<Output>
     */
    public function findUnspentByOwner(string $ledgerId, string $owner): array;

    /**
     * Find unspent outputs within an amount range.
     *
     * @param int      $min Minimum amount (inclusive)
     * @param int|null $max Maximum amount (inclusive), null for no upper limit
     *
     * @return list<Output>
     */
    public function findUnspentByAmountRange(string $ledgerId, int $min, ?int $max = null): array;

    /**
     * Find unspent outputs by lock type.
     *
     * @param string $lockType Lock type: 'owner', 'pubkey', 'none', or custom types
     *
     * @return list<Output>
     */
    public function findUnspentByLockType(string $ledgerId, string $lockType): array;

    /**
     * Find all outputs created by a specific transaction.
     *
     * @return list<Output>
     */
    public function findOutputsCreatedBy(string $ledgerId, string $txId): array;

    /**
     * Count unspent outputs in a ledger.
     */
    public function countUnspent(string $ledgerId): int;

    /**
     * Sum all unspent amounts for a specific owner.
     */
    public function sumUnspentByOwner(string $ledgerId, string $owner): int;

    /**
     * Find all coinbase transaction IDs.
     *
     * @return list<string>
     */
    public function findCoinbaseTransactions(string $ledgerId): array;

    /**
     * Find transactions by fee range.
     *
     * @param int      $min Minimum fee (inclusive)
     * @param int|null $max Maximum fee (inclusive), null for no upper limit
     *
     * @return list<TransactionInfo>
     */
    public function findTransactionsByFeeRange(string $ledgerId, int $min, ?int $max = null): array;
}
