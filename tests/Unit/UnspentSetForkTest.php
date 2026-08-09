<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit;

use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\UnspentSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Covers the copy-on-fork branches, which only run once a set has been shared
 * via snapshot(). The owned/in-place branches are exercised everywhere else, so
 * without these the fork arithmetic and owner-index bookkeeping go untested.
 */
#[CoversClass(UnspentSet::class)]
final class UnspentSetForkTest extends TestCase
{
    public function test_add_all_on_a_shared_set_forks_and_sums_every_output(): void
    {
        $shared = UnspentSet::fromOutputs(Output::ownedBy('alice', 100, 'a-1'))->snapshot();

        $forked = $shared->addAll(
            Output::ownedBy('bob', 30, 'b-1'),
            Output::ownedBy('bob', 7, 'b-2'),
        );

        self::assertSame(137, $forked->totalAmount());
        self::assertSame(100, $shared->totalAmount(), 'the shared set must be untouched');
    }

    public function test_add_all_on_a_shared_set_replaces_an_existing_output_by_delta(): void
    {
        $shared = UnspentSet::fromOutputs(Output::ownedBy('alice', 100, 'a-1'))->snapshot();

        $forked = $shared->addAll(Output::ownedBy('alice', 250, 'a-1'));

        self::assertSame(250, $forked->totalAmount());
        self::assertSame(1, $forked->count());
        self::assertSame(100, $shared->totalAmount());
    }

    public function test_remove_all_on_a_shared_set_subtracts_every_removed_output(): void
    {
        $shared = UnspentSet::fromOutputs(
            Output::ownedBy('alice', 100, 'a-1'),
            Output::ownedBy('alice', 30, 'a-2'),
            Output::ownedBy('bob', 5, 'b-1'),
        )->snapshot();

        $forked = $shared->removeAll(new OutputId('a-1'), new OutputId('a-2'));

        self::assertSame(5, $forked->totalAmount());
        self::assertSame(135, $shared->totalAmount(), 'the shared set must be untouched');
    }

    public function test_remove_all_on_a_shared_set_drops_the_output_from_the_owner_index(): void
    {
        $shared = UnspentSet::fromOutputs(
            Output::ownedBy('alice', 100, 'a-1'),
            Output::ownedBy('alice', 30, 'a-2'),
        )->snapshot();

        $forked = $shared->removeAll(new OutputId('a-1'));

        self::assertSame(30, $forked->totalAmountOwnedBy('alice'));
        self::assertSame(['a-2'], array_keys(iterator_to_array($forked->iterateOwnedBy('alice'))));
        self::assertSame(130, $shared->totalAmountOwnedBy('alice'));
    }

    public function test_filter_rebuilds_the_owner_index_of_the_result(): void
    {
        $set = UnspentSet::fromOutputs(
            Output::ownedBy('alice', 100, 'a-1'),
            Output::ownedBy('alice', 5, 'a-2'),
            Output::ownedBy('bob', 200, 'b-1'),
        );

        $filtered = $set->filter(static fn (Output $output): bool => $output->amount >= 100);

        self::assertSame(100, $filtered->totalAmountOwnedBy('alice'));
        self::assertSame(200, $filtered->totalAmountOwnedBy('bob'));
        self::assertSame(['a-1'], array_keys(iterator_to_array($filtered->iterateOwnedBy('alice'))));
    }

    public function test_owned_by_result_keeps_a_usable_owner_index(): void
    {
        $set = UnspentSet::fromOutputs(
            Output::ownedBy('alice', 100, 'a-1'),
            Output::ownedBy('alice', 30, 'a-2'),
            Output::ownedBy('bob', 200, 'b-1'),
        );

        $owned = $set->ownedBy('alice');

        self::assertSame(130, $owned->totalAmountOwnedBy('alice'));
        self::assertSame(['a-1', 'a-2'], array_keys(iterator_to_array($owned->iterateOwnedBy('alice'))));
    }

    public function test_add_all_on_a_shared_set_reassigns_ownership_when_replacing_an_output(): void
    {
        $shared = UnspentSet::fromOutputs(Output::ownedBy('alice', 100, 'x'))->snapshot();

        $forked = $shared->addAll(Output::ownedBy('bob', 100, 'x'));

        self::assertSame(0, $forked->totalAmountOwnedBy('alice'), 'the previous owner must be unindexed');
        self::assertSame(100, $forked->totalAmountOwnedBy('bob'));
        self::assertSame([], iterator_to_array($forked->iterateOwnedBy('alice')));
        self::assertSame(100, $shared->totalAmountOwnedBy('alice'));
    }
}
