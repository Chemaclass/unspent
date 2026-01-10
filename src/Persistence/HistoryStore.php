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
 * Interface for querying and recording transaction history.
 *
 * Used by Ledger in hybrid mode to delegate history storage to a database,
 * keeping only the unspent set in memory for scalability.
 */
interface HistoryStore
{
    /**
     * Returns complete history of an output.
     */
    public function outputHistory(OutputId $id): ?OutputHistory;

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
     * Returns the output data for a spent output.
     */
    public function getSpentOutput(OutputId $id): ?Output;

    /**
     * Returns the fee paid for a specific transaction.
     */
    public function feeForTx(TxId $id): ?int;

    /**
     * Returns true if the given ID was a coinbase transaction.
     */
    public function isCoinbase(TxId $id): bool;

    /**
     * Returns the minted amount for a coinbase transaction.
     */
    public function coinbaseAmount(TxId $id): ?int;

    /**
     * Records a regular transaction and its effects.
     *
     * @param array<string, array{amount: int, lock: array<string, mixed>}> $spentOutputData
     */
    public function recordTransaction(
        Tx $tx,
        int $fee,
        array $spentOutputData,
    ): void;

    /**
     * Records a coinbase (minting) transaction.
     */
    public function recordCoinbase(CoinbaseTx $coinbase): void;

    /**
     * Records genesis outputs.
     *
     * @param Output[] $outputs
     */
    public function recordGenesis(array $outputs): void;
}
