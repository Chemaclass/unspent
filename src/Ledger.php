<?php

declare(strict_types=1);

namespace Chemaclass\Unspent;

use Chemaclass\Unspent\Exception\AuthorizationException;
use Chemaclass\Unspent\Exception\DuplicateOutputIdException;
use Chemaclass\Unspent\Exception\DuplicateTxException;
use Chemaclass\Unspent\Exception\GenesisNotAllowedException;
use Chemaclass\Unspent\Exception\InsufficientSpendsException;
use Chemaclass\Unspent\Exception\OutputAlreadySpentException;
use Chemaclass\Unspent\Persistence\HistoryRepository;
use Chemaclass\Unspent\Persistence\InMemoryHistoryRepository;
use Chemaclass\Unspent\Validation\DuplicateValidator;
use JsonException;

/**
 * Immutable UTXO ledger with pluggable history storage.
 *
 * - Use `Ledger::inMemory()` for development/testing (InMemoryHistoryRepository)
 * - Use `Ledger::withRepository($repo)` for production (SqliteHistoryRepository, etc.)
 *
 * Memory usage depends on the HistoryRepository implementation:
 * - InMemoryHistoryRepository: grows with total history
 * - SqliteHistoryRepository: bounded by unspent count only
 */
final readonly class Ledger implements LedgerInterface
{
    /** Library version following semver. */
    public const string VERSION = '1.0.0';

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
        private HistoryRepository $historyRepository,
    ) {
    }

    public static function inMemory(): self
    {
        return new self(
            unspentSet: UnspentSet::empty(),
            appliedTxIds: [],
            totalFees: 0,
            totalMinted: 0,
            historyRepository: new InMemoryHistoryRepository(),
        );
    }

    /**
     * Creates an in-memory ledger with genesis outputs.
     *
     * @throws DuplicateOutputIdException If any output ID is duplicated
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

        $historyRepository = InMemoryHistoryRepository::fromArray([
            'txFees' => $data['txFees'],
            'coinbaseAmounts' => $data['coinbaseAmounts'],
            'outputCreatedBy' => $data['outputCreatedBy'] ?? [],
            'outputSpentBy' => $data['outputSpentBy'] ?? [],
            'spentOutputs' => $data['spentOutputs'] ?? [],
        ]);

        return new self(
            unspentSet: UnspentSet::fromArray($data['unspent']),
            appliedTxIds: $appliedTxIds,
            totalFees: $totalFees,
            totalMinted: $totalMinted,
            historyRepository: $historyRepository,
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

    public static function withRepository(HistoryRepository $repository): self
    {
        return new self(
            unspentSet: UnspentSet::empty(),
            appliedTxIds: [],
            totalFees: 0,
            totalMinted: 0,
            historyRepository: $repository,
        );
    }

    /**
     * Creates a ledger from an existing UnspentSet with external history storage.
     *
     * Use this when loading a ledger from persistence.
     */
    public static function fromUnspentSet(
        UnspentSet $unspentSet,
        HistoryRepository $repository,
        int $totalFees = 0,
        int $totalMinted = 0,
    ): self {
        return new self(
            unspentSet: $unspentSet,
            appliedTxIds: [],
            totalFees: $totalFees,
            totalMinted: $totalMinted,
            historyRepository: $repository,
        );
    }

    /**
     * Adds genesis outputs to an empty ledger.
     *
     * @throws GenesisNotAllowedException If the ledger is not empty
     * @throws DuplicateOutputIdException If any output ID is duplicated
     */
    public function addGenesis(Output ...$outputs): self
    {
        if (!$this->unspentSet->isEmpty()) {
            throw GenesisNotAllowedException::ledgerNotEmpty();
        }

        DuplicateValidator::assertNoDuplicateOutputIds(array_values($outputs));

        $newRepository = $this->historyRepository->withGenesis(array_values($outputs));

        return new self(
            UnspentSet::fromOutputs(...$outputs),
            $this->appliedTxIds,
            $this->totalFees,
            $this->totalMinted,
            $newRepository,
        );
    }

    /**
     * Applies a transaction to the ledger.
     *
     * @throws DuplicateTxException        If the transaction ID was already used
     * @throws OutputAlreadySpentException If any spend references an output not in the unspent set
     * @throws InsufficientSpendsException If the total output amount exceeds the total spend amount
     * @throws DuplicateOutputIdException  If any new output ID already exists in the unspent set
     * @throws AuthorizationException      If authorization fails for any spent output
     */
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

        $newRepository = $this->historyRepository->withTransaction($tx, $fee, $spentOutputData);

        return new self(
            $unspent,
            $appliedTxs,
            $this->totalFees + $fee,
            $this->totalMinted,
            $newRepository,
        );
    }

    /**
     * Applies a coinbase (minting) transaction to the ledger.
     *
     * @throws DuplicateTxException       If the transaction ID was already used
     * @throws DuplicateOutputIdException If any output ID already exists in the unspent set
     */
    public function applyCoinbase(CoinbaseTx $coinbase): static
    {
        $this->assertTxIdNotAlreadyUsed($coinbase->id);
        $this->assertNoOutputIdConflictsForCoinbase($coinbase);

        $unspent = $this->unspentSet->addAll(...$coinbase->outputs);
        $mintedAmount = $coinbase->totalOutputAmount();

        $appliedTxs = $this->appliedTxIds;
        $appliedTxs[$coinbase->id->value] = true;

        $newRepository = $this->historyRepository->withCoinbase($coinbase);

        return new self(
            $unspent,
            $appliedTxs,
            $this->totalFees,
            $this->totalMinted + $mintedAmount,
            $newRepository,
        );
    }

    public function transfer(string $from, string $to, int $amount, int $fee = 0, ?string $txId = null): static
    {
        $required = $amount + $fee;
        $outputsToSpend = [];
        $accumulated = 0;

        foreach ($this->unspentByOwner($from) as $output) {
            $outputsToSpend[] = $output->id->value;
            $accumulated += $output->amount;
            if ($accumulated >= $required) {
                break;
            }
        }

        if ($accumulated < $required) {
            throw InsufficientSpendsException::create($accumulated, $required);
        }

        $outputs = [Output::ownedBy($to, $amount)];
        $change = $accumulated - $required;
        if ($change > 0) {
            $outputs[] = Output::ownedBy($from, $change);
        }

        return $this->apply(Tx::create(
            spendIds: $outputsToSpend,
            outputs: $outputs,
            signedBy: $from,
            id: $txId,
        ));
    }

    public function debit(string $owner, int $amount, int $fee = 0, ?string $txId = null): static
    {
        $required = $amount + $fee;
        $outputsToSpend = [];
        $accumulated = 0;

        foreach ($this->unspentByOwner($owner) as $output) {
            $outputsToSpend[] = $output->id->value;
            $accumulated += $output->amount;
            if ($accumulated >= $required) {
                break;
            }
        }

        if ($accumulated < $required) {
            throw InsufficientSpendsException::create($accumulated, $required);
        }

        $outputs = [];
        $change = $accumulated - $required;
        if ($change > 0) {
            $outputs[] = Output::ownedBy($owner, $change);
        }

        return $this->apply(Tx::create(
            spendIds: $outputsToSpend,
            outputs: $outputs,
            signedBy: $owner,
            id: $txId,
        ));
    }

    public function credit(string $owner, int $amount, ?string $txId = null): static
    {
        return $this->applyCoinbase(CoinbaseTx::create(
            outputs: [Output::ownedBy($owner, $amount)],
            id: $txId,
        ));
    }

    public function unspent(): UnspentSet
    {
        return $this->unspentSet->release();
    }

    public function totalUnspentAmount(): int
    {
        return $this->unspentSet->totalAmount();
    }

    /**
     * Returns all unspent outputs owned by a specific owner.
     *
     * For large datasets with SQLite, prefer QueryableLedgerRepository::findUnspentByOwner()
     * for O(1) memory usage.
     */
    public function unspentByOwner(string $owner): UnspentSet
    {
        return $this->unspentSet->ownedBy($owner);
    }

    /**
     * Returns total unspent amount for a specific owner.
     *
     * For large datasets with SQLite, prefer QueryableLedgerRepository::sumUnspentByOwner()
     * for O(1) memory usage.
     */
    public function totalUnspentByOwner(string $owner): int
    {
        return $this->unspentSet->totalAmountOwnedBy($owner);
    }

    /**
     * Checks if a transaction can be applied without actually applying it.
     *
     * Returns null if the transaction is valid, or the exception that would be thrown.
     * Useful for validation before committing to apply.
     */
    public function canApply(Tx $tx): ?Exception\UnspentException
    {
        try {
            $this->assertTxNotAlreadyApplied($tx);
            $spendAmount = $this->validateSpendsAndGetTotal($tx);
            $outputAmount = $tx->totalOutputAmount();
            $this->assertSufficientSpends($spendAmount, $outputAmount);
            $this->assertNoOutputIdConflicts($tx);

            return null;
        } catch (Exception\UnspentException $e) {
            return $e;
        }
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
        return $this->historyRepository->findFeeForTx($txId);
    }

    public function allTxFees(): array
    {
        return $this->historyRepository->findAllTxFees();
    }

    public function totalMinted(): int
    {
        return $this->totalMinted;
    }

    public function isCoinbase(TxId $id): bool
    {
        return $this->historyRepository->isCoinbase($id);
    }

    public function coinbaseAmount(TxId $id): ?int
    {
        return $this->historyRepository->findCoinbaseAmount($id);
    }

    public function outputCreatedBy(OutputId $id): ?string
    {
        return $this->historyRepository->findOutputCreatedBy($id);
    }

    public function outputSpentBy(OutputId $id): ?string
    {
        return $this->historyRepository->findOutputSpentBy($id);
    }

    public function getOutput(OutputId $id): ?Output
    {
        $output = $this->unspentSet->get($id);
        if ($output !== null) {
            return $output;
        }

        return $this->historyRepository->findSpentOutput($id);
    }

    public function outputExists(OutputId $id): bool
    {
        if ($this->unspentSet->contains($id)) {
            return true;
        }

        return $this->historyRepository->findSpentOutput($id) !== null;
    }

    public function outputHistory(OutputId $id): ?OutputHistory
    {
        $output = $this->unspentSet->get($id);
        if ($output !== null) {
            return OutputHistory::fromOutput(
                output: $output,
                createdBy: $this->historyRepository->findOutputCreatedBy($id),
                spentBy: null,
            );
        }

        return $this->historyRepository->findOutputHistory($id);
    }

    /**
     * Returns the HistoryRepository.
     */
    public function historyRepository(): HistoryRepository
    {
        return $this->historyRepository;
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
        $historyData = $this->historyRepository instanceof InMemoryHistoryRepository
            ? $this->historyRepository->toArray()
            : [
                'txFees' => [],
                'coinbaseAmounts' => [],
                'outputCreatedBy' => [],
                'outputSpentBy' => [],
                'spentOutputs' => [],
            ];

        return [
            'version' => self::SERIALIZATION_VERSION,
            'unspent' => $this->unspentSet->toArray(),
            'appliedTxs' => array_keys($this->appliedTxIds),
            'txFees' => $historyData['txFees'],
            'coinbaseAmounts' => $historyData['coinbaseAmounts'],
            'outputCreatedBy' => $historyData['outputCreatedBy'],
            'outputSpentBy' => $historyData['outputSpentBy'],
            'spentOutputs' => $historyData['spentOutputs'],
        ];
    }

    /**
     * @throws JsonException If encoding fails
     */
    public function toJson(int $flags = 0): string
    {
        return json_encode($this->toArray(), $flags | JSON_THROW_ON_ERROR);
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
