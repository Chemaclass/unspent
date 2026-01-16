<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit;

use Chemaclass\Unspent\IdGenerator;
use Chemaclass\Unspent\Output;
use PHPUnit\Framework\TestCase;

final class IdGeneratorTest extends TestCase
{
    public function test_for_tx_is_deterministic(): void
    {
        $spendIds = ['spend1', 'spend2'];
        $outputs = [
            Output::open(100, 'output1'),
            Output::open(50, 'output2'),
        ];

        $id1 = IdGenerator::forTx($spendIds, $outputs);
        $id2 = IdGenerator::forTx($spendIds, $outputs);

        self::assertSame($id1, $id2);
    }

    public function test_for_tx_different_spends_produce_different_ids(): void
    {
        $outputs = [Output::open(100, 'output1')];

        $id1 = IdGenerator::forTx(['spend1'], $outputs);
        $id2 = IdGenerator::forTx(['spend2'], $outputs);

        self::assertNotSame($id1, $id2);
    }

    public function test_for_tx_different_outputs_produce_different_ids(): void
    {
        $spendIds = ['spend1'];

        $id1 = IdGenerator::forTx($spendIds, [Output::open(100, 'output1')]);
        $id2 = IdGenerator::forTx($spendIds, [Output::open(100, 'output2')]);

        self::assertNotSame($id1, $id2);
    }

    public function test_for_tx_returns_32_hex_characters(): void
    {
        $id = IdGenerator::forTx(['spend1'], [Output::open(100, 'output1')]);

        self::assertSame(32, \strlen($id));
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $id);
    }

    public function test_for_tx_with_empty_arrays(): void
    {
        $id1 = IdGenerator::forTx([], []);
        $id2 = IdGenerator::forTx([], []);

        self::assertSame($id1, $id2);
        self::assertSame(32, \strlen($id1));
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $id1);
    }

    public function test_for_coinbase_is_deterministic(): void
    {
        $outputs = [
            Output::open(50, 'reward1'),
            Output::open(25, 'reward2'),
        ];

        $id1 = IdGenerator::forCoinbase($outputs);
        $id2 = IdGenerator::forCoinbase($outputs);

        self::assertSame($id1, $id2);
    }

    public function test_for_coinbase_different_outputs_produce_different_ids(): void
    {
        $id1 = IdGenerator::forCoinbase([Output::open(50, 'reward1')]);
        $id2 = IdGenerator::forCoinbase([Output::open(50, 'reward2')]);

        self::assertNotSame($id1, $id2);
    }

    public function test_for_coinbase_returns_32_hex_characters(): void
    {
        $id = IdGenerator::forCoinbase([Output::open(50, 'reward1')]);

        self::assertSame(32, \strlen($id));
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $id);
    }

    public function test_for_coinbase_with_empty_array(): void
    {
        $id1 = IdGenerator::forCoinbase([]);
        $id2 = IdGenerator::forCoinbase([]);

        self::assertSame($id1, $id2);
        self::assertSame(32, \strlen($id1));
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $id1);
    }

    public function test_for_output_is_random(): void
    {
        $id1 = IdGenerator::forOutput(100);
        $id2 = IdGenerator::forOutput(100);

        self::assertNotSame($id1, $id2);
    }

    public function test_for_output_returns_32_hex_characters(): void
    {
        $id = IdGenerator::forOutput(100);

        self::assertSame(32, \strlen($id));
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $id);
    }

    public function test_for_output_produces_unique_ids_in_bulk(): void
    {
        $ids = [];
        for ($i = 0; $i < 100; ++$i) {
            $ids[] = IdGenerator::forOutput(100);
        }

        $uniqueIds = array_unique($ids);

        self::assertCount(100, $uniqueIds);
    }

    public function test_for_tx_and_coinbase_produce_different_ids_for_same_outputs(): void
    {
        $outputs = [Output::open(50, 'output1')];

        $txId = IdGenerator::forTx([], $outputs);
        $coinbaseId = IdGenerator::forCoinbase($outputs);

        self::assertNotSame($txId, $coinbaseId);
    }

    public function test_for_tx_order_of_spends_matters(): void
    {
        $outputs = [Output::open(100, 'output1')];

        $id1 = IdGenerator::forTx(['spend1', 'spend2'], $outputs);
        $id2 = IdGenerator::forTx(['spend2', 'spend1'], $outputs);

        self::assertNotSame($id1, $id2);
    }

    public function test_for_tx_order_of_outputs_matters(): void
    {
        $spendIds = ['spend1'];
        $output1 = Output::open(100, 'a');
        $output2 = Output::open(50, 'b');

        $id1 = IdGenerator::forTx($spendIds, [$output1, $output2]);
        $id2 = IdGenerator::forTx($spendIds, [$output2, $output1]);

        self::assertNotSame($id1, $id2);
    }
}
