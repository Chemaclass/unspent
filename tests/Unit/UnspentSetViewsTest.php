<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit;

use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\UnspentSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnspentSet::class)]
final class UnspentSetViewsTest extends TestCase
{
    public function test_values_returns_outputs_as_a_list(): void
    {
        $set = UnspentSet::fromOutputs(
            Output::ownedBy('alice', 100, 'a-1'),
            Output::ownedBy('bob', 200, 'b-1'),
        );

        $values = $set->values();

        self::assertSame([0, 1], array_keys($values));
        self::assertSame('a-1', $values[0]->id->value);
        self::assertSame('b-1', $values[1]->id->value);
    }

    public function test_values_returns_empty_array_for_empty_set(): void
    {
        self::assertSame([], UnspentSet::empty()->values());
    }

    public function test_first_returns_the_output_in_insertion_order(): void
    {
        $set = UnspentSet::fromOutputs(
            Output::ownedBy('alice', 100, 'a-1'),
            Output::ownedBy('alice', 200, 'a-2'),
        );

        self::assertSame('a-1', $set->first()?->id->value);
    }

    public function test_first_returns_null_for_empty_set(): void
    {
        self::assertNull(UnspentSet::empty()->first());
    }

    public function test_iterate_owned_by_yields_only_that_owners_outputs(): void
    {
        $set = UnspentSet::fromOutputs(
            Output::ownedBy('alice', 100, 'a-1'),
            Output::ownedBy('bob', 200, 'b-1'),
            Output::ownedBy('alice', 300, 'a-2'),
        );

        $ids = [];
        foreach ($set->iterateOwnedBy('alice') as $id => $output) {
            $ids[$id] = $output->amount;
        }

        self::assertSame(['a-1' => 100, 'a-2' => 300], $ids);
    }

    public function test_iterate_owned_by_yields_nothing_for_unknown_owner(): void
    {
        $set = UnspentSet::fromOutputs(Output::ownedBy('alice', 100, 'a-1'));

        self::assertSame([], iterator_to_array($set->iterateOwnedBy('nobody')));
    }

    public function test_iterate_owned_by_skips_outputs_without_an_owner_lock(): void
    {
        $set = UnspentSet::fromOutputs(
            Output::open(100, 'open-1'),
            Output::ownedBy('alice', 200, 'a-1'),
        );

        self::assertSame(['a-1'], array_keys(iterator_to_array($set->iterateOwnedBy('alice'))));
    }

    public function test_iterate_owned_by_can_be_consumed_lazily_and_abandoned(): void
    {
        $outputs = [];
        for ($i = 0; $i < 50; ++$i) {
            $outputs[] = Output::ownedBy('alice', 10, "a-{$i}");
        }
        $set = UnspentSet::fromOutputs(...$outputs);

        $seen = 0;
        foreach ($set->iterateOwnedBy('alice') as $_) {
            ++$seen;
            if ($seen === 3) {
                break;
            }
        }

        self::assertSame(3, $seen);
        self::assertSame(50, $set->count());
    }
}
