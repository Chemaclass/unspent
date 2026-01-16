<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Selection;

use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\UnspentSet;

/**
 * Exact-match selection strategy.
 *
 * Attempts to find a combination of outputs that exactly matches the target,
 * eliminating the need for change outputs. Falls back to largest-first if
 * no exact match is found.
 *
 * This uses a branch-and-bound algorithm with limits to prevent
 * exponential search time on large output sets.
 */
final readonly class ExactMatchStrategy implements SelectionStrategy
{
    private const int MAX_ITERATIONS = 10000;
    private const int MAX_OUTPUTS_TO_CONSIDER = 100;

    public function __construct(
        private SelectionStrategy $fallback = new LargestFirstStrategy(),
    ) {
    }

    public function select(UnspentSet $available, int $target): array
    {
        $outputs = iterator_to_array($available);

        // Limit outputs to consider for performance
        if (\count($outputs) > self::MAX_OUTPUTS_TO_CONSIDER) {
            usort($outputs, static fn (Output $a, Output $b): int => $b->amount <=> $a->amount);
            $outputs = \array_slice($outputs, 0, self::MAX_OUTPUTS_TO_CONSIDER);
        }

        // Sort descending for branch-and-bound efficiency
        usort($outputs, static fn (Output $a, Output $b): int => $b->amount <=> $a->amount);

        $best = null;
        $iterations = 0;

        $this->search($outputs, $target, [], 0, 0, $best, $iterations);

        if ($best !== null) {
            return $best;
        }

        // Fall back to default strategy
        return $this->fallback->select($available, $target);
    }

    public function name(): string
    {
        return 'exact-match';
    }

    /**
     * @param list<Output>      $outputs    Available outputs (sorted descending)
     * @param int               $target     Target amount
     * @param list<Output>      $current    Current selection
     * @param int               $sum        Sum of current selection
     * @param int               $startIndex Index to start from
     * @param list<Output>|null $best       Best exact match found
     * @param int               $iterations Iteration counter for early termination
     */
    private function search(
        array $outputs,
        int $target,
        array $current,
        int $sum,
        int $startIndex,
        ?array &$best,
        int &$iterations,
    ): void {
        if ($iterations >= self::MAX_ITERATIONS) {
            return;
        }

        if ($sum === $target) {
            if ($best === null || \count($current) < \count($best)) {
                $best = $current;
            }

            return;
        }

        if ($sum > $target) {
            return;
        }

        $remaining = 0;
        $counter = \count($outputs);
        for ($i = $startIndex; $i < $counter; ++$i) {
            $remaining += $outputs[$i]->amount;
        }

        if ($sum + $remaining < $target) {
            return;
        }
        $counter = \count($outputs);

        for ($i = $startIndex; $i < $counter; ++$i) {
            ++$iterations;
            if ($iterations >= self::MAX_ITERATIONS) {
                return;
            }

            $output = $outputs[$i];
            $newSum = $sum + $output->amount;

            if ($newSum <= $target) {
                $current[] = $output;
                $this->search($outputs, $target, $current, $newSum, $i + 1, $best, $iterations);
                array_pop($current);
            }

            if ($best !== null && $sum === $target) {
                return;
            }
        }
    }
}
