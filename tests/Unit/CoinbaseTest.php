<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit;

use Chemaclass\Unspent\Coinbase;
use Chemaclass\Unspent\Exception\DuplicateOutputIdException;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\SpendId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CoinbaseTest extends TestCase
{
    public function test_can_create_coinbase_with_outputs(): void
    {
        $coinbase = new Coinbase(
            id: new SpendId('block-1'),
            outputs: [
                new Output(new OutputId('reward-1'), 50),
                new Output(new OutputId('reward-2'), 25),
            ],
        );

        self::assertSame('block-1', $coinbase->id->value);
        self::assertCount(2, $coinbase->outputs);
    }

    public function test_coinbase_must_have_at_least_one_output(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Coinbase must have at least one output');

        new Coinbase(
            id: new SpendId('block-1'),
            outputs: [],
        );
    }

    public function test_coinbase_outputs_must_have_unique_ids(): void
    {
        $this->expectException(DuplicateOutputIdException::class);
        $this->expectExceptionMessage("Duplicate output id: 'reward'");

        new Coinbase(
            id: new SpendId('block-1'),
            outputs: [
                new Output(new OutputId('reward'), 50),
                new Output(new OutputId('reward'), 25),
            ],
        );
    }

    public function test_coinbase_calculates_total_output_amount(): void
    {
        $coinbase = new Coinbase(
            id: new SpendId('block-1'),
            outputs: [
                new Output(new OutputId('reward-1'), 50),
                new Output(new OutputId('reward-2'), 25),
                new Output(new OutputId('reward-3'), 10),
            ],
        );

        self::assertSame(85, $coinbase->totalOutputAmount());
    }

    public function test_create_factory_method(): void
    {
        $coinbase = Coinbase::create('block-1', [
            Output::create('reward-1', 50),
            Output::create('reward-2', 25),
        ]);

        self::assertSame('block-1', $coinbase->id->value);
        self::assertCount(2, $coinbase->outputs);
        self::assertSame(75, $coinbase->totalOutputAmount());
    }
}
