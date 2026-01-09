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

        $unspent = $this->unspentSet;
        foreach ($outputs as $output) {
            $unspent = $unspent->add($output);
        }

        return new self($unspent, $this->appliedSpendIds);
    }

    public function apply(Spend $spend): self
    {
        $this->assertSpendNotAlreadyApplied($spend);
        $this->assertAllInputsExistInUnspentSet($spend);
        $this->assertSpendIsBalanced($spend);
        $this->assertNoOutputIdConflicts($spend);

        $unspent = $this->unspentSet;

        foreach ($spend->inputs as $inputId) {
            $unspent = $unspent->remove($inputId);
        }

        foreach ($spend->outputs as $output) {
            $unspent = $unspent->add($output);
        }

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

    private function assertAllInputsExistInUnspentSet(Spend $spend): void
    {
        foreach ($spend->inputs as $inputId) {
            if (!$this->unspentSet->contains($inputId)) {
                throw OutputAlreadySpentException::forId($inputId->value);
            }
        }
    }

    private function assertSpendIsBalanced(Spend $spend): void
    {
        $inputAmount = 0;
        foreach ($spend->inputs as $inputId) {
            $output = $this->unspentSet->get($inputId);
            if ($output !== null) {
                $inputAmount += $output->amount;
            }
        }

        $outputAmount = $spend->totalOutputAmount();

        if ($inputAmount !== $outputAmount) {
            throw UnbalancedSpendException::create($inputAmount, $outputAmount);
        }
    }

    private function assertNoOutputIdConflicts(Spend $spend): void
    {
        $seen = [];
        foreach ($spend->outputs as $output) {
            $id = $output->id->value;

            if (isset($seen[$id])) {
                throw DuplicateOutputIdException::forId($id);
            }

            if ($this->unspentSet->contains($output->id)) {
                $isBeingSpent = false;
                foreach ($spend->inputs as $inputId) {
                    if ($inputId->equals($output->id)) {
                        $isBeingSpent = true;
                        break;
                    }
                }
                if (!$isBeingSpent) {
                    throw DuplicateOutputIdException::forId($id);
                }
            }

            $seen[$id] = true;
        }
    }
}
