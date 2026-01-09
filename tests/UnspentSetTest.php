<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests;

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
        $output1 = new Output(new OutputId('a'), 100);
        $output2 = new Output(new OutputId('b'), 50);

        $set = UnspentSet::empty()
            ->add($output1)
            ->add($output2);

        self::assertSame(150, $set->totalAmount());
        self::assertSame(2, $set->count());
    }

    public function test_can_check_if_contains_output_id(): void
    {
        $output = new Output(new OutputId('a'), 100);
        $set = UnspentSet::empty()->add($output);

        self::assertTrue($set->contains(new OutputId('a')));
        self::assertFalse($set->contains(new OutputId('b')));
    }

    public function test_can_get_output_by_id(): void
    {
        $output = new Output(new OutputId('a'), 100);
        $set = UnspentSet::empty()->add($output);

        self::assertSame($output, $set->get(new OutputId('a')));
        self::assertNull($set->get(new OutputId('b')));
    }

    public function test_can_remove_output_by_id(): void
    {
        $output1 = new Output(new OutputId('a'), 100);
        $output2 = new Output(new OutputId('b'), 50);

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
        $output1 = new Output(new OutputId('a'), 100);
        $output2 = new Output(new OutputId('b'), 50);

        $set = UnspentSet::empty()->add($output1)->add($output2);

        $outputs = iterator_to_array($set);

        self::assertCount(2, $outputs);
        self::assertSame($output1, $outputs['a']);
        self::assertSame($output2, $outputs['b']);
    }

    public function test_returns_all_output_ids(): void
    {
        $output1 = new Output(new OutputId('a'), 100);
        $output2 = new Output(new OutputId('b'), 50);

        $set = UnspentSet::empty()->add($output1)->add($output2);

        $ids = $set->outputIds();

        self::assertCount(2, $ids);
    }
}
