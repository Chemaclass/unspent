<?php

declare(strict_types=1);

namespace Chemaclass\Unspent;

use Chemaclass\Unspent\Exception\AuthorizationException;
use Chemaclass\Unspent\Exception\DuplicateOutputIdException;
use Chemaclass\Unspent\Exception\DuplicateTxException;
use Chemaclass\Unspent\Exception\InsufficientSpendsException;
use Chemaclass\Unspent\Exception\OutputAlreadySpentException;
use Chemaclass\Unspent\Persistence\HistoryRepository;

/**
 * Core interface for the UTXO ledger.
 *
 * Use Ledger::inMemory() for development/testing (InMemoryHistoryRepository)
 * or Ledger::withRepository() for production (SqliteHistoryRepository, etc.).
 */
interface LedgerInterface
{
    /**
     * Apply a transaction to the ledger.
     *
     * The transaction must spend existing unspent outputs and create new outputs.
     * Any difference between input and output amounts becomes a fee.
     *
     * @throws DuplicateTxException        If the transaction ID was already used
     * @throws OutputAlreadySpentException If any spend references an output not in the unspent set
     * @throws InsufficientSpendsException If the total output amount exceeds the total spend amount
     * @throws DuplicateOutputIdException  If any new output ID already exists in the unspent set
     * @throws AuthorizationException      If authorization fails for any spent output
     *
     * @return static New ledger with the transaction applied
     */
    public function apply(Tx $tx): static;

    /**
     * Apply a coinbase (minting) transaction to the ledger.
     *
     * Coinbase transactions create new outputs without spending any inputs,
     * effectively minting new value.
     *
     * @throws DuplicateTxException       If the transaction ID was already used
     * @throws DuplicateOutputIdException If any output ID already exists in the unspent set
     *
     * @return static New ledger with the coinbase applied
     */
    public function applyCoinbase(CoinbaseTx $coinbase): static;

    /**
     * Returns the set of unspent outputs.
     */
    public function unspent(): UnspentSet;

    /**
     * Returns the total amount across all unspent outputs.
     */
    public function totalUnspentAmount(): int;

    /**
     * Returns all unspent outputs owned by a specific owner.
     *
     * For large datasets with SQLite, prefer QueryableLedgerRepository::findUnspentByOwner()
     * for O(1) memory usage.
     */
    public function unspentByOwner(string $owner): UnspentSet;

    /**
     * Returns total unspent amount for a specific owner.
     *
     * For large datasets with SQLite, prefer QueryableLedgerRepository::sumUnspentByOwner()
     * for O(1) memory usage.
     */
    public function totalUnspentByOwner(string $owner): int;

    /**
     * Checks if a transaction can be applied without actually applying it.
     *
     * Returns null if the transaction is valid, or the exception that would be thrown.
     * Useful for validation before committing to apply.
     */
    public function canApply(Tx $tx): ?Exception\UnspentException;

    /**
     * Returns the total fees collected across all applied transactions.
     */
    public function totalFeesCollected(): int;

    /**
     * Returns total amount minted via coinbase transactions.
     */
    public function totalMinted(): int;

    /**
     * Returns true if a transaction with the given ID has been applied.
     */
    public function isTxApplied(TxId $txId): bool;

    /**
     * Returns the fee paid for a specific transaction.
     *
     * @return int|null Fee amount, or null if tx not found
     */
    public function feeForTx(TxId $txId): ?int;

    /**
     * Returns all transaction IDs with their associated fees.
     *
     * @return array<string, int> Map of TxId value to fee
     */
    public function allTxFees(): array;

    /**
     * Returns true if the given ID was a coinbase transaction.
     */
    public function isCoinbase(TxId $id): bool;

    /**
     * Returns the minted amount for a coinbase transaction.
     *
     * @return int|null Minted amount, or null if not a coinbase
     */
    public function coinbaseAmount(TxId $id): ?int;

    /**
     * Returns which transaction created this output.
     *
     * @return string|null 'genesis' for genesis outputs, tx ID for others, null if unknown
     */
    public function outputCreatedBy(OutputId $id): ?string;

    /**
     * Returns which transaction spent this output.
     *
     * @return string|null Tx ID if spent, null if unspent or unknown
     */
    public function outputSpentBy(OutputId $id): ?string;

    /**
     * Returns the output data, even if the output has been spent.
     *
     * @return Output|null The output, or null if it never existed
     */
    public function getOutput(OutputId $id): ?Output;

    /**
     * Returns true if the output ever existed (spent or unspent).
     */
    public function outputExists(OutputId $id): bool;

    /**
     * Returns complete history of an output.
     */
    public function outputHistory(OutputId $id): ?OutputHistory;

    /**
     * Returns the HistoryRepository.
     */
    public function historyRepository(): HistoryRepository;

    /**
     * Serializes the ledger to an array format suitable for persistence.
     *
     * @return array{
     *     version: int,
     *     unspent: array<string, array{amount: int, lock: array<string, mixed>}>,
     *     appliedTxs: list<string>,
     *     txFees: array<string, int>,
     *     coinbaseAmounts: array<string, int>,
     *     outputCreatedBy: array<string, string>,
     *     outputSpentBy: array<string, string>,
     *     spentOutputs: array<string, array{amount: int, lock: array<string, mixed>}>
     * }
     */
    public function toArray(): array;

    /**
     * Serializes the ledger to a JSON string.
     */
    public function toJson(int $flags = 0): string;
}
