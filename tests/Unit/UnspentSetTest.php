<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit;

use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\UnspentSet;
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
        self::assertContains(['id' => 'a', 'amount' => 100, 'lock' => ['type' => 'none']], $array);
        self::assertContains(['id' => 'b', 'amount' => 50, 'lock' => ['type' => 'none']], $array);
    }

    public function test_can_deserialize_from_array(): void
    {
        $data = [
            ['id' => 'a', 'amount' => 100, 'lock' => ['type' => 'none']],
            ['id' => 'b', 'amount' => 50, 'lock' => ['type' => 'owner', 'name' => 'alice']],
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
}
