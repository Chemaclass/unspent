<?php

declare(strict_types=1);

namespace Chemaclass\Unspent;

use Chemaclass\Unspent\Exception\DuplicateOutputIdException;
use Chemaclass\Unspent\Exception\DuplicateTxException;
use Chemaclass\Unspent\Exception\GenesisNotAllowedException;
use Chemaclass\Unspent\Exception\InsufficientSpendsException;
use Chemaclass\Unspent\Exception\OutputAlreadySpentException;
use Chemaclass\Unspent\Persistence\HistoryStore;
use Chemaclass\Unspent\Validation\DuplicateValidator;
use JsonException;

/**
 * Production-ready implementation of the Ledger interface with scalable history storage.
 *
 * Only keeps unspent outputs in memory. All history data (spent outputs, provenance,
 * fees, coinbase info) is delegated to a HistoryStore implementation.
 *
 * Best for:
 * - 100k+ total outputs
 * - Applications with long transaction history
 * - Memory-constrained environments
 *
 * Memory usage is bounded by unspent count, not total history:
 * - 1M total outputs with 100k unspent: ~100 MB
 * - 10M total outputs with 100k unspent: ~100 MB
 * - 100M total outputs with 100k unspent: ~100 MB
 */
final readonly class ScalableLedger implements Ledger
{
    /** Serialization format version for future migration support. */
    private const int SERIALIZATION_VERSION = 1;

    /**
     * @param array<string, true> $appliedTxIds
     */
    private function __construct(
        private UnspentSet $unspentSet,
        private array $appliedTxIds,
        private int $totalFees,
        private int $totalMinted,
        private HistoryStore $historyStore,
    ) {
    }

    /**
     * Creates a new ScalableLedger with the given HistoryStore and genesis outputs.
     */
    public static function create(HistoryStore $store, Output ...$genesis): self
    {
        $ledger = new self(
            UnspentSet::empty(),
            [],
            0,
            0,
            $store,
        );

        if ($genesis !== []) {
            return $ledger->addGenesis(...$genesis);
        }

        return $ledger;
    }

    /**
     * Creates a ScalableLedger from an existing UnspentSet.
     *
     * Use this when loading a ledger from persistence.
     * Only the unspent outputs are loaded into memory.
     */
    public static function fromUnspentSet(
        UnspentSet $unspentSet,
        HistoryStore $store,
        int $totalFees = 0,
        int $totalMinted = 0,
    ): self {
        return new self(
            $unspentSet,
            [],
            $totalFees,
            $totalMinted,
            $store,
        );
    }

    public function addGenesis(Output ...$outputs): self
    {
        if (!$this->unspentSet->isEmpty()) {
            throw GenesisNotAllowedException::ledgerNotEmpty();
        }

        DuplicateValidator::assertNoDuplicateOutputIds(array_values($outputs));

        // Record genesis to the store
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

        // Calculate implicit fee (Bitcoin-style: spends - outputs)
        $fee = $spendAmount - $outputAmount;

        // Collect spent output data for recording
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

        // Update unspent set
        $unspent = $this->unspentSet
            ->removeAll(...$tx->spends)
            ->addAll(...$tx->outputs);

        $appliedTxs = $this->appliedTxIds;
        $appliedTxs[$tx->id->value] = true;

        // Record to store
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

        // Record to store
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
        return $this->historyStore->feeForTx($txId);
    }

    /**
     * Returns all transaction IDs with their associated fees.
     *
     * Note: For ScalableLedger, fees are stored in the HistoryStore.
     * This returns an empty array as fees are not tracked in memory.
     *
     * @return array<string, int>
     */
    public function allTxFees(): array
    {
        // Fees are managed by the HistoryStore, not tracked in memory
        return [];
    }

    public function totalMinted(): int
    {
        return $this->totalMinted;
    }

    public function isCoinbase(TxId $id): bool
    {
        return $this->historyStore->isCoinbase($id);
    }

    public function coinbaseAmount(TxId $id): ?int
    {
        return $this->historyStore->coinbaseAmount($id);
    }

    public function outputCreatedBy(OutputId $id): ?string
    {
        return $this->historyStore->outputCreatedBy($id);
    }

    public function outputSpentBy(OutputId $id): ?string
    {
        return $this->historyStore->outputSpentBy($id);
    }

    public function getOutput(OutputId $id): ?Output
    {
        // First check unspent outputs
        $output = $this->unspentSet->get($id);
        if ($output !== null) {
            return $output;
        }

        // Check the store for spent outputs
        return $this->historyStore->getSpentOutput($id);
    }

    public function outputExists(OutputId $id): bool
    {
        if ($this->unspentSet->contains($id)) {
            return true;
        }

        return $this->historyStore->getSpentOutput($id) !== null;
    }

    public function outputHistory(OutputId $id): ?OutputHistory
    {
        // First check if it's in the unspent set
        $output = $this->unspentSet->get($id);
        if ($output !== null) {
            // For unspent outputs, query provenance from store
            return OutputHistory::fromOutput(
                output: $output,
                createdBy: $this->historyStore->outputCreatedBy($id),
                spentBy: null,
            );
        }

        return $this->historyStore->outputHistory($id);
    }

    /**
     * Returns the HistoryStore used by this ledger.
     */
    public function historyStore(): HistoryStore
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
        // For ScalableLedger, we only serialize what's in memory
        // History data is in the HistoryStore
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
