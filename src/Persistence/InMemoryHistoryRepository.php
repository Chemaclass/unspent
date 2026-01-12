<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Persistence;

use Chemaclass\Unspent\CoinbaseTx;
use Chemaclass\Unspent\Lock\LockFactory;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputHistory;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\OutputStatus;
use Chemaclass\Unspent\Tx;
use Chemaclass\Unspent\TxId;

/**
 * In-memory implementation of HistoryRepository.
 *
 * Stores all transaction history in memory arrays. Ideal for:
 * - Development and testing
 * - Small to medium applications (< 100k outputs)
 * - Cases where persistence is handled separately via JSON serialization
 */
final class InMemoryHistoryRepository implements HistoryRepository
{
    /**
     * @param array<string, int>                                            $txFees          Map of TxId value to fee
     * @param array<string, int>                                            $coinbaseAmounts Map of coinbase TxId to minted amount
     * @param array<string, string>                                         $outputCreatedBy Map of OutputId → TxId|'genesis'
     * @param array<string, string>                                         $outputSpentBy   Map of OutputId → TxId
     * @param array<string, array{amount: int, lock: array<string, mixed>}> $spentOutputs    Spent output data for history queries
     */
    public function __construct(
        private array $txFees = [],
        private array $coinbaseAmounts = [],
        private array $outputCreatedBy = [],
        private array $outputSpentBy = [],
        private array $spentOutputs = [],
    ) {
    }

    // =========================================================================
    // Write Operations
    // =========================================================================

    public function saveTransaction(
        Tx $tx,
        int $fee,
        array $spentOutputData,
    ): void {
        $this->txFees[$tx->id->value] = $fee;

        foreach ($tx->spends as $spendId) {
            $this->outputSpentBy[$spendId->value] = $tx->id->value;
        }

        foreach ($tx->outputs as $output) {
            $this->outputCreatedBy[$output->id->value] = $tx->id->value;
        }

        foreach ($spentOutputData as $outputId => $data) {
            $this->spentOutputs[$outputId] = $data;
        }
    }

    public function saveCoinbase(CoinbaseTx $coinbase): void
    {
        $mintedAmount = $coinbase->totalOutputAmount();
        $this->coinbaseAmounts[$coinbase->id->value] = $mintedAmount;

        foreach ($coinbase->outputs as $output) {
            $this->outputCreatedBy[$output->id->value] = $coinbase->id->value;
        }
    }

    public function saveGenesis(array $outputs): void
    {
        foreach ($outputs as $output) {
            $this->outputCreatedBy[$output->id->value] = 'genesis';
        }
    }

    // =========================================================================
    // Read Operations - Outputs
    // =========================================================================

    public function findSpentOutput(OutputId $id): ?Output
    {
        $spentData = $this->spentOutputs[$id->value] ?? null;
        if ($spentData === null) {
            return null;
        }

        return new Output(
            $id,
            $spentData['amount'],
            LockFactory::fromArray($spentData['lock']),
        );
    }

    public function findOutputHistory(OutputId $id): ?OutputHistory
    {
        $spentData = $this->spentOutputs[$id->value] ?? null;
        if ($spentData === null) {
            return null;
        }

        return new OutputHistory(
            id: $id,
            amount: $spentData['amount'],
            lock: LockFactory::fromArray($spentData['lock']),
            createdBy: $this->outputCreatedBy[$id->value] ?? null,
            spentBy: $this->outputSpentBy[$id->value] ?? null,
            status: OutputStatus::SPENT,
        );
    }

    public function findOutputCreatedBy(OutputId $id): ?string
    {
        return $this->outputCreatedBy[$id->value] ?? null;
    }

    public function findOutputSpentBy(OutputId $id): ?string
    {
        return $this->outputSpentBy[$id->value] ?? null;
    }

    // =========================================================================
    // Read Operations - Transactions
    // =========================================================================

    public function findFeeForTx(TxId $id): ?int
    {
        return $this->txFees[$id->value] ?? null;
    }

    public function findAllTxFees(): array
    {
        return $this->txFees;
    }

    public function isCoinbase(TxId $id): bool
    {
        return isset($this->coinbaseAmounts[$id->value]);
    }

    public function findCoinbaseAmount(TxId $id): ?int
    {
        return $this->coinbaseAmounts[$id->value] ?? null;
    }

    // =========================================================================
    // Serialization
    // =========================================================================

    /**
     * Serializes the repository data to an array.
     *
     * @return array{
     *     txFees: array<string, int>,
     *     coinbaseAmounts: array<string, int>,
     *     outputCreatedBy: array<string, string>,
     *     outputSpentBy: array<string, string>,
     *     spentOutputs: array<string, array{amount: int, lock: array<string, mixed>}>
     * }
     */
    public function toArray(): array
    {
        return [
            'txFees' => $this->txFees,
            'coinbaseAmounts' => $this->coinbaseAmounts,
            'outputCreatedBy' => $this->outputCreatedBy,
            'outputSpentBy' => $this->outputSpentBy,
            'spentOutputs' => $this->spentOutputs,
        ];
    }

    /**
     * Creates an instance from serialized data.
     *
     * @param array{
     *     txFees?: array<string, int>,
     *     coinbaseAmounts?: array<string, int>,
     *     outputCreatedBy?: array<string, string>,
     *     outputSpentBy?: array<string, string>,
     *     spentOutputs?: array<string, array{amount: int, lock: array<string, mixed>}>
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            txFees: $data['txFees'] ?? [],
            coinbaseAmounts: $data['coinbaseAmounts'] ?? [],
            outputCreatedBy: $data['outputCreatedBy'] ?? [],
            outputSpentBy: $data['outputSpentBy'] ?? [],
            spentOutputs: $data['spentOutputs'] ?? [],
        );
    }
}
