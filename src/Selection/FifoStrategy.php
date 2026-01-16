<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Selection;

use Chemaclass\Unspent\UnspentSet;

/**
 * First-In-First-Out selection strategy.
 *
 * Selects outputs in the order they appear in the UnspentSet iterator.
 * This is the default behavior and ensures fairness - older outputs
 * are spent before newer ones.
 */
final readonly class FifoStrategy implements SelectionStrategy
{
    public function select(UnspentSet $available, int $target): array
    {
        $selected = [];
        $accumulated = 0;

        foreach ($available as $output) {
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
        return 'fifo';
    }
}
