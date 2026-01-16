<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit;

use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\UnspentSet;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class UnspentSetTest extends TestCase
{
    public function test_empty_set_has_zero_total(): void
    {
        $set = UnspentSet::empty();

        self::assertSame(0, $set->totalAmount());
    }

    public function test_empty_set_is_empty(): void
    {
        $set = UnspentSet::empty();

        self::assertTrue($set->isEmpty());
        self::assertSame(0, $set->count());
    }

    public function test_can_add_outputs(): void
    {
        $output1 = Output::open(100, 'a');
        $output2 = Output::open(50, 'b');

        $set = UnspentSet::empty()
            ->add($output1)
            ->add($output2);

        self::assertSame(150, $set->totalAmount());
        self::assertSame(2, $set->count());
    }

    public function test_can_check_if_contains_output_id(): void
    {
        $output = Output::open(100, 'a');
        $set = UnspentSet::empty()->add($output);

        self::assertTrue($set->contains(new OutputId('a')));
        self::assertFalse($set->contains(new OutputId('b')));
    }

    public function test_can_get_output_by_id(): void
    {
        $output = Output::open(100, 'a');
        $set = UnspentSet::empty()->add($output);

        self::assertSame($output, $set->get(new OutputId('a')));
        self::assertNull($set->get(new OutputId('b')));
    }

    public function test_can_remove_output_by_id(): void
    {
        $output1 = Output::open(100, 'a');
        $output2 = Output::open(50, 'b');

        $set = UnspentSet::empty()
            ->add($output1)
            ->add($output2)
            ->remove(new OutputId('a'));

        self::assertFalse($set->contains(new OutputId('a')));
        self::assertTrue($set->contains(new OutputId('b')));
        self::assertSame(50, $set->totalAmount());
    }

    public function test_is_iterable(): void
    {
        $output1 = Output::open(100, 'a');
        $output2 = Output::open(50, 'b');

        $set = UnspentSet::empty()->add($output1)->add($output2);

        $outputs = iterator_to_array($set);

        self::assertCount(2, $outputs);
        self::assertSame($output1, $outputs['a']);
        self::assertSame($output2, $outputs['b']);
    }

    public function test_returns_all_output_ids(): void
    {
        $output1 = Output::open(100, 'a');
        $output2 = Output::open(50, 'b');

        $set = UnspentSet::empty()->add($output1)->add($output2);

        $ids = $set->outputIds();

        self::assertCount(2, $ids);
    }

    public function test_can_add_multiple_outputs_at_once(): void
    {
        $output1 = Output::open(100, 'a');
        $output2 = Output::open(50, 'b');
        $output3 = Output::open(25, 'c');

        $set = UnspentSet::empty()->addAll($output1, $output2, $output3);

        self::assertSame(175, $set->totalAmount());
        self::assertSame(3, $set->count());
    }

    public function test_can_remove_multiple_outputs_at_once(): void
    {
        $output1 = Output::open(100, 'a');
        $output2 = Output::open(50, 'b');
        $output3 = Output::open(25, 'c');

        $set = UnspentSet::empty()
            ->addAll($output1, $output2, $output3)
            ->removeAll(new OutputId('a'), new OutputId('c'));

        self::assertSame(50, $set->totalAmount());
        self::assertSame(1, $set->count());
        self::assertTrue($set->contains(new OutputId('b')));
    }

    public function test_can_create_from_outputs(): void
    {
        $output1 = Output::open(100, 'a');
        $output2 = Output::open(50, 'b');

        $set = UnspentSet::fromOutputs($output1, $output2);

        self::assertSame(150, $set->totalAmount());
        self::assertSame(2, $set->count());
    }

    // ========================================================================
    // Serialization Tests
    // ========================================================================

    public function test_can_serialize_to_array(): void
    {
        $set = UnspentSet::fromOutputs(
            Output::open(100, 'a'),
            Output::open(50, 'b'),
        );

        $array = $set->toArray();

        self::assertCount(2, $array);
        self::assertArrayHasKey('a', $array);
        self::assertArrayHasKey('b', $array);
        self::assertSame(['amount' => 100, 'lock' => ['type' => 'none']], $array['a']);
        self::assertSame(['amount' => 50, 'lock' => ['type' => 'none']], $array['b']);
    }

    public function test_can_deserialize_from_array(): void
    {
        $data = [
            'a' => ['amount' => 100, 'lock' => ['type' => 'none']],
            'b' => ['amount' => 50, 'lock' => ['type' => 'owner', 'name' => 'alice']],
        ];

        $set = UnspentSet::fromArray($data);

        self::assertSame(150, $set->totalAmount());
        self::assertSame(2, $set->count());
        self::assertTrue($set->contains(new OutputId('a')));
        self::assertTrue($set->contains(new OutputId('b')));
    }

    public function test_serialization_round_trip(): void
    {
        $original = UnspentSet::fromOutputs(
            Output::open(1000, 'x'),
            Output::open(500, 'y'),
            Output::open(250, 'z'),
        );

        $restored = UnspentSet::fromArray($original->toArray());

        self::assertSame($original->totalAmount(), $restored->totalAmount());
        self::assertSame($original->count(), $restored->count());

        foreach ($original->outputIds() as $id) {
            self::assertTrue($restored->contains($id));
            self::assertSame(
                $original->get($id)?->amount,
                $restored->get($id)?->amount,
            );
        }
    }

    public function test_empty_set_serialization(): void
    {
        $empty = UnspentSet::empty();

        $array = $empty->toArray();
        self::assertSame([], $array);

        $restored = UnspentSet::fromArray([]);
        self::assertTrue($restored->isEmpty());
        self::assertSame(0, $restored->totalAmount());
    }

    // ========================================================================
    // Copy-on-Fork Semantics Tests
    // ========================================================================

    public function test_release_enables_forking_behavior_on_add(): void
    {
        $output1 = Output::open(100, 'a');
        $set = UnspentSet::fromOutputs($output1);
        $set->release();

        $output2 = Output::open(50, 'b');
        $newSet = $set->add($output2);

        // Original set unchanged, new set has both
        self::assertSame(1, $set->count());
        self::assertSame(2, $newSet->count());
    }

    public function test_release_enables_forking_behavior_on_remove(): void
    {
        $output = Output::open(100, 'a');
        $set = UnspentSet::fromOutputs($output);
        $set->release();

        $newSet = $set->remove(new OutputId('a'));

        // Original unchanged, new set is empty
        self::assertSame(1, $set->count());
        self::assertTrue($newSet->isEmpty());
    }

    public function test_add_replaces_output_with_same_id(): void
    {
        $output1 = new Output(new OutputId('shared'), 100, new \Chemaclass\Unspent\Lock\Owner('alice'));
        $output2 = new Output(new OutputId('shared'), 200, new \Chemaclass\Unspent\Lock\Owner('bob'));

        $set = UnspentSet::fromOutputs($output1)->add($output2);

        self::assertSame(1, $set->count());
        self::assertSame(200, $set->totalAmount());
    }

    public function test_add_all_with_empty_array_returns_same_instance(): void
    {
        $set = UnspentSet::empty();

        $result = $set->addAll();

        self::assertSame($set, $result);
    }

    public function test_add_all_forks_when_released(): void
    {
        $set = UnspentSet::fromOutputs(Output::open(100, 'a'));
        $set->release();

        $newSet = $set->addAll(Output::open(50, 'b'));

        self::assertNotSame($set, $newSet);
        self::assertSame(1, $set->count());
        self::assertSame(2, $newSet->count());
    }

    public function test_add_all_updates_total_when_replacing(): void
    {
        $set = UnspentSet::fromOutputs(
            new Output(new OutputId('x'), 100, new \Chemaclass\Unspent\Lock\NoLock()),
        );

        $set = $set->addAll(
            new Output(new OutputId('x'), 300, new \Chemaclass\Unspent\Lock\NoLock()),
        );

        self::assertSame(300, $set->totalAmount());
    }

    public function test_remove_nonexistent_id_returns_same_instance(): void
    {
        $set = UnspentSet::fromOutputs(Output::open(100, 'a'));

        $result = $set->remove(new OutputId('nonexistent'));

        self::assertSame($set, $result);
    }

    public function test_remove_all_with_empty_array_returns_same_instance(): void
    {
        $set = UnspentSet::fromOutputs(Output::open(100, 'a'));

        $result = $set->removeAll();

        self::assertSame($set, $result);
    }

    public function test_remove_all_forks_when_released(): void
    {
        $set = UnspentSet::fromOutputs(
            Output::open(100, 'a'),
            Output::open(50, 'b'),
        );
        $set->release();

        $newSet = $set->removeAll(new OutputId('a'));

        self::assertNotSame($set, $newSet);
        self::assertSame(2, $set->count());
        self::assertSame(1, $newSet->count());
    }

    public function test_remove_all_ignores_nonexistent_ids(): void
    {
        $set = UnspentSet::fromOutputs(Output::open(100, 'a'));

        $result = $set->removeAll(new OutputId('nonexistent'));

        self::assertSame(1, $result->count());
        self::assertSame(100, $result->totalAmount());
    }

    // ========================================================================
    // Filter and Owner Query Tests
    // ========================================================================

    public function test_filter_returns_matching_outputs(): void
    {
        $set = UnspentSet::fromOutputs(
            Output::open(100, 'a'),
            Output::open(50, 'b'),
            Output::open(200, 'c'),
        );

        $filtered = $set->filter(static fn (Output $o): bool => $o->amount >= 100);

        self::assertSame(2, $filtered->count());
        self::assertSame(300, $filtered->totalAmount());
    }

    public function test_filter_returns_empty_set_when_no_match(): void
    {
        $set = UnspentSet::fromOutputs(Output::open(100, 'a'));

        $filtered = $set->filter(static fn (): bool => false);

        self::assertTrue($filtered->isEmpty());
        self::assertSame(0, $filtered->totalAmount());
    }

    public function test_owned_by_returns_outputs_with_owner_lock(): void
    {
        $set = UnspentSet::fromOutputs(
            Output::ownedBy('alice', 100, 'a'),
            Output::ownedBy('bob', 50, 'b'),
            Output::ownedBy('alice', 30, 'c'),
            Output::open(20, 'd'),
        );

        $aliceOutputs = $set->ownedBy('alice');

        self::assertSame(2, $aliceOutputs->count());
        self::assertSame(130, $aliceOutputs->totalAmount());
    }

    public function test_owned_by_returns_empty_for_open_outputs(): void
    {
        $set = UnspentSet::fromOutputs(Output::open(100, 'a'));

        $result = $set->ownedBy('alice');

        self::assertTrue($result->isEmpty());
    }

    public function test_total_amount_owned_by_returns_sum(): void
    {
        $set = UnspentSet::fromOutputs(
            Output::ownedBy('alice', 100, 'a'),
            Output::ownedBy('alice', 50, 'b'),
            Output::ownedBy('bob', 30, 'c'),
        );

        self::assertSame(150, $set->totalAmountOwnedBy('alice'));
        self::assertSame(30, $set->totalAmountOwnedBy('bob'));
        self::assertSame(0, $set->totalAmountOwnedBy('charlie'));
    }

    // ========================================================================
    // Deserialization Error Tests
    // ========================================================================

    public function test_from_array_throws_when_lock_data_missing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Output 'a' missing lock data");

        UnspentSet::fromArray([
            'a' => ['amount' => 100],
        ]);
    }
}
