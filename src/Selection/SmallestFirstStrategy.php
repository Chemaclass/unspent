<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Selection;

use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\UnspentSet;

/**
 * Smallest-first selection strategy.
 *
 * Selects the smallest outputs first to consolidate "dust" outputs.
 * This is useful for cleaning up many small outputs into fewer larger ones.
 */
final readonly class SmallestFirstStrategy implements SelectionStrategy
{
    public function select(UnspentSet $available, int $target): array
    {
        // Collect and sort by amount ascending
        $outputs = iterator_to_array($available);
        usort($outputs, static fn (Output $a, Output $b): int => $a->amount <=> $b->amount);

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
        return 'smallest-first';
    }
}
