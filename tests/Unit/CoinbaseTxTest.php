<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit;

use Chemaclass\Unspent\CoinbaseTx;
use Chemaclass\Unspent\Exception\DuplicateOutputIdException;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\TxId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CoinbaseTxTest extends TestCase
{
    public function test_can_create_coinbase_with_outputs(): void
    {
        $coinbase = new CoinbaseTx(
            id: new TxId('block-1'),
            outputs: [
                Output::open(50, 'reward-1'),
                Output::open(25, 'reward-2'),
            ],
        );

        self::assertSame('block-1', $coinbase->id->value);
        self::assertCount(2, $coinbase->outputs);
    }

    public function test_coinbase_must_have_at_least_one_output(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CoinbaseTx must have at least one output');

        new CoinbaseTx(
            id: new TxId('block-1'),
            outputs: [],
        );
    }

    public function test_coinbase_outputs_must_have_unique_ids(): void
    {
        $this->expectException(DuplicateOutputIdException::class);
        $this->expectExceptionMessage("Duplicate output id: 'reward'");

        new CoinbaseTx(
            id: new TxId('block-1'),
            outputs: [
                Output::open(50, 'reward'),
                Output::open(25, 'reward'),
            ],
        );
    }

    public function test_coinbase_calculates_total_output_amount(): void
    {
        $coinbase = new CoinbaseTx(
            id: new TxId('block-1'),
            outputs: [
                Output::open(50, 'reward-1'),
                Output::open(25, 'reward-2'),
                Output::open(10, 'reward-3'),
            ],
        );

        self::assertSame(85, $coinbase->totalOutputAmount());
    }

    public function test_create_factory_method(): void
    {
        $coinbase = CoinbaseTx::create([
            Output::open(50, 'reward-1'),
            Output::open(25, 'reward-2'),
        ], 'block-1');

        self::assertSame('block-1', $coinbase->id->value);
        self::assertCount(2, $coinbase->outputs);
        self::assertSame(75, $coinbase->totalOutputAmount());
    }

    public function test_create_with_auto_generated_id(): void
    {
        $coinbase = CoinbaseTx::create([
            Output::open(50, 'reward-1'),
        ]);

        self::assertSame(32, \strlen($coinbase->id->value));
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $coinbase->id->value);
    }

    public function test_same_outputs_generate_same_id(): void
    {
        $coinbase1 = CoinbaseTx::create([
            Output::open(50, 'reward-1'),
            Output::open(25, 'reward-2'),
        ]);

        $coinbase2 = CoinbaseTx::create([
            Output::open(50, 'reward-1'),
            Output::open(25, 'reward-2'),
        ]);

        self::assertSame($coinbase1->id->value, $coinbase2->id->value);
    }
}
