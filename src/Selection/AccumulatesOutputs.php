<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Selection;

use Chemaclass\Unspent\Output;

/**
 * Shared accumulation logic for selection strategies that pick outputs,
 * in a given order, until the target amount is reached.
 *
 * Extracted from FifoStrategy, LargestFirstStrategy, SmallestFirstStrategy,
 * and RandomStrategy to eliminate duplicated selection loops (DRY).
 */
trait AccumulatesOutputs
{
    /**
     * @param list<Output> $orderedOutputs Outputs pre-ordered by the strategy
     *
     * @return list<Output>
     */
    private function accumulateUntilTarget(array $orderedOutputs, int $target): array
    {
        $selected = [];
        $accumulated = 0;

        foreach ($orderedOutputs as $output) {
            $selected[] = $output;
            $accumulated += $output->amount;

            if ($accumulated >= $target) {
                break;
            }
        }

        return $selected;
    }
}
