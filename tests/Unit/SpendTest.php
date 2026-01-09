<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit;

use Chemaclass\Unspent\Exception\DuplicateOutputIdException;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\Spend;
use Chemaclass\Unspent\SpendId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SpendTest extends TestCase
{
    public function test_can_be_created_with_inputs_and_outputs(): void
    {
        $spend = new Spend(
            id: new SpendId('tx1'),
            inputs: [new OutputId('a'), new OutputId('b')],
            outputs: [
                new Output(new OutputId('c'), 100),
                new Output(new OutputId('d'), 50),
            ],
        );

        self::assertSame('tx1', $spend->id->value);
        self::assertCount(2, $spend->inputs);
        self::assertCount(2, $spend->outputs);
    }

    public function test_must_have_at_least_one_input(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Spend must have at least one input');

        new Spend(
            id: new SpendId('tx1'),
            inputs: [],
            outputs: [new Output(new OutputId('c'), 100)],
        );
    }

    public function test_must_have_at_least_one_output(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Spend must have at least one output');

        new Spend(
            id: new SpendId('tx1'),
            inputs: [new OutputId('a')],
            outputs: [],
        );
    }

    public function test_total_input_amount_returns_zero_without_unspent_set(): void
    {
        $spend = new Spend(
            id: new SpendId('tx1'),
            inputs: [new OutputId('a')],
            outputs: [new Output(new OutputId('c'), 100)],
        );

        self::assertCount(1, $spend->inputs);
    }

    public function test_total_output_amount(): void
    {
        $spend = new Spend(
            id: new SpendId('tx1'),
            inputs: [new OutputId('a')],
            outputs: [
                new Output(new OutputId('c'), 100),
                new Output(new OutputId('d'), 50),
            ],
        );

        self::assertSame(150, $spend->totalOutputAmount());
    }

    public function test_outputs_must_have_unique_ids(): void
    {
        $this->expectException(DuplicateOutputIdException::class);
        $this->expectExceptionMessage("Duplicate output id: 'c'");

        new Spend(
            id: new SpendId('tx1'),
            inputs: [new OutputId('a')],
            outputs: [
                new Output(new OutputId('c'), 50),
                new Output(new OutputId('c'), 50),
            ],
        );
    }

    public function test_inputs_must_have_unique_ids(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Duplicate input id: 'a'");

        new Spend(
            id: new SpendId('tx1'),
            inputs: [new OutputId('a'), new OutputId('a')],
            outputs: [new Output(new OutputId('c'), 100)],
        );
    }

    public function test_create_factory_method(): void
    {
        $spend = Spend::create(
            inputIds: ['a', 'b'],
            outputs: [
                Output::create(100, 'c'),
                Output::create(50, 'd'),
            ],
            id: 'tx1',
        );

        self::assertSame('tx1', $spend->id->value);
        self::assertCount(2, $spend->inputs);
        self::assertSame('a', $spend->inputs[0]->value);
        self::assertSame('b', $spend->inputs[1]->value);
        self::assertCount(2, $spend->outputs);
        self::assertSame(150, $spend->totalOutputAmount());
    }

    public function test_create_with_auto_generated_id(): void
    {
        $spend = Spend::create(
            inputIds: ['a', 'b'],
            outputs: [
                Output::create(100, 'c'),
                Output::create(50, 'd'),
            ],
        );

        self::assertSame(32, strlen($spend->id->value));
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $spend->id->value);
    }

    public function test_same_content_generates_same_id(): void
    {
        $spend1 = Spend::create(
            inputIds: ['a', 'b'],
            outputs: [
                Output::create(100, 'c'),
                Output::create(50, 'd'),
            ],
        );

        $spend2 = Spend::create(
            inputIds: ['a', 'b'],
            outputs: [
                Output::create(100, 'c'),
                Output::create(50, 'd'),
            ],
        );

        self::assertSame($spend1->id->value, $spend2->id->value);
    }

    public function test_different_content_generates_different_id(): void
    {
        $spend1 = Spend::create(
            inputIds: ['a'],
            outputs: [Output::create(100, 'c')],
        );

        $spend2 = Spend::create(
            inputIds: ['b'],
            outputs: [Output::create(100, 'c')],
        );

        self::assertNotSame($spend1->id->value, $spend2->id->value);
    }
}
