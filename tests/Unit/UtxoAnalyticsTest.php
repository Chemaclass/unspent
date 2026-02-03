<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit;

use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\UtxoAnalytics;
use PHPUnit\Framework\TestCase;

final class UtxoAnalyticsTest extends TestCase
{
    // ========================================
    // findDust() tests
    // ========================================

    public function test_find_dust_returns_outputs_below_threshold(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 5, 'a1'),
            Output::ownedBy('alice', 15, 'a2'),
            Output::ownedBy('alice', 3, 'a3'),
            Output::ownedBy('alice', 100, 'a4'),
        );

        $dust = UtxoAnalytics::findDust($ledger, 'alice', threshold: 10);

        self::assertCount(2, $dust);
        $ids = array_map(static fn (Output $o): string => $o->id->value, $dust);
        self::assertContains('a1', $ids);
        self::assertContains('a3', $ids);
    }

    public function test_find_dust_returns_empty_when_no_dust(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 100, 'a1'),
            Output::ownedBy('alice', 200, 'a2'),
        );

        $dust = UtxoAnalytics::findDust($ledger, 'alice', threshold: 10);

        self::assertCount(0, $dust);
    }

    public function test_find_dust_returns_empty_for_unknown_owner(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('bob', 5, 'b1'),
        );

        $dust = UtxoAnalytics::findDust($ledger, 'alice', threshold: 10);

        self::assertCount(0, $dust);
    }

    // ========================================
    // oldestUnspent() tests
    // ========================================

    public function test_oldest_unspent_returns_first_output(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 100, 'oldest'),
            Output::ownedBy('alice', 200, 'middle'),
            Output::ownedBy('alice', 300, 'newest'),
        );

        $oldest = UtxoAnalytics::oldestUnspent($ledger, 'alice');

        self::assertNotNull($oldest);
        self::assertSame('oldest', $oldest->id->value);
    }

    public function test_oldest_unspent_returns_null_for_no_outputs(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('bob', 100, 'b1'),
        );

        $oldest = UtxoAnalytics::oldestUnspent($ledger, 'alice');

        self::assertNull($oldest);
    }

    // ========================================
    // stats() tests
    // ========================================

    public function test_stats_returns_correct_values(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 100, 'a1'),
            Output::ownedBy('alice', 200, 'a2'),
            Output::ownedBy('alice', 5, 'a3'),   // dust
            Output::ownedBy('alice', 3, 'a4'),   // dust
            Output::ownedBy('alice', 192, 'a5'),
        );

        $stats = UtxoAnalytics::stats($ledger, 'alice', dustThreshold: 10);

        self::assertSame(5, $stats['count']);
        self::assertSame(500, $stats['total']);
        self::assertSame(100, $stats['average']);
        self::assertSame(3, $stats['min']);
        self::assertSame(200, $stats['max']);
        self::assertSame(2, $stats['dustCount']);
        self::assertSame(8, $stats['dustTotal']);
    }

    public function test_stats_returns_zeros_for_no_outputs(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('bob', 100, 'b1'),
        );

        $stats = UtxoAnalytics::stats($ledger, 'alice');

        self::assertSame(0, $stats['count']);
        self::assertSame(0, $stats['total']);
        self::assertSame(0, $stats['average']);
        self::assertSame(0, $stats['min']);
        self::assertSame(0, $stats['max']);
        self::assertSame(0, $stats['dustCount']);
        self::assertSame(0, $stats['dustTotal']);
    }

    // ========================================
    // largestUnspent() tests
    // ========================================

    public function test_largest_unspent_returns_biggest_output(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 100, 'a1'),
            Output::ownedBy('alice', 500, 'biggest'),
            Output::ownedBy('alice', 200, 'a3'),
        );

        $largest = UtxoAnalytics::largestUnspent($ledger, 'alice');

        self::assertNotNull($largest);
        self::assertSame('biggest', $largest->id->value);
        self::assertSame(500, $largest->amount);
    }

    public function test_largest_unspent_returns_null_for_no_outputs(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('bob', 100, 'b1'),
        );

        $largest = UtxoAnalytics::largestUnspent($ledger, 'alice');

        self::assertNull($largest);
    }

    // ========================================
    // smallestUnspent() tests
    // ========================================

    public function test_smallest_unspent_returns_smallest_output(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 100, 'a1'),
            Output::ownedBy('alice', 5, 'smallest'),
            Output::ownedBy('alice', 200, 'a3'),
        );

        $smallest = UtxoAnalytics::smallestUnspent($ledger, 'alice');

        self::assertNotNull($smallest);
        self::assertSame('smallest', $smallest->id->value);
        self::assertSame(5, $smallest->amount);
    }

    public function test_smallest_unspent_returns_null_for_no_outputs(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('bob', 100, 'b1'),
        );

        $smallest = UtxoAnalytics::smallestUnspent($ledger, 'alice');

        self::assertNull($smallest);
    }

    // ========================================
    // outputCountByOwner() tests
    // ========================================

    public function test_output_count_by_owner(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 100, 'a1'),
            Output::ownedBy('alice', 200, 'a2'),
            Output::ownedBy('bob', 300, 'b1'),
        );

        self::assertSame(2, UtxoAnalytics::outputCountByOwner($ledger, 'alice'));
        self::assertSame(1, UtxoAnalytics::outputCountByOwner($ledger, 'bob'));
        self::assertSame(0, UtxoAnalytics::outputCountByOwner($ledger, 'charlie'));
    }

    // ========================================
    // shouldConsolidate() tests
    // ========================================

    public function test_should_consolidate_returns_true_when_many_outputs(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 10, 'a1'),
            Output::ownedBy('alice', 10, 'a2'),
            Output::ownedBy('alice', 10, 'a3'),
            Output::ownedBy('alice', 10, 'a4'),
            Output::ownedBy('alice', 10, 'a5'),
        );

        self::assertTrue(UtxoAnalytics::shouldConsolidate($ledger, 'alice', threshold: 3));
    }

    public function test_should_consolidate_returns_false_when_few_outputs(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 100, 'a1'),
            Output::ownedBy('alice', 200, 'a2'),
        );

        self::assertFalse(UtxoAnalytics::shouldConsolidate($ledger, 'alice', threshold: 3));
    }
}
