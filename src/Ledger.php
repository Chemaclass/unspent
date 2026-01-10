<?php

declare(strict_types=1);

namespace Chemaclass\Unspent;

/**
 * Core interface for the UTXO ledger.
 *
 * Implementations:
 * - InMemoryLedger: Simple implementation, everything in memory. Best for development,
 *   testing, and applications with <100k total outputs.
 * - ScalableLedger: Production implementation with HistoryStore. Best for 100k+ outputs,
 *   long transaction history, and memory-constrained environments.
 */
interface Ledger
{
    /**
     * Apply a transaction to the ledger.
     *
     * The transaction must spend existing unspent outputs and create new outputs.
     * Any difference between input and output amounts becomes a fee.
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
