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
    use AccumulatesOutputs;

    public function select(UnspentSet $available, int $target): array
    {
        /** @var list<Output> $all */
        $all = iterator_to_array($available);

        shuffle($all);

        return $this->accumulateUntilTarget($all, $target);
    }

    public function name(): string
    {
        return 'random';
    }
}
