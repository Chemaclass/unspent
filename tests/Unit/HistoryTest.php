<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit;

use Chemaclass\Unspent\CoinbaseTx;
use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\Tx;
use PHPUnit\Framework\TestCase;

final class HistoryTest extends TestCase
{
    // ========================================================================
    // outputCreatedBy Tests
    // ========================================================================

    public function test_genesis_outputs_created_by_genesis(): void
    {
        $ledger = Ledger::withGenesis(
            Output::open(1000, 'genesis-output'),
        );

        self::assertSame('genesis', $ledger->outputCreatedBy(new OutputId('genesis-output')));
    }

    public function test_spend_outputs_created_by_spend_id(): void
    {
        $ledger = Ledger::inMemory()
            ->addGenesis(Output::open(1000, 'genesis'))
            ->apply(Tx::create(
                spendIds: ['genesis'],
                outputs: [
                    Output::open(600, 'output-a'),
                    Output::open(400, 'output-b'),
                ],
                id: 'tx-001',
            ));

        self::assertSame('tx-001', $ledger->outputCreatedBy(new OutputId('output-a')));
        self::assertSame('tx-001', $ledger->outputCreatedBy(new OutputId('output-b')));
    }

    public function test_coinbase_outputs_created_by_coinbase_id(): void
    {
        $ledger = Ledger::inMemory()
            ->applyCoinbase(CoinbaseTx::create([
                Output::open(50, 'miner-reward'),
            ], 'block-1'));

        self::assertSame('block-1', $ledger->outputCreatedBy(new OutputId('miner-reward')));
    }

    public function test_created_by_returns_null_for_unknown_output(): void
    {
        $ledger = Ledger::withGenesis(
            Output::open(1000, 'genesis'),
        );

        self::assertNull($ledger->outputCreatedBy(new OutputId('nonexistent')));
    }

    // ========================================================================
    // outputSpentBy Tests
    // ========================================================================

    public function test_spent_output_returns_spend_id(): void
    {
        $ledger = Ledger::inMemory()
            ->addGenesis(Output::open(1000, 'alice-funds'))
            ->apply(Tx::create(
                spendIds: ['alice-funds'],
                outputs: [Output::open(1000, 'bob-funds')],
                id: 'tx-001',
            ));

        self::assertSame('tx-001', $ledger->outputSpentBy(new OutputId('alice-funds')));
    }

    public function test_unspent_output_returns_null(): void
    {
        $ledger = Ledger::withGenesis(
            Output::open(1000, 'alice-funds'),
        );

        self::assertNull($ledger->outputSpentBy(new OutputId('alice-funds')));
    }

    public function test_unknown_output_spent_by_returns_null(): void
    {
        $ledger = Ledger::inMemory();

        self::assertNull($ledger->outputSpentBy(new OutputId('nonexistent')));
    }

    public function test_multiple_inputs_tracked_separately(): void
    {
        $ledger = Ledger::inMemory()
            ->addGenesis(
                Output::open(500, 'alice-funds'),
                Output::open(300, 'bob-funds'),
            )
            ->apply(Tx::create(
                spendIds: ['alice-funds', 'bob-funds'],
                outputs: [Output::open(800, 'combined')],
                id: 'tx-combine',
            ));

        self::assertSame('tx-combine', $ledger->outputSpentBy(new OutputId('alice-funds')));
        self::assertSame('tx-combine', $ledger->outputSpentBy(new OutputId('bob-funds')));
    }

    // ========================================================================
    // getOutput Tests
    // ========================================================================

    public function test_get_unspent_output(): void
    {
        $ledger = Ledger::withGenesis(
            Output::open(1000, 'alice-funds'),
        );

        $output = $ledger->getOutput(new OutputId('alice-funds'));

        self::assertNotNull($output);
        self::assertSame(1000, $output->amount);
        self::assertSame('alice-funds', $output->id->value);
    }

    public function test_get_spent_output(): void
    {
        $ledger = Ledger::inMemory()
            ->addGenesis(Output::open(1000, 'alice-funds'))
            ->apply(Tx::create(
                spendIds: ['alice-funds'],
                outputs: [Output::open(1000, 'bob-funds')],
                id: 'tx-001',
            ));

        // alice-funds is spent but should still be retrievable
        $output = $ledger->getOutput(new OutputId('alice-funds'));

        self::assertNotNull($output);
        self::assertSame(1000, $output->amount);
        self::assertSame('alice-funds', $output->id->value);
    }

    public function test_get_nonexistent_output_returns_null(): void
    {
        $ledger = Ledger::inMemory();

        self::assertNull($ledger->getOutput(new OutputId('nonexistent')));
    }

    // ========================================================================
    // outputExists Tests
    // ========================================================================

    public function test_output_exists_for_unspent(): void
    {
        $ledger = Ledger::withGenesis(
            Output::open(1000, 'alice-funds'),
        );

        self::assertTrue($ledger->outputExists(new OutputId('alice-funds')));
    }

    public function test_output_exists_for_spent(): void
    {
        $ledger = Ledger::inMemory()
            ->addGenesis(Output::open(1000, 'alice-funds'))
            ->apply(Tx::create(
                spendIds: ['alice-funds'],
                outputs: [Output::open(1000, 'bob-funds')],
                id: 'tx-001',
            ));

        // alice-funds is spent but should still exist
        self::assertTrue($ledger->outputExists(new OutputId('alice-funds')));
    }

    public function test_output_not_exists_for_unknown(): void
    {
        $ledger = Ledger::inMemory();

        self::assertFalse($ledger->outputExists(new OutputId('nonexistent')));
    }

    // ========================================================================
    // outputHistory Tests
    // ========================================================================

    public function test_output_history_for_genesis_unspent(): void
    {
        $ledger = Ledger::withGenesis(
            Output::open(1000, 'alice-funds'),
        );

        $history = $ledger->outputHistory(new OutputId('alice-funds'));

        self::assertNotNull($history);
        self::assertSame('alice-funds', $history->id->value);
        self::assertSame(1000, $history->amount);
        self::assertSame('genesis', $history->createdBy);
        self::assertNull($history->spentBy);
        self::assertTrue($history->isUnspent());
    }

    public function test_output_history_for_spent_output(): void
    {
        $ledger = Ledger::inMemory()
            ->addGenesis(Output::open(1000, 'alice-funds'))
            ->apply(Tx::create(
                spendIds: ['alice-funds'],
                outputs: [Output::open(1000, 'bob-funds')],
                id: 'tx-001',
            ));

        $history = $ledger->outputHistory(new OutputId('alice-funds'));

        self::assertNotNull($history);
        self::assertSame('alice-funds', $history->id->value);
        self::assertSame(1000, $history->amount);
        self::assertSame('genesis', $history->createdBy);
        self::assertSame('tx-001', $history->spentBy);
        self::assertTrue($history->isSpent());
    }

    public function test_output_history_includes_lock_info(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 1000, 'alice-funds'),
        );

        $history = $ledger->outputHistory(new OutputId('alice-funds'));

        self::assertNotNull($history);
        self::assertSame(['type' => 'owner', 'name' => 'alice'], $history->lock->toArray());
    }

    public function test_output_history_returns_null_for_unknown(): void
    {
        $ledger = Ledger::inMemory();

        self::assertNull($ledger->outputHistory(new OutputId('nonexistent')));
    }

    // ========================================================================
    // Chain of Custody Tests
    // ========================================================================

    public function test_trace_output_through_multiple_transactions(): void
    {
        $ledger = Ledger::inMemory()
            ->addGenesis(Output::open(1000, 'genesis'))
            ->apply(Tx::create(
                spendIds: ['genesis'],
                outputs: [
                    Output::open(600, 'alice'),
                    Output::open(400, 'bob'),
                ],
                id: 'tx-001',
            ))
            ->apply(Tx::create(
                spendIds: ['alice'],
                outputs: [Output::open(600, 'charlie')],
                id: 'tx-002',
            ));

        // Genesis was spent in tx-001
        self::assertSame('genesis', $ledger->outputCreatedBy(new OutputId('genesis')));
        self::assertSame('tx-001', $ledger->outputSpentBy(new OutputId('genesis')));

        // Alice was created in tx-001, spent in tx-002
        self::assertSame('tx-001', $ledger->outputCreatedBy(new OutputId('alice')));
        self::assertSame('tx-002', $ledger->outputSpentBy(new OutputId('alice')));

        // Charlie was created in tx-002, still unspent
        self::assertSame('tx-002', $ledger->outputCreatedBy(new OutputId('charlie')));
        self::assertNull($ledger->outputSpentBy(new OutputId('charlie')));

        // Bob was created in tx-001, still unspent
        self::assertSame('tx-001', $ledger->outputCreatedBy(new OutputId('bob')));
        self::assertNull($ledger->outputSpentBy(new OutputId('bob')));
    }

    // ========================================================================
    // Serialization Tests
    // ========================================================================

    public function test_history_preserved_through_serialization(): void
    {
        $original = Ledger::inMemory()
            ->addGenesis(Output::open(1000, 'genesis'))
            ->apply(Tx::create(
                spendIds: ['genesis'],
                outputs: [Output::open(1000, 'alice')],
                id: 'tx-001',
            ));

        $restored = Ledger::fromJson($original->toJson());

        // Provenance preserved
        self::assertSame('genesis', $restored->outputCreatedBy(new OutputId('genesis')));
        self::assertSame('tx-001', $restored->outputSpentBy(new OutputId('genesis')));
        self::assertSame('tx-001', $restored->outputCreatedBy(new OutputId('alice')));

        // Spent output still accessible
        $spentOutput = $restored->getOutput(new OutputId('genesis'));
        self::assertNotNull($spentOutput);
        self::assertSame(1000, $spentOutput->amount);
    }

    public function test_history_preserved_through_array_serialization(): void
    {
        $original = Ledger::inMemory()
            ->addGenesis(Output::open(1000, 'genesis'))
            ->apply(Tx::create(
                spendIds: ['genesis'],
                outputs: [Output::open(1000, 'alice')],
                id: 'tx-001',
            ));

        $restored = Ledger::fromArray($original->toArray());

        self::assertSame('genesis', $restored->outputCreatedBy(new OutputId('genesis')));
        self::assertSame('tx-001', $restored->outputSpentBy(new OutputId('genesis')));
        self::assertTrue($restored->outputExists(new OutputId('genesis')));
    }

    // ========================================================================
    // Immutability Tests
    // ========================================================================

    public function test_history_tracks_across_mutable_ledger(): void
    {
        $ledger = Ledger::withGenesis(Output::open(1000, 'genesis'));

        // Initially genesis is unspent
        self::assertSame('genesis', $ledger->outputCreatedBy(new OutputId('genesis')));
        self::assertNull($ledger->outputSpentBy(new OutputId('genesis')));
        self::assertNull($ledger->outputCreatedBy(new OutputId('alice')));

        $ledger->apply(Tx::create(
            spendIds: ['genesis'],
            outputs: [Output::open(1000, 'alice')],
            id: 'tx-001',
        ));

        // After applying, genesis is spent
        self::assertSame('genesis', $ledger->outputCreatedBy(new OutputId('genesis')));
        self::assertSame('tx-001', $ledger->outputSpentBy(new OutputId('genesis')));

        // And alice is created
        self::assertSame('tx-001', $ledger->outputCreatedBy(new OutputId('alice')));
    }
}
