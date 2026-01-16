<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Selection;

use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\UnspentSet;

/**
 * Largest-first selection strategy.
 *
 * Selects the largest outputs first to minimize the number of inputs.
 * This reduces transaction size and consolidates value into fewer outputs.
 */
final readonly class LargestFirstStrategy implements SelectionStrategy
{
    public function select(UnspentSet $available, int $target): array
    {
        // Collect and sort by amount descending
        $outputs = iterator_to_array($available);
        usort($outputs, static fn (Output $a, Output $b): int => $b->amount <=> $a->amount);

        $selected = [];
        $accumulated = 0;

        foreach ($outputs as $output) {
            $selected[] = $output;
            $accumulated += $output->amount;

            if ($accumulated >= $target) {
                break;
            }
        }

        return $selected;
    }

    public function name(): string
    {
        return 'largest-first';
    }
}
