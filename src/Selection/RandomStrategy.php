<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Selection;

use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\UnspentSet;

/**
 * Random selection strategy.
 *
 * Shuffles outputs randomly before selecting, which improves
 * privacy by making spending patterns unpredictable.
 */
final readonly class RandomStrategy implements SelectionStrategy
{
    public function select(UnspentSet $available, int $target): array
    {
        /** @var list<Output> $all */
        $all = iterator_to_array($available);

        shuffle($all);

        $selected = [];
        $accumulated = 0;

        foreach ($all as $output) {
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
        return 'random';
    }
}
