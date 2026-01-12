<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Persistence;

use Chemaclass\Unspent\CoinbaseTx;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputHistory;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\Tx;
use Chemaclass\Unspent\TxId;

/**
 * Repository interface for persisting and querying transaction history.
 *
 * Used by Ledger in store-backed mode to delegate history storage to a database,
 * keeping only the unspent set in memory for scalability.
 */
interface HistoryRepository
{
    // =========================================================================
    // Write Operations
    // =========================================================================

    /**
     * Saves a regular transaction and its effects.
     *
     * @param array<string, array{amount: int, lock: array<string, mixed>}> $spentOutputData
     */
    public function saveTransaction(
        Tx $tx,
        int $fee,
        array $spentOutputData,
    ): void;

    /**
     * Saves a coinbase (minting) transaction.
     */
    public function saveCoinbase(CoinbaseTx $coinbase): void;

    /**
     * Saves genesis outputs.
     *
     * @param Output[] $outputs
     */
    public function saveGenesis(array $outputs): void;

    // =========================================================================
    // Read Operations - Outputs
    // =========================================================================

    /**
     * Finds output data for a spent output.
     *
     * @return Output|null The output if found, null otherwise
     */
    public function findSpentOutput(OutputId $id): ?Output;

    /**
     * Finds complete history of an output.
     *
     * @return OutputHistory|null The output history if found, null otherwise
     */
    public function findOutputHistory(OutputId $id): ?OutputHistory;

    /**
     * Finds which transaction created this output.
     *
     * @return string|null 'genesis' for genesis outputs, tx ID for others, null if unknown
     */
    public function findOutputCreatedBy(OutputId $id): ?string;

    /**
     * Finds which transaction spent this output.
     *
     * @return string|null Tx ID if spent, null if unspent or unknown
     */
    public function findOutputSpentBy(OutputId $id): ?string;

    // =========================================================================
    // Read Operations - Transactions
    // =========================================================================

    /**
     * Finds the fee paid for a specific transaction.
     *
     * @return int|null Fee amount, or null if tx not found
     */
    public function findFeeForTx(TxId $id): ?int;

    /**
     * Finds all transaction IDs with their associated fees.
     *
     * @return array<string, int> Map of TxId value to fee
     */
    public function findAllTxFees(): array;

    /**
     * Checks if the given ID was a coinbase transaction.
     */
    public function isCoinbase(TxId $id): bool;

    /**
     * Finds the minted amount for a coinbase transaction.
     *
     * @return int|null Minted amount, or null if not a coinbase
     */
    public function findCoinbaseAmount(TxId $id): ?int;
}
