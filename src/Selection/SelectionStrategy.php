<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Selection;

use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\UnspentSet;

/**
 * Strategy for selecting which outputs to spend.
 *
 * Different strategies optimize for different goals:
 * - FIFO: Spend oldest outputs first (fairness)
 * - Largest first: Minimize number of inputs (reduce fragmentation)
 * - Smallest first: Consolidate dust outputs
 * - Exact match: Find outputs that exactly match the target amount
 */
interface SelectionStrategy
{
    /**
     * Select outputs to spend from the available set.
     *
     * @param UnspentSet $available Available outputs to choose from
     * @param int        $target    Target amount to reach
     *
     * @return list<Output> Selected outputs (may exceed target for change)
     */
    public function select(UnspentSet $available, int $target): array;

    /**
     * Returns a human-readable name for the strategy.
     */
    public function name(): string;
}
