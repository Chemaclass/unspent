<?php

declare(strict_types=1);

namespace Chemaclass\Unspent;

/**
 * Utility class for analyzing UTXO sets.
 *
 * Provides methods for dust detection, output statistics, and consolidation recommendations.
 */
final class UtxoAnalytics
{
    /**
     * Find outputs below a threshold amount ("dust").
     *
     * @param int $threshold Maximum amount to consider as dust
     *
     * @return list<Output> Outputs below the threshold
     */
    public static function findDust(LedgerInterface $ledger, string $owner, int $threshold): array
    {
        $dust = [];

        foreach ($ledger->unspentByOwner($owner) as $output) {
            if ($output->amount < $threshold) {
                $dust[] = $output;
            }
        }

        return $dust;
    }

    /**
     * Get the oldest unspent output for an owner.
     *
     * Returns the first output in iteration order, which for FIFO is the oldest.
     *
     * @return Output|null The oldest output, or null if none found
     */
    public static function oldestUnspent(LedgerInterface $ledger, string $owner): ?Output
    {
        foreach ($ledger->unspentByOwner($owner) as $output) {
            return $output;
        }

        return null;
    }

    /**
     * Get the largest unspent output for an owner.
     *
     * @return Output|null The largest output, or null if none found
     */
    public static function largestUnspent(LedgerInterface $ledger, string $owner): ?Output
    {
        $largest = null;

        foreach ($ledger->unspentByOwner($owner) as $output) {
            if ($largest === null || $output->amount > $largest->amount) {
                $largest = $output;
            }
        }

        return $largest;
    }

    /**
     * Get the smallest unspent output for an owner.
     *
     * @return Output|null The smallest output, or null if none found
     */
    public static function smallestUnspent(LedgerInterface $ledger, string $owner): ?Output
    {
        $smallest = null;

        foreach ($ledger->unspentByOwner($owner) as $output) {
            if ($smallest === null || $output->amount < $smallest->amount) {
                $smallest = $output;
            }
        }

        return $smallest;
    }

    /**
     * Computes every owner metric in a single pass over a pre-fetched unspent
     * set, so callers needing several metrics fetch the outputs only once, e.g.
     * `UtxoAnalytics::summarize($ledger->unspentByOwner('alice'))`.
     *
     * @param int $dustThreshold Amount below which outputs are considered dust
     *
     * @return array{
     *     count: int,
     *     total: int,
     *     average: int,
     *     min: int,
     *     max: int,
     *     dustCount: int,
     *     dustTotal: int,
     *     largest: ?Output,
     *     smallest: ?Output,
     *     oldest: ?Output
     * }
     */
    public static function summarize(UnspentSet $unspent, int $dustThreshold = 10): array
    {
        $count = 0;
        $total = 0;
        $min = 0;
        $max = 0;
        $dustCount = 0;
        $dustTotal = 0;
        $largest = null;
        $smallest = null;
        $oldest = null;

        foreach ($unspent as $output) {
            ++$count;
            $total += $output->amount;

            if ($count === 1) {
                $min = $output->amount;
                $max = $output->amount;
                $oldest = $output;
            } else {
                $min = min($min, $output->amount);
                $max = max($max, $output->amount);
            }

            if ($largest === null || $output->amount > $largest->amount) {
                $largest = $output;
            }
            if ($smallest === null || $output->amount < $smallest->amount) {
                $smallest = $output;
            }
            if ($output->amount < $dustThreshold) {
                ++$dustCount;
                $dustTotal += $output->amount;
            }
        }

        return [
            'count' => $count,
            'total' => $total,
            'average' => $count > 0 ? intdiv($total, $count) : 0,
            'min' => $min,
            'max' => $max,
            'dustCount' => $dustCount,
            'dustTotal' => $dustTotal,
            'largest' => $largest,
            'smallest' => $smallest,
            'oldest' => $oldest,
        ];
    }

    /**
     * Get statistics about an owner's outputs.
     *
     * @param int $dustThreshold Amount below which outputs are considered dust
     *
     * @return array{
     *     count: int,
     *     total: int,
     *     average: int,
     *     min: int,
     *     max: int,
     *     dustCount: int,
     *     dustTotal: int
     * }
     */
    public static function stats(LedgerInterface $ledger, string $owner, int $dustThreshold = 10): array
    {
        $summary = self::summarize($ledger->unspentByOwner($owner), $dustThreshold);

        return [
            'count' => $summary['count'],
            'total' => $summary['total'],
            'average' => $summary['average'],
            'min' => $summary['min'],
            'max' => $summary['max'],
            'dustCount' => $summary['dustCount'],
            'dustTotal' => $summary['dustTotal'],
        ];
    }

    /**
     * Get the number of unspent outputs for an owner.
     */
    public static function outputCountByOwner(LedgerInterface $ledger, string $owner): int
    {
        return $ledger->unspentByOwner($owner)->count();
    }

    /**
     * Check if consolidation is recommended for an owner.
     *
     * Returns true if the owner has more outputs than the threshold.
     *
     * @param int $threshold Output count above which consolidation is recommended
     */
    public static function shouldConsolidate(LedgerInterface $ledger, string $owner, int $threshold = 10): bool
    {
        return self::outputCountByOwner($ledger, $owner) > $threshold;
    }
}
