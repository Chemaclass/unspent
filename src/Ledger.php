<?php

declare(strict_types=1);

namespace Chemaclass\Unspent;

use Chemaclass\Unspent\Exception\DuplicateOutputIdException;
use Chemaclass\Unspent\Exception\DuplicateSpendException;
use Chemaclass\Unspent\Exception\GenesisNotAllowedException;
use Chemaclass\Unspent\Exception\OutputAlreadySpentException;
use Chemaclass\Unspent\Exception\UnbalancedSpendException;

final readonly class Ledger
{
    /**
     * @param array<string, true> $appliedSpendIds
     */
    private function __construct(
        private UnspentSet $unspentSet,
        private array $appliedSpendIds,
    ) {}

    public static function empty(): self
    {
        return new self(UnspentSet::empty(), []);
    }

    public function addGenesis(Output ...$outputs): self
    {
        if (!$this->unspentSet->isEmpty()) {
            throw GenesisNotAllowedException::ledgerNotEmpty();
        }

        $this->assertNoDuplicateOutputIds($outputs);

        return new self(
            UnspentSet::fromOutputs(...$outputs),
            $this->appliedSpendIds,
        );
    }

    public function apply(Spend $spend): self
    {
        $this->assertSpendNotAlreadyApplied($spend);
        $inputAmount = $this->validateInputsAndGetTotal($spend);
        $this->assertSpendIsBalanced($inputAmount, $spend->totalOutputAmount());
        $this->assertNoOutputIdConflicts($spend);

        $unspent = $this->unspentSet
            ->removeAll(...$spend->inputs)
            ->addAll(...$spend->outputs);

        $appliedSpends = $this->appliedSpendIds;
        $appliedSpends[$spend->id->value] = true;

        return new self($unspent, $appliedSpends);
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
        if (isset($this->appliedSpendIds[$spend->id->value])) {
            throw DuplicateSpendException::forId($spend->id->value);
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

    private function assertSpendIsBalanced(int $inputAmount, int $outputAmount): void
    {
        if ($inputAmount !== $outputAmount) {
            throw UnbalancedSpendException::create($inputAmount, $outputAmount);
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
}
