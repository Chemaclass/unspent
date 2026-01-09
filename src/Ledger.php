<?php

declare(strict_types=1);

namespace Chemaclass\Unspent;

use Chemaclass\Unspent\Exception\DuplicateOutputIdException;
use Chemaclass\Unspent\Exception\DuplicateSpendException;
use Chemaclass\Unspent\Exception\GenesisNotAllowedException;
use Chemaclass\Unspent\Exception\InsufficientInputsException;
use Chemaclass\Unspent\Exception\OutputAlreadySpentException;

final readonly class Ledger
{
    /**
     * @param array<string, true> $appliedSpendIds
     * @param array<string, int> $spendFees Map of SpendId value to fee amount
     * @param array<string, int> $coinbaseAmounts Map of coinbase SpendId to minted amount
     */
    private function __construct(
        private UnspentSet $unspentSet,
        private array $appliedSpendIds,
        private array $spendFees = [],
        private int $totalFees = 0,
        private array $coinbaseAmounts = [],
        private int $totalMinted = 0,
    ) {}

    public static function empty(): self
    {
        return new self(UnspentSet::empty(), [], [], 0, [], 0);
    }

    public function addGenesis(Output ...$outputs): self
    {
        if (!$this->unspentSet->isEmpty()) {
            throw GenesisNotAllowedException::ledgerNotEmpty();
        }

        $this->assertNoDuplicateOutputIds(array_values($outputs));

        return new self(
            UnspentSet::fromOutputs(...$outputs),
            $this->appliedSpendIds,
            $this->spendFees,
            $this->totalFees,
            $this->coinbaseAmounts,
            $this->totalMinted,
        );
    }

    public function apply(Spend $spend): self
    {
        $this->assertSpendNotAlreadyApplied($spend);
        $inputAmount = $this->validateInputsAndGetTotal($spend);
        $outputAmount = $spend->totalOutputAmount();
        $this->assertSufficientInputs($inputAmount, $outputAmount);
        $this->assertNoOutputIdConflicts($spend);

        // Calculate implicit fee (Bitcoin-style: inputs - outputs)
        $fee = $inputAmount - $outputAmount;

        $unspent = $this->unspentSet
            ->removeAll(...$spend->inputs)
            ->addAll(...$spend->outputs);

        $appliedSpends = $this->appliedSpendIds;
        $appliedSpends[$spend->id->value] = true;

        $spendFees = $this->spendFees;
        $spendFees[$spend->id->value] = $fee;

        return new self(
            $unspent,
            $appliedSpends,
            $spendFees,
            $this->totalFees + $fee,
            $this->coinbaseAmounts,
            $this->totalMinted,
        );
    }

    public function applyCoinbase(Coinbase $coinbase): self
    {
        $this->assertSpendIdNotAlreadyUsed($coinbase->id);
        $this->assertNoOutputIdConflictsForCoinbase($coinbase);

        $unspent = $this->unspentSet->addAll(...$coinbase->outputs);

        $appliedSpends = $this->appliedSpendIds;
        $appliedSpends[$coinbase->id->value] = true;

        $coinbaseAmounts = $this->coinbaseAmounts;
        $coinbaseAmounts[$coinbase->id->value] = $coinbase->totalOutputAmount();

        return new self(
            $unspent,
            $appliedSpends,
            $this->spendFees,
            $this->totalFees,
            $coinbaseAmounts,
            $this->totalMinted + $coinbase->totalOutputAmount(),
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

    public function hasSpendBeenApplied(SpendId $spendId): bool
    {
        return isset($this->appliedSpendIds[$spendId->value]);
    }

    /**
     * Returns the total fees collected across all applied spends.
     */
    public function totalFeesCollected(): int
    {
        return $this->totalFees;
    }

    /**
     * Returns the fee paid for a specific spend.
     *
     * @return int|null Fee amount, or null if spend not found
     */
    public function feeForSpend(SpendId $spendId): ?int
    {
        return $this->spendFees[$spendId->value] ?? null;
    }

    /**
     * Returns all spend IDs with their associated fees.
     *
     * @return array<string, int> Map of SpendId value to fee
     */
    public function allSpendFees(): array
    {
        return $this->spendFees;
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
    public function isCoinbase(SpendId $id): bool
    {
        return isset($this->coinbaseAmounts[$id->value]);
    }

    /**
     * Returns the minted amount for a coinbase transaction.
     *
     * @return int|null Minted amount, or null if not a coinbase
     */
    public function coinbaseAmount(SpendId $id): ?int
    {
        return $this->coinbaseAmounts[$id->value] ?? null;
    }

    /**
     * @param list<Output> $outputs
     */
    private function assertNoDuplicateOutputIds(array $outputs): void
    {
        $seen = [];
        foreach ($outputs as $output) {
            $id = $output->id->value;
            if (isset($seen[$id])) {
                throw DuplicateOutputIdException::forId($id);
            }
            $seen[$id] = true;
        }
    }

    private function assertSpendNotAlreadyApplied(Spend $spend): void
    {
        $this->assertSpendIdNotAlreadyUsed($spend->id);
    }

    private function assertSpendIdNotAlreadyUsed(SpendId $id): void
    {
        if (isset($this->appliedSpendIds[$id->value])) {
            throw DuplicateSpendException::forId($id->value);
        }
    }

    /**
     * Validates all inputs exist in unspent set and returns total input amount.
     */
    private function validateInputsAndGetTotal(Spend $spend): int
    {
        $inputAmount = 0;
        foreach ($spend->inputs as $inputId) {
            $output = $this->unspentSet->get($inputId);
            if ($output === null) {
                throw OutputAlreadySpentException::forId($inputId->value);
            }
            $inputAmount += $output->amount;
        }

        return $inputAmount;
    }

    private function assertSufficientInputs(int $inputAmount, int $outputAmount): void
    {
        if ($inputAmount < $outputAmount) {
            throw InsufficientInputsException::create($inputAmount, $outputAmount);
        }
    }

    private function assertNoOutputIdConflicts(Spend $spend): void
    {
        // Build set of input IDs being spent (O(n))
        $inputIds = [];
        foreach ($spend->inputs as $inputId) {
            $inputIds[$inputId->value] = true;
        }

        // Check each output ID (O(m))
        foreach ($spend->outputs as $output) {
            $id = $output->id->value;

            // Skip IDs that are being spent (they will be removed)
            if (isset($inputIds[$id])) {
                continue;
            }

            // Check for conflict with existing unspent outputs
            if ($this->unspentSet->contains($output->id)) {
                throw DuplicateOutputIdException::forId($id);
            }
        }
    }

    private function assertNoOutputIdConflictsForCoinbase(Coinbase $coinbase): void
    {
        foreach ($coinbase->outputs as $output) {
            if ($this->unspentSet->contains($output->id)) {
                throw DuplicateOutputIdException::forId($output->id->value);
            }
        }
    }
}
