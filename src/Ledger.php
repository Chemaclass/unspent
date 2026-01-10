<?php

declare(strict_types=1);

namespace Chemaclass\Unspent;

use Chemaclass\Unspent\Exception\DuplicateOutputIdException;
use Chemaclass\Unspent\Exception\DuplicateTxException;
use Chemaclass\Unspent\Exception\GenesisNotAllowedException;
use Chemaclass\Unspent\Exception\InsufficientSpendsException;
use Chemaclass\Unspent\Exception\OutputAlreadySpentException;
use Chemaclass\Unspent\Lock\LockFactory;
use Chemaclass\Unspent\Validation\DuplicateValidator;
use JsonException;

final readonly class Ledger
{
    /** Serialization format version for future migration support. */
    private const int SERIALIZATION_VERSION = 1;

    /**
     * @param array<string, true>                                                       $appliedTxIds
     * @param array<string, int>                                                        $txFees          Map of TxId value to fee amount
     * @param array<string, int>                                                        $coinbaseAmounts Map of coinbase TxId to minted amount
     * @param array<string, string>                                                     $outputCreatedBy Map of OutputId → TxId|'genesis'
     * @param array<string, string>                                                     $outputSpentBy   Map of OutputId → TxId
     * @param array<string, array{id: string, amount: int, lock: array<string, mixed>}> $spentOutputs
     */
    private function __construct(
        private UnspentSet $unspentSet,
        private array $appliedTxIds,
        private array $txFees = [],
        private int $totalFees = 0,
        private array $coinbaseAmounts = [],
        private int $totalMinted = 0,
        private array $outputCreatedBy = [],
        private array $outputSpentBy = [],
        private array $spentOutputs = [],
    ) {
    }

    public static function empty(): self
    {
        return new self(UnspentSet::empty(), [], [], 0, [], 0, [], [], []);
    }

    public static function withGenesis(Output ...$outputs): self
    {
        return self::empty()->addGenesis(...$outputs);
    }

    public function addGenesis(Output ...$outputs): self
    {
        if (!$this->unspentSet->isEmpty()) {
            throw GenesisNotAllowedException::ledgerNotEmpty();
        }

        DuplicateValidator::assertNoDuplicateOutputIds(array_values($outputs));

        // Track provenance for genesis outputs
        $outputCreatedBy = $this->outputCreatedBy;
        foreach ($outputs as $output) {
            $outputCreatedBy[$output->id->value] = 'genesis';
        }

        return new self(
            UnspentSet::fromOutputs(...$outputs),
            $this->appliedTxIds,
            $this->txFees,
            $this->totalFees,
            $this->coinbaseAmounts,
            $this->totalMinted,
            $outputCreatedBy,
            $this->outputSpentBy,
            $this->spentOutputs,
        );
    }

    public function apply(Tx $tx): self
    {
        $this->assertTxNotAlreadyApplied($tx);
        $spendAmount = $this->validateSpendsAndGetTotal($tx);
        $outputAmount = $tx->totalOutputAmount();
        $this->assertSufficientSpends($spendAmount, $outputAmount);
        $this->assertNoOutputIdConflicts($tx);

        // Calculate implicit fee (Bitcoin-style: spends - outputs)
        $fee = $spendAmount - $outputAmount;

        // Track spent outputs before removing them
        $spentOutputs = $this->spentOutputs;
        $outputSpentBy = $this->outputSpentBy;
        foreach ($tx->spends as $spendId) {
            $output = $this->unspentSet->get($spendId);
            if ($output !== null) {
                $spentOutputs[$spendId->value] = [
                    'id' => $output->id->value,
                    'amount' => $output->amount,
                    'lock' => $output->lock->toArray(),
                ];
                $outputSpentBy[$spendId->value] = $tx->id->value;
            }
        }

        // Track provenance for new outputs
        $outputCreatedBy = $this->outputCreatedBy;
        foreach ($tx->outputs as $output) {
            $outputCreatedBy[$output->id->value] = $tx->id->value;
        }

        $unspent = $this->unspentSet
            ->removeAll(...$tx->spends)
            ->addAll(...$tx->outputs);

        $appliedTxs = $this->appliedTxIds;
        $appliedTxs[$tx->id->value] = true;

        $txFees = $this->txFees;
        $txFees[$tx->id->value] = $fee;

        return new self(
            $unspent,
            $appliedTxs,
            $txFees,
            $this->totalFees + $fee,
            $this->coinbaseAmounts,
            $this->totalMinted,
            $outputCreatedBy,
            $outputSpentBy,
            $spentOutputs,
        );
    }

    public function applyCoinbase(CoinbaseTx $coinbase): self
    {
        $this->assertTxIdNotAlreadyUsed($coinbase->id);
        $this->assertNoOutputIdConflictsForCoinbase($coinbase);

        // Track provenance for coinbase outputs
        $outputCreatedBy = $this->outputCreatedBy;
        foreach ($coinbase->outputs as $output) {
            $outputCreatedBy[$output->id->value] = $coinbase->id->value;
        }

        $unspent = $this->unspentSet->addAll(...$coinbase->outputs);

        $appliedTxs = $this->appliedTxIds;
        $appliedTxs[$coinbase->id->value] = true;

        $coinbaseAmounts = $this->coinbaseAmounts;
        $coinbaseAmounts[$coinbase->id->value] = $coinbase->totalOutputAmount();

        return new self(
            $unspent,
            $appliedTxs,
            $this->txFees,
            $this->totalFees,
            $coinbaseAmounts,
            $this->totalMinted + $coinbase->totalOutputAmount(),
            $outputCreatedBy,
            $this->outputSpentBy,
            $this->spentOutputs,
        );
    }

    public function unspent(): UnspentSet
    {
        return $this->unspentSet;
    }

    public function totalUnspentAmount(): int
    {
        return $this->unspentSet->totalAmount();
    }

    public function isTxApplied(TxId $txId): bool
    {
        return isset($this->appliedTxIds[$txId->value]);
    }

    /**
     * Returns the total fees collected across all applied transactions.
     */
    public function totalFeesCollected(): int
    {
        return $this->totalFees;
    }

    /**
     * Returns the fee paid for a specific transaction.
     *
     * @return int|null Fee amount, or null if tx not found
     */
    public function feeForTx(TxId $txId): ?int
    {
        return $this->txFees[$txId->value] ?? null;
    }

    /**
     * Returns all transaction IDs with their associated fees.
     *
     * @return array<string, int> Map of TxId value to fee
     */
    public function allTxFees(): array
    {
        return $this->txFees;
    }

    /**
     * Returns total amount minted via coinbase transactions.
     */
    public function totalMinted(): int
    {
        return $this->totalMinted;
    }

    /**
     * Returns true if the given ID was a coinbase transaction.
     */
    public function isCoinbase(TxId $id): bool
    {
        return isset($this->coinbaseAmounts[$id->value]);
    }

    /**
     * Returns the minted amount for a coinbase transaction.
     *
     * @return int|null Minted amount, or null if not a coinbase
     */
    public function coinbaseAmount(TxId $id): ?int
    {
        return $this->coinbaseAmounts[$id->value] ?? null;
    }

    // ========================================================================
    // History & Provenance
    // ========================================================================

    /**
     * Returns which transaction created this output.
     *
     * @return string|null 'genesis' for genesis outputs, tx ID for others, null if unknown
     */
    public function outputCreatedBy(OutputId $id): ?string
    {
        return $this->outputCreatedBy[$id->value] ?? null;
    }

    /**
     * Returns which transaction spent this output.
     *
     * @return string|null Tx ID if spent, null if unspent or unknown
     */
    public function outputSpentBy(OutputId $id): ?string
    {
        return $this->outputSpentBy[$id->value] ?? null;
    }

    /**
     * Returns the output data, even if the output has been spent.
     *
     * @return Output|null The output, or null if it never existed
     */
    public function getOutput(OutputId $id): ?Output
    {
        // First check unspent outputs
        $output = $this->unspentSet->get($id);
        if ($output !== null) {
            return $output;
        }

        // Check spent outputs
        $spentData = $this->spentOutputs[$id->value] ?? null;
        if ($spentData !== null) {
            return new Output(
                new OutputId($spentData['id']),
                $spentData['amount'],
                LockFactory::fromArray($spentData['lock']),
            );
        }

        return null;
    }

    /**
     * Returns true if the output ever existed (spent or unspent).
     */
    public function outputExists(OutputId $id): bool
    {
        return $this->unspentSet->contains($id)
            || isset($this->spentOutputs[$id->value]);
    }

    /**
     * Returns complete history of an output.
     */
    public function outputHistory(OutputId $id): ?OutputHistory
    {
        $output = $this->getOutput($id);
        if ($output === null) {
            return null;
        }

        return OutputHistory::fromOutput(
            output: $output,
            createdBy: $this->outputCreatedBy[$id->value] ?? null,
            spentBy: $this->outputSpentBy[$id->value] ?? null,
        );
    }

    // ========================================================================
    // Serialization
    // ========================================================================

    /**
     * Serializes the ledger to an array format suitable for persistence.
     *
     * @return array{
     *     version: int,
     *     unspent: list<array{id: string, amount: int, lock: array<string, mixed>}>,
     *     appliedTxs: list<string>,
     *     txFees: array<string, int>,
     *     coinbaseAmounts: array<string, int>,
     *     outputCreatedBy: array<string, string>,
     *     outputSpentBy: array<string, string>,
     *     spentOutputs: array<string, array{id: string, amount: int, lock: array<string, mixed>}>
     * }
     */
    public function toArray(): array
    {
        return [
            'version' => self::SERIALIZATION_VERSION,
            'unspent' => $this->unspentSet->toArray(),
            'appliedTxs' => array_keys($this->appliedTxIds),
            'txFees' => $this->txFees,
            'coinbaseAmounts' => $this->coinbaseAmounts,
            'outputCreatedBy' => $this->outputCreatedBy,
            'outputSpentBy' => $this->outputSpentBy,
            'spentOutputs' => $this->spentOutputs,
        ];
    }

    /**
     * Creates a Ledger from a serialized array.
     *
     * @param array{
     *     version: int,
     *     unspent: list<array{id: string, amount: int, lock: array<string, mixed>}>,
     *     appliedTxs: list<string>,
     *     txFees: array<string, int>,
     *     coinbaseAmounts: array<string, int>,
     *     outputCreatedBy?: array<string, string>,
     *     outputSpentBy?: array<string, string>,
     *     spentOutputs?: array<string, array{id: string, amount: int, lock: array<string, mixed>}>
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $appliedTxIds = array_fill_keys($data['appliedTxs'], true);
        $totalFees = array_sum($data['txFees']);
        $totalMinted = array_sum($data['coinbaseAmounts']);

        return new self(
            unspentSet: UnspentSet::fromArray($data['unspent']),
            appliedTxIds: $appliedTxIds,
            txFees: $data['txFees'],
            totalFees: $totalFees,
            coinbaseAmounts: $data['coinbaseAmounts'],
            totalMinted: $totalMinted,
            outputCreatedBy: $data['outputCreatedBy'] ?? [],
            outputSpentBy: $data['outputSpentBy'] ?? [],
            spentOutputs: $data['spentOutputs'] ?? [],
        );
    }

    /**
     * Serializes the ledger to a JSON string.
     *
     * @throws JsonException If encoding fails
     */
    public function toJson(int $flags = 0): string
    {
        return json_encode($this->toArray(), $flags | JSON_THROW_ON_ERROR);
    }

    /**
     * Creates a Ledger from a JSON string.
     *
     * @throws JsonException If decoding fails
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return self::fromArray($data);
    }

    // ========================================================================
    // Private Helpers
    // ========================================================================

    private function assertTxNotAlreadyApplied(Tx $tx): void
    {
        $this->assertTxIdNotAlreadyUsed($tx->id);
    }

    private function assertTxIdNotAlreadyUsed(TxId $id): void
    {
        if (isset($this->appliedTxIds[$id->value])) {
            throw DuplicateTxException::forId($id->value);
        }
    }

    /**
     * Validates all spends exist in unspent set, checks authorization, and returns total spend amount.
     */
    private function validateSpendsAndGetTotal(Tx $tx): int
    {
        $spendAmount = 0;
        $spendIndex = 0;

        foreach ($tx->spends as $spendId) {
            $output = $this->unspentSet->get($spendId);
            if ($output === null) {
                throw OutputAlreadySpentException::forId($spendId->value);
            }

            $output->lock->validate($tx, $spendIndex);

            $spendAmount += $output->amount;
            ++$spendIndex;
        }

        return $spendAmount;
    }

    private function assertSufficientSpends(int $spendAmount, int $outputAmount): void
    {
        if ($spendAmount < $outputAmount) {
            throw InsufficientSpendsException::create($spendAmount, $outputAmount);
        }
    }

    private function assertNoOutputIdConflicts(Tx $tx): void
    {
        // Build set of spend IDs (outputs being spent) (O(n))
        $spendIds = [];
        foreach ($tx->spends as $spendId) {
            $spendIds[$spendId->value] = true;
        }

        // Check each output ID (O(m))
        foreach ($tx->outputs as $output) {
            $id = $output->id->value;

            // Skip IDs that are being spent (they will be removed)
            if (isset($spendIds[$id])) {
                continue;
            }

            // Check for conflict with existing unspent outputs
            if ($this->unspentSet->contains($output->id)) {
                throw DuplicateOutputIdException::forId($id);
            }
        }
    }

    private function assertNoOutputIdConflictsForCoinbase(CoinbaseTx $coinbase): void
    {
        foreach ($coinbase->outputs as $output) {
            if ($this->unspentSet->contains($output->id)) {
                throw DuplicateOutputIdException::forId($output->id->value);
            }
        }
    }
}
