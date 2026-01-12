<?php

declare(strict_types=1);

namespace Chemaclass\Unspent;

use Chemaclass\Unspent\Exception\DuplicateOutputIdException;
use Chemaclass\Unspent\Exception\DuplicateTxException;
use Chemaclass\Unspent\Exception\GenesisNotAllowedException;
use Chemaclass\Unspent\Exception\InsufficientSpendsException;
use Chemaclass\Unspent\Exception\OutputAlreadySpentException;
use Chemaclass\Unspent\Lock\LockFactory;
use Chemaclass\Unspent\Persistence\HistoryStore;
use Chemaclass\Unspent\Validation\DuplicateValidator;
use JsonException;

/**
 * Operates in two modes:
 * - In-memory mode (historyStore = null): Stores all history in memory. Best for dev/testing.
 * - Store-backed mode (historyStore != null): Delegates history to a HistoryStore. Best for production.
 *
 * Memory usage in-memory mode:
 * - 10k outputs: ~10 MB
 * - 100k outputs: ~100 MB
 * - 1M outputs: ~700 MB
 *
 * Memory usage in store-backed mode (bounded by unspent count only):
 * - 1M total outputs with 100k unspent: ~100 MB
 * - 10M total outputs with 100k unspent: ~100 MB
 */
final readonly class Ledger implements LedgerInterface
{
    /** Serialization format version for future migration support. */
    private const int SERIALIZATION_VERSION = 1;

    /**
     * @param array<string, true>                                           $appliedTxIds
     * @param array<string, int>                                            $txFees          Map of TxId value to fee (in-memory mode only)
     * @param array<string, int>                                            $coinbaseAmounts Map of coinbase TxId to minted amount (in-memory mode only)
     * @param array<string, string>                                         $outputCreatedBy Map of OutputId → TxId|'genesis' (in-memory mode only)
     * @param array<string, string>                                         $outputSpentBy   Map of OutputId → TxId (in-memory mode only)
     * @param array<string, array{amount: int, lock: array<string, mixed>}> $spentOutputs    (in-memory mode only)
     */
    private function __construct(
        private UnspentSet $unspentSet,
        private array $appliedTxIds,
        private int $totalFees,
        private int $totalMinted,
        private ?HistoryStore $historyStore,
        private array $txFees = [],
        private array $coinbaseAmounts = [],
        private array $outputCreatedBy = [],
        private array $outputSpentBy = [],
        private array $spentOutputs = [],
    ) {
    }

    // ========================================================================
    // Factory Methods - In-Memory Mode
    // ========================================================================

    /**
     * Creates an empty in-memory ledger.
     */
    public static function inMemory(): self
    {
        return new self(
            unspentSet: UnspentSet::empty(),
            appliedTxIds: [],
            totalFees: 0,
            totalMinted: 0,
            historyStore: null,
        );
    }

    /**
     * Creates an in-memory ledger with genesis outputs.
     */
    public static function withGenesis(Output ...$outputs): self
    {
        return self::inMemory()->addGenesis(...$outputs);
    }

    /**
     * Creates a Ledger from a serialized array (in-memory mode).
     *
     * @param array{
     *     version: int,
     *     unspent: array<string, array{amount: int, lock: array<string, mixed>}>,
     *     appliedTxs: list<string>,
     *     txFees: array<string, int>,
     *     coinbaseAmounts: array<string, int>,
     *     outputCreatedBy?: array<string, string>,
     *     outputSpentBy?: array<string, string>,
     *     spentOutputs?: array<string, array{amount: int, lock: array<string, mixed>}>
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
            totalFees: $totalFees,
            totalMinted: $totalMinted,
            historyStore: null,
            txFees: $data['txFees'],
            coinbaseAmounts: $data['coinbaseAmounts'],
            outputCreatedBy: $data['outputCreatedBy'] ?? [],
            outputSpentBy: $data['outputSpentBy'] ?? [],
            spentOutputs: $data['spentOutputs'] ?? [],
        );
    }

    /**
     * Creates a Ledger from a JSON string (in-memory mode).
     *
     * @throws JsonException If decoding fails
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return self::fromArray($data);
    }

    // ========================================================================
    // Factory Methods - Store-Backed Mode
    // ========================================================================

    /**
     * Creates an empty ledger with external history storage.
     */
    public static function withStore(HistoryStore $store): self
    {
        return new self(
            unspentSet: UnspentSet::empty(),
            appliedTxIds: [],
            totalFees: 0,
            totalMinted: 0,
            historyStore: $store,
        );
    }

    /**
     * Creates a ledger from an existing UnspentSet with external history storage.
     *
     * Use this when loading a ledger from persistence.
     */
    public static function fromUnspentSet(
        UnspentSet $unspentSet,
        HistoryStore $store,
        int $totalFees = 0,
        int $totalMinted = 0,
    ): self {
        return new self(
            unspentSet: $unspentSet,
            appliedTxIds: [],
            totalFees: $totalFees,
            totalMinted: $totalMinted,
            historyStore: $store,
        );
    }

    // ========================================================================
    // Public API
    // ========================================================================

    /**
     * Adds genesis outputs to an empty ledger.
     */
    public function addGenesis(Output ...$outputs): self
    {
        if (!$this->unspentSet->isEmpty()) {
            throw GenesisNotAllowedException::ledgerNotEmpty();
        }

        DuplicateValidator::assertNoDuplicateOutputIds(array_values($outputs));

        if ($this->usesInMemoryHistory()) {
            $outputCreatedBy = $this->outputCreatedBy;
            foreach ($outputs as $output) {
                $outputCreatedBy[$output->id->value] = 'genesis';
            }

            return new self(
                UnspentSet::fromOutputs(...$outputs),
                $this->appliedTxIds,
                $this->totalFees,
                $this->totalMinted,
                null,
                $this->txFees,
                $this->coinbaseAmounts,
                $outputCreatedBy,
                $this->outputSpentBy,
                $this->spentOutputs,
            );
        }

        \assert($this->historyStore !== null);
        $this->historyStore->recordGenesis($outputs);

        return new self(
            UnspentSet::fromOutputs(...$outputs),
            $this->appliedTxIds,
            $this->totalFees,
            $this->totalMinted,
            $this->historyStore,
        );
    }

    public function apply(Tx $tx): static
    {
        $this->assertTxNotAlreadyApplied($tx);
        $spendAmount = $this->validateSpendsAndGetTotal($tx);
        $outputAmount = $tx->totalOutputAmount();
        $this->assertSufficientSpends($spendAmount, $outputAmount);
        $this->assertNoOutputIdConflicts($tx);

        $fee = $spendAmount - $outputAmount;

        // Collect spent output data
        $spentOutputData = [];
        foreach ($tx->spends as $spendId) {
            $output = $this->unspentSet->get($spendId);
            if ($output !== null) {
                $spentOutputData[$spendId->value] = [
                    'amount' => $output->amount,
                    'lock' => $output->lock->toArray(),
                ];
            }
        }

        $unspent = $this->unspentSet
            ->removeAll(...$tx->spends)
            ->addAll(...$tx->outputs);

        $appliedTxs = $this->appliedTxIds;
        $appliedTxs[$tx->id->value] = true;

        if ($this->usesInMemoryHistory()) {
            $outputSpentBy = $this->outputSpentBy;
            foreach ($tx->spends as $spendId) {
                $outputSpentBy[$spendId->value] = $tx->id->value;
            }

            $outputCreatedBy = $this->outputCreatedBy;
            foreach ($tx->outputs as $output) {
                $outputCreatedBy[$output->id->value] = $tx->id->value;
            }

            $txFees = $this->txFees;
            $txFees[$tx->id->value] = $fee;

            $spentOutputs = array_merge($this->spentOutputs, $spentOutputData);

            return new self(
                $unspent,
                $appliedTxs,
                $this->totalFees + $fee,
                $this->totalMinted,
                null,
                $txFees,
                $this->coinbaseAmounts,
                $outputCreatedBy,
                $outputSpentBy,
                $spentOutputs,
            );
        }

        \assert($this->historyStore !== null);
        $this->historyStore->recordTransaction($tx, $fee, $spentOutputData);

        return new self(
            $unspent,
            $appliedTxs,
            $this->totalFees + $fee,
            $this->totalMinted,
            $this->historyStore,
        );
    }

    public function applyCoinbase(CoinbaseTx $coinbase): static
    {
        $this->assertTxIdNotAlreadyUsed($coinbase->id);
        $this->assertNoOutputIdConflictsForCoinbase($coinbase);

        $unspent = $this->unspentSet->addAll(...$coinbase->outputs);
        $mintedAmount = $coinbase->totalOutputAmount();

        $appliedTxs = $this->appliedTxIds;
        $appliedTxs[$coinbase->id->value] = true;

        if ($this->usesInMemoryHistory()) {
            $outputCreatedBy = $this->outputCreatedBy;
            foreach ($coinbase->outputs as $output) {
                $outputCreatedBy[$output->id->value] = $coinbase->id->value;
            }

            $coinbaseAmounts = $this->coinbaseAmounts;
            $coinbaseAmounts[$coinbase->id->value] = $mintedAmount;

            return new self(
                $unspent,
                $appliedTxs,
                $this->totalFees,
                $this->totalMinted + $mintedAmount,
                null,
                $this->txFees,
                $coinbaseAmounts,
                $outputCreatedBy,
                $this->outputSpentBy,
                $this->spentOutputs,
            );
        }

        \assert($this->historyStore !== null);
        $this->historyStore->recordCoinbase($coinbase);

        return new self(
            $unspent,
            $appliedTxs,
            $this->totalFees,
            $this->totalMinted + $mintedAmount,
            $this->historyStore,
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

    public function totalFeesCollected(): int
    {
        return $this->totalFees;
    }

    public function feeForTx(TxId $txId): ?int
    {
        if ($this->usesInMemoryHistory()) {
            return $this->txFees[$txId->value] ?? null;
        }

        \assert($this->historyStore !== null);

        return $this->historyStore->feeForTx($txId);
    }

    public function allTxFees(): array
    {
        if ($this->usesInMemoryHistory()) {
            return $this->txFees;
        }

        \assert($this->historyStore !== null);

        return $this->historyStore->allTxFees();
    }

    public function totalMinted(): int
    {
        return $this->totalMinted;
    }

    public function isCoinbase(TxId $id): bool
    {
        if ($this->usesInMemoryHistory()) {
            return isset($this->coinbaseAmounts[$id->value]);
        }

        \assert($this->historyStore !== null);

        return $this->historyStore->isCoinbase($id);
    }

    public function coinbaseAmount(TxId $id): ?int
    {
        if ($this->usesInMemoryHistory()) {
            return $this->coinbaseAmounts[$id->value] ?? null;
        }

        \assert($this->historyStore !== null);

        return $this->historyStore->coinbaseAmount($id);
    }

    public function outputCreatedBy(OutputId $id): ?string
    {
        if ($this->usesInMemoryHistory()) {
            return $this->outputCreatedBy[$id->value] ?? null;
        }

        \assert($this->historyStore !== null);

        return $this->historyStore->outputCreatedBy($id);
    }

    public function outputSpentBy(OutputId $id): ?string
    {
        if ($this->usesInMemoryHistory()) {
            return $this->outputSpentBy[$id->value] ?? null;
        }

        \assert($this->historyStore !== null);

        return $this->historyStore->outputSpentBy($id);
    }

    public function getOutput(OutputId $id): ?Output
    {
        $output = $this->unspentSet->get($id);
        if ($output !== null) {
            return $output;
        }

        if ($this->usesInMemoryHistory()) {
            $spentData = $this->spentOutputs[$id->value] ?? null;
            if ($spentData !== null) {
                return new Output(
                    $id,
                    $spentData['amount'],
                    LockFactory::fromArray($spentData['lock']),
                );
            }

            return null;
        }

        \assert($this->historyStore !== null);

        return $this->historyStore->getSpentOutput($id);
    }

    public function outputExists(OutputId $id): bool
    {
        if ($this->unspentSet->contains($id)) {
            return true;
        }

        if ($this->usesInMemoryHistory()) {
            return isset($this->spentOutputs[$id->value]);
        }

        \assert($this->historyStore !== null);

        return $this->historyStore->getSpentOutput($id) !== null;
    }

    public function outputHistory(OutputId $id): ?OutputHistory
    {
        $output = $this->unspentSet->get($id);
        if ($output !== null) {
            if ($this->usesInMemoryHistory()) {
                return OutputHistory::fromOutput(
                    output: $output,
                    createdBy: $this->outputCreatedBy[$id->value] ?? null,
                    spentBy: null,
                );
            }

            \assert($this->historyStore !== null);

            return OutputHistory::fromOutput(
                output: $output,
                createdBy: $this->historyStore->outputCreatedBy($id),
                spentBy: null,
            );
        }

        if ($this->usesInMemoryHistory()) {
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

        \assert($this->historyStore !== null);

        return $this->historyStore->outputHistory($id);
    }

    /**
     * Returns the HistoryStore if using external storage, null otherwise.
     */
    public function historyStore(): ?HistoryStore
    {
        return $this->historyStore;
    }

    /**
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
    public function toArray(): array
    {
        if ($this->usesInMemoryHistory()) {
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

        // Store-backed mode: history is in the store, not serialized here
        return [
            'version' => self::SERIALIZATION_VERSION,
            'unspent' => $this->unspentSet->toArray(),
            'appliedTxs' => array_keys($this->appliedTxIds),
            'txFees' => [],
            'coinbaseAmounts' => [],
            'outputCreatedBy' => [],
            'outputSpentBy' => [],
            'spentOutputs' => [],
        ];
    }

    /**
     * @throws JsonException If encoding fails
     */
    public function toJson(int $flags = 0): string
    {
        return json_encode($this->toArray(), $flags | JSON_THROW_ON_ERROR);
    }

    // ========================================================================
    // Private Helpers
    // ========================================================================

    private function usesInMemoryHistory(): bool
    {
        return $this->historyStore === null;
    }

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
        $spendIds = [];
        foreach ($tx->spends as $spendId) {
            $spendIds[$spendId->value] = true;
        }

        foreach ($tx->outputs as $output) {
            $id = $output->id->value;

            if (isset($spendIds[$id])) {
                continue;
            }

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
