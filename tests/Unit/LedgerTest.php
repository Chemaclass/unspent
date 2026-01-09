<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit;

use Chemaclass\Unspent\Coinbase;
use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Lock\NoLock;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\Spend;
use Chemaclass\Unspent\SpendId;
use Chemaclass\Unspent\Exception\DuplicateOutputIdException;
use Chemaclass\Unspent\Exception\DuplicateSpendException;
use Chemaclass\Unspent\Exception\GenesisNotAllowedException;
use Chemaclass\Unspent\Exception\InsufficientInputsException;
use Chemaclass\Unspent\Exception\OutputAlreadySpentException;
use PHPUnit\Framework\TestCase;

final class LedgerTest extends TestCase
{
    public function test_empty_ledger_has_zero_unspent(): void
    {
        $ledger = Ledger::empty();

        self::assertSame(0, $ledger->totalUnspentAmount());
        self::assertTrue($ledger->unspent()->isEmpty());
    }

    public function test_can_add_genesis_outputs(): void
    {
        $output1 = Output::open(100, 'genesis-1');
        $output2 = Output::open(50, 'genesis-2');

        $ledger = Ledger::empty()->addGenesis($output1, $output2);

        self::assertSame(150, $ledger->totalUnspentAmount());
        self::assertSame(2, $ledger->unspent()->count());
    }

    public function test_genesis_only_allowed_on_empty_ledger(): void
    {
        $this->expectException(GenesisNotAllowedException::class);
        $this->expectExceptionMessage('Genesis outputs can only be added to an empty ledger');

        $ledger = Ledger::empty()
            ->addGenesis(Output::open(100, 'a'))
            ->addGenesis(Output::open(50, 'b'));
    }

    public function test_genesis_fails_on_duplicate_output_ids(): void
    {
        $this->expectException(DuplicateOutputIdException::class);
        $this->expectExceptionMessage("Duplicate output id: 'a'");

        Ledger::empty()->addGenesis(
            Output::open(100, 'a'),
            Output::open(50, 'a'),
        );
    }

    public function test_apply_spend_happy_path(): void
    {
        $ledger = Ledger::empty()
            ->addGenesis(
                Output::open(100, 'a'),
                Output::open(50, 'b'),
            )
            ->apply(new Spend(
                id: new SpendId('tx1'),
                inputs: [new OutputId('a')],
                outputs: [
                    Output::open(60, 'c'),
                    Output::open(40, 'd'),
                ],
            ));

        self::assertSame(150, $ledger->totalUnspentAmount());
        self::assertFalse($ledger->unspent()->contains(new OutputId('a')));
        self::assertTrue($ledger->unspent()->contains(new OutputId('b')));
        self::assertTrue($ledger->unspent()->contains(new OutputId('c')));
        self::assertTrue($ledger->unspent()->contains(new OutputId('d')));
    }

    public function test_apply_spend_fails_when_input_not_in_unspent_set(): void
    {
        $this->expectException(OutputAlreadySpentException::class);
        $this->expectExceptionMessage("Output 'nonexistent' is not in the unspent set");

        Ledger::empty()
            ->addGenesis(Output::open(100, 'a'))
            ->apply(new Spend(
                id: new SpendId('tx1'),
                inputs: [new OutputId('nonexistent')],
                outputs: [Output::open(100, 'b')],
            ));
    }

    public function test_apply_spend_fails_when_outputs_exceed_inputs(): void
    {
        $this->expectException(InsufficientInputsException::class);
        $this->expectExceptionMessage('Insufficient inputs: input amount (100) is less than output amount (150)');

        Ledger::empty()
            ->addGenesis(Output::open(100, 'a'))
            ->apply(new Spend(
                id: new SpendId('tx1'),
                inputs: [new OutputId('a')],
                outputs: [Output::open(150, 'b')],
            ));
    }

    public function test_apply_same_spend_twice_fails(): void
    {
        $this->expectException(DuplicateSpendException::class);
        $this->expectExceptionMessage("Spend 'tx1' has already been applied");

        $spend1 = new Spend(
            id: new SpendId('tx1'),
            inputs: [new OutputId('a')],
            outputs: [Output::open(100, 'b')],
        );

        $spend2 = new Spend(
            id: new SpendId('tx1'),
            inputs: [new OutputId('b')],
            outputs: [Output::open(100, 'c')],
        );

        Ledger::empty()
            ->addGenesis(Output::open(100, 'a'))
            ->apply($spend1)
            ->apply($spend2);
    }

    public function test_output_can_only_be_spent_once(): void
    {
        $this->expectException(OutputAlreadySpentException::class);
        $this->expectExceptionMessage("Output 'a' is not in the unspent set");

        $ledger = Ledger::empty()
            ->addGenesis(Output::open(100, 'a'))
            ->apply(new Spend(
                id: new SpendId('tx1'),
                inputs: [new OutputId('a')],
                outputs: [Output::open(100, 'b')],
            ));

        $ledger->apply(new Spend(
            id: new SpendId('tx2'),
            inputs: [new OutputId('a')],
            outputs: [Output::open(100, 'c')],
        ));
    }

    public function test_spend_output_ids_must_be_unique(): void
    {
        $this->expectException(DuplicateOutputIdException::class);
        $this->expectExceptionMessage("Duplicate output id: 'c'");

        Ledger::empty()
            ->addGenesis(Output::open(100, 'a'))
            ->apply(new Spend(
                id: new SpendId('tx1'),
                inputs: [new OutputId('a')],
                outputs: [
                    Output::open(50, 'c'),
                    Output::open(50, 'c'),
                ],
            ));
    }

    public function test_spend_output_id_cannot_conflict_with_existing_unspent(): void
    {
        $this->expectException(DuplicateOutputIdException::class);
        $this->expectExceptionMessage("Duplicate output id: 'b'");

        Ledger::empty()
            ->addGenesis(
                Output::open(100, 'a'),
                Output::open(50, 'b'),
            )
            ->apply(new Spend(
                id: new SpendId('tx1'),
                inputs: [new OutputId('a')],
                outputs: [Output::open(100, 'b')],
            ));
    }

    public function test_multiple_spends_in_sequence(): void
    {
        $ledger = Ledger::empty()
            ->addGenesis(Output::open(1000, 'genesis'))
            ->apply(new Spend(
                id: new SpendId('tx1'),
                inputs: [new OutputId('genesis')],
                outputs: [
                    Output::open(600, 'a'),
                    Output::open(400, 'b'),
                ],
            ))
            ->apply(new Spend(
                id: new SpendId('tx2'),
                inputs: [new OutputId('a')],
                outputs: [
                    Output::open(300, 'c'),
                    Output::open(300, 'd'),
                ],
            ));

        self::assertSame(1000, $ledger->totalUnspentAmount());
        self::assertSame(3, $ledger->unspent()->count());
        self::assertTrue($ledger->unspent()->contains(new OutputId('b')));
        self::assertTrue($ledger->unspent()->contains(new OutputId('c')));
        self::assertTrue($ledger->unspent()->contains(new OutputId('d')));
    }

    public function test_spend_with_multiple_inputs(): void
    {
        $ledger = Ledger::empty()
            ->addGenesis(
                Output::open(100, 'a'),
                Output::open(50, 'b'),
            )
            ->apply(new Spend(
                id: new SpendId('tx1'),
                inputs: [new OutputId('a'), new OutputId('b')],
                outputs: [Output::open(150, 'c')],
            ));

        self::assertSame(150, $ledger->totalUnspentAmount());
        self::assertSame(1, $ledger->unspent()->count());
        self::assertTrue($ledger->unspent()->contains(new OutputId('c')));
    }

    public function test_can_query_if_spend_has_been_applied(): void
    {
        $ledger = Ledger::empty()
            ->addGenesis(Output::open(100, 'a'));

        self::assertFalse($ledger->hasSpendBeenApplied(new SpendId('tx1')));

        $ledger = $ledger->apply(new Spend(
            id: new SpendId('tx1'),
            inputs: [new OutputId('a')],
            outputs: [Output::open(100, 'b')],
        ));

        self::assertTrue($ledger->hasSpendBeenApplied(new SpendId('tx1')));
        self::assertFalse($ledger->hasSpendBeenApplied(new SpendId('tx2')));
    }

    // ========================================================================
    // Fee Tests (Bitcoin-style implicit fees)
    // ========================================================================

    public function test_fee_calculated_when_inputs_exceed_outputs(): void
    {
        $ledger = Ledger::empty()
            ->addGenesis(Output::open(100, 'a'))
            ->apply(new Spend(
                id: new SpendId('tx1'),
                inputs: [new OutputId('a')],
                outputs: [Output::open(90, 'b')],
            ));

        self::assertSame(10, $ledger->feeForSpend(new SpendId('tx1')));
        self::assertSame(10, $ledger->totalFeesCollected());
        self::assertSame(90, $ledger->totalUnspentAmount());
    }

    public function test_zero_fee_when_inputs_equal_outputs(): void
    {
        $ledger = Ledger::empty()
            ->addGenesis(Output::open(100, 'a'))
            ->apply(new Spend(
                id: new SpendId('tx1'),
                inputs: [new OutputId('a')],
                outputs: [Output::open(100, 'b')],
            ));

        self::assertSame(0, $ledger->feeForSpend(new SpendId('tx1')));
        self::assertSame(0, $ledger->totalFeesCollected());
        self::assertSame(100, $ledger->totalUnspentAmount());
    }

    public function test_total_fees_accumulate_across_spends(): void
    {
        $ledger = Ledger::empty()
            ->addGenesis(Output::open(1000, 'genesis'))
            ->apply(new Spend(
                id: new SpendId('tx1'),
                inputs: [new OutputId('genesis')],
                outputs: [
                    Output::open(500, 'a'),
                    Output::open(490, 'b'),
                ],
            ))
            ->apply(new Spend(
                id: new SpendId('tx2'),
                inputs: [new OutputId('a')],
                outputs: [Output::open(495, 'c')],
            ));

        self::assertSame(10, $ledger->feeForSpend(new SpendId('tx1')));
        self::assertSame(5, $ledger->feeForSpend(new SpendId('tx2')));
        self::assertSame(15, $ledger->totalFeesCollected());
        self::assertSame(985, $ledger->totalUnspentAmount());
    }

    public function test_fee_for_unknown_spend_returns_null(): void
    {
        $ledger = Ledger::empty()
            ->addGenesis(Output::open(100, 'a'));

        self::assertNull($ledger->feeForSpend(new SpendId('nonexistent')));
    }

    public function test_empty_ledger_has_zero_total_fees(): void
    {
        $ledger = Ledger::empty();

        self::assertSame(0, $ledger->totalFeesCollected());
    }

    public function test_genesis_does_not_affect_fees(): void
    {
        $ledger = Ledger::empty()
            ->addGenesis(
                Output::open(1000, 'a'),
                Output::open(500, 'b'),
            );

        self::assertSame(0, $ledger->totalFeesCollected());
        self::assertSame([], $ledger->allSpendFees());
    }

    public function test_all_spend_fees_returns_complete_map(): void
    {
        $ledger = Ledger::empty()
            ->addGenesis(Output::open(1000, 'genesis'))
            ->apply(new Spend(
                id: new SpendId('tx1'),
                inputs: [new OutputId('genesis')],
                outputs: [Output::open(990, 'a')],
            ))
            ->apply(new Spend(
                id: new SpendId('tx2'),
                inputs: [new OutputId('a')],
                outputs: [Output::open(980, 'b')],
            ));

        $fees = $ledger->allSpendFees();
        self::assertCount(2, $fees);
        self::assertSame(10, $fees['tx1']);
        self::assertSame(10, $fees['tx2']);
    }

    public function test_fees_preserved_through_immutability(): void
    {
        $ledger1 = Ledger::empty()
            ->addGenesis(Output::open(100, 'a'))
            ->apply(new Spend(
                id: new SpendId('tx1'),
                inputs: [new OutputId('a')],
                outputs: [Output::open(95, 'b')],
            ));

        $ledger2 = $ledger1->apply(new Spend(
            id: new SpendId('tx2'),
            inputs: [new OutputId('b')],
            outputs: [Output::open(90, 'c')],
        ));

        // Original ledger unchanged
        self::assertSame(5, $ledger1->totalFeesCollected());
        self::assertNull($ledger1->feeForSpend(new SpendId('tx2')));

        // New ledger has both fees
        self::assertSame(10, $ledger2->totalFeesCollected());
        self::assertSame(5, $ledger2->feeForSpend(new SpendId('tx1')));
        self::assertSame(5, $ledger2->feeForSpend(new SpendId('tx2')));
    }

    // ========================================================================
    // Coinbase Tests (Minting)
    // ========================================================================

    public function test_apply_coinbase_creates_new_outputs(): void
    {
        $ledger = Ledger::empty()
            ->applyCoinbase(new Coinbase(
                id: new SpendId('block-1'),
                outputs: [
                    Output::open(50, 'reward-1'),
                    Output::open(25, 'reward-2'),
                ],
            ));

        self::assertSame(75, $ledger->totalUnspentAmount());
        self::assertTrue($ledger->unspent()->contains(new OutputId('reward-1')));
        self::assertTrue($ledger->unspent()->contains(new OutputId('reward-2')));
    }

    public function test_apply_coinbase_tracks_minted_amount(): void
    {
        $ledger = Ledger::empty()
            ->applyCoinbase(Coinbase::create([
                Output::open(50, 'reward-1'),
            ], 'block-1'));

        self::assertSame(50, $ledger->totalMinted());
        self::assertSame(50, $ledger->coinbaseAmount(new SpendId('block-1')));
    }

    public function test_apply_coinbase_fails_on_duplicate_id(): void
    {
        $this->expectException(DuplicateSpendException::class);
        $this->expectExceptionMessage("Spend 'block-1' has already been applied");

        Ledger::empty()
            ->applyCoinbase(Coinbase::create([Output::open(50, 'a')], 'block-1'))
            ->applyCoinbase(Coinbase::create([Output::open(50, 'b')], 'block-1'));
    }

    public function test_apply_coinbase_fails_on_output_id_conflict(): void
    {
        $this->expectException(DuplicateOutputIdException::class);
        $this->expectExceptionMessage("Duplicate output id: 'reward'");

        Ledger::empty()
            ->applyCoinbase(Coinbase::create([Output::open(50, 'reward')], 'block-1'))
            ->applyCoinbase(Coinbase::create([Output::open(50, 'reward')], 'block-2'));
    }

    public function test_is_coinbase_returns_true_for_coinbase_transactions(): void
    {
        $ledger = Ledger::empty()
            ->applyCoinbase(Coinbase::create([Output::open(50, 'a')], 'block-1'));

        self::assertTrue($ledger->isCoinbase(new SpendId('block-1')));
        self::assertFalse($ledger->isCoinbase(new SpendId('nonexistent')));
    }

    public function test_total_minted_accumulates_across_coinbases(): void
    {
        $ledger = Ledger::empty()
            ->applyCoinbase(Coinbase::create([Output::open(50, 'a')], 'block-1'))
            ->applyCoinbase(Coinbase::create([Output::open(25, 'b')], 'block-2'))
            ->applyCoinbase(Coinbase::create([Output::open(10, 'c')], 'block-3'));

        self::assertSame(85, $ledger->totalMinted());
        self::assertSame(85, $ledger->totalUnspentAmount());
    }

    public function test_coinbase_and_spend_ids_share_namespace(): void
    {
        $this->expectException(DuplicateSpendException::class);
        $this->expectExceptionMessage("Spend 'tx-1' has already been applied");

        Ledger::empty()
            ->applyCoinbase(Coinbase::create([Output::open(100, 'a')], 'tx-1'))
            ->apply(Spend::create(['a'], [Output::open(100, 'b')], id: 'tx-1'));
    }

    public function test_spend_after_coinbase_works(): void
    {
        $ledger = Ledger::empty()
            ->applyCoinbase(Coinbase::create([Output::open(100, 'reward')], 'block-1'))
            ->apply(Spend::create(['reward'], [Output::open(90, 'spent')], id: 'tx-1'));

        self::assertSame(100, $ledger->totalMinted());
        self::assertSame(10, $ledger->totalFeesCollected());
        self::assertSame(90, $ledger->totalUnspentAmount());
    }

    public function test_coinbase_amount_returns_null_for_regular_spend(): void
    {
        $ledger = Ledger::empty()
            ->applyCoinbase(Coinbase::create([Output::open(100, 'a')], 'block-1'))
            ->apply(Spend::create(['a'], [Output::open(100, 'b')], id: 'tx-1'));

        self::assertSame(100, $ledger->coinbaseAmount(new SpendId('block-1')));
        self::assertNull($ledger->coinbaseAmount(new SpendId('tx-1')));
    }

    public function test_empty_ledger_has_zero_minted(): void
    {
        $ledger = Ledger::empty();

        self::assertSame(0, $ledger->totalMinted());
    }

    // ========================================================================
    // Serialization Tests
    // ========================================================================

    public function test_ledger_can_be_serialized_to_array(): void
    {
        $ledger = Ledger::empty()
            ->addGenesis(Output::open(1000, 'genesis'))
            ->apply(new Spend(
                id: new SpendId('tx1'),
                inputs: [new OutputId('genesis')],
                outputs: [Output::open(900, 'out1')],
            ));

        $array = $ledger->toArray();

        self::assertArrayHasKey('unspent', $array);
        self::assertArrayHasKey('appliedSpends', $array);
        self::assertArrayHasKey('spendFees', $array);
        self::assertArrayHasKey('coinbaseAmounts', $array);

        self::assertCount(1, $array['unspent']);
        self::assertContains('tx1', $array['appliedSpends']);
        self::assertSame(100, $array['spendFees']['tx1']);
    }

    public function test_ledger_can_be_restored_from_array(): void
    {
        $data = [
            'unspent' => [
                ['id' => 'out1', 'amount' => 900],
            ],
            'appliedSpends' => ['tx1'],
            'spendFees' => ['tx1' => 100],
            'coinbaseAmounts' => [],
        ];

        $ledger = Ledger::fromArray($data);

        self::assertSame(900, $ledger->totalUnspentAmount());
        self::assertTrue($ledger->hasSpendBeenApplied(new SpendId('tx1')));
        self::assertSame(100, $ledger->feeForSpend(new SpendId('tx1')));
        self::assertSame(100, $ledger->totalFeesCollected());
    }

    public function test_ledger_round_trip_preserves_all_state(): void
    {
        $original = Ledger::empty()
            ->applyCoinbase(Coinbase::create([Output::open(1000, 'cb-out')], 'block-1'))
            ->apply(Spend::create(['cb-out'], [
                Output::open(600, 'alice'),
                Output::open(350, 'bob'),
            ], id: 'tx1'))
            ->apply(Spend::create(['alice'], [Output::open(550, 'charlie')], id: 'tx2'));

        $restored = Ledger::fromArray($original->toArray());

        // Verify unspent state
        self::assertSame($original->totalUnspentAmount(), $restored->totalUnspentAmount());
        self::assertSame($original->unspent()->count(), $restored->unspent()->count());

        // Verify spend tracking
        self::assertTrue($restored->hasSpendBeenApplied(new SpendId('block-1')));
        self::assertTrue($restored->hasSpendBeenApplied(new SpendId('tx1')));
        self::assertTrue($restored->hasSpendBeenApplied(new SpendId('tx2')));

        // Verify fees
        self::assertSame($original->totalFeesCollected(), $restored->totalFeesCollected());
        self::assertSame($original->feeForSpend(new SpendId('tx1')), $restored->feeForSpend(new SpendId('tx1')));
        self::assertSame($original->feeForSpend(new SpendId('tx2')), $restored->feeForSpend(new SpendId('tx2')));

        // Verify coinbase tracking
        self::assertSame($original->totalMinted(), $restored->totalMinted());
        self::assertTrue($restored->isCoinbase(new SpendId('block-1')));
        self::assertSame(1000, $restored->coinbaseAmount(new SpendId('block-1')));
    }

    public function test_ledger_json_serialization(): void
    {
        $ledger = Ledger::empty()
            ->addGenesis(Output::open(1000, 'genesis'))
            ->apply(new Spend(
                id: new SpendId('tx1'),
                inputs: [new OutputId('genesis')],
                outputs: [Output::open(950, 'out1')],
            ));

        $json = $ledger->toJson();
        $decoded = json_decode($json, true);

        self::assertIsArray($decoded);
        self::assertArrayHasKey('unspent', $decoded);
        self::assertArrayHasKey('appliedSpends', $decoded);
    }

    public function test_ledger_json_round_trip(): void
    {
        $original = Ledger::empty()
            ->applyCoinbase(Coinbase::create([Output::open(500, 'reward')], 'block-1'))
            ->apply(Spend::create(['reward'], [Output::open(450, 'spent')], id: 'tx1'));

        $json = $original->toJson();
        $restored = Ledger::fromJson($json);

        self::assertSame($original->totalUnspentAmount(), $restored->totalUnspentAmount());
        self::assertSame($original->totalFeesCollected(), $restored->totalFeesCollected());
        self::assertSame($original->totalMinted(), $restored->totalMinted());
    }

    public function test_empty_ledger_serialization(): void
    {
        $empty = Ledger::empty();

        $array = $empty->toArray();
        self::assertSame([], $array['unspent']);
        self::assertSame([], $array['appliedSpends']);
        self::assertSame([], $array['spendFees']);
        self::assertSame([], $array['coinbaseAmounts']);

        $restored = Ledger::fromArray($array);
        self::assertSame(0, $restored->totalUnspentAmount());
        self::assertSame(0, $restored->totalFeesCollected());
        self::assertSame(0, $restored->totalMinted());
    }

    public function test_restored_ledger_can_apply_new_spends(): void
    {
        $original = Ledger::empty()
            ->addGenesis(Output::open(1000, 'genesis'));

        $restored = Ledger::fromArray($original->toArray());

        // Apply a new spend to the restored ledger
        $restored = $restored->apply(new Spend(
            id: new SpendId('new-tx'),
            inputs: [new OutputId('genesis')],
            outputs: [Output::open(950, 'new-out')],
        ));

        self::assertSame(950, $restored->totalUnspentAmount());
        self::assertSame(50, $restored->feeForSpend(new SpendId('new-tx')));
    }

    public function test_restored_ledger_prevents_duplicate_spends(): void
    {
        $this->expectException(DuplicateSpendException::class);

        $original = Ledger::empty()
            ->addGenesis(Output::open(1000, 'genesis'))
            ->apply(new Spend(
                id: new SpendId('tx1'),
                inputs: [new OutputId('genesis')],
                outputs: [Output::open(1000, 'out')],
            ));

        $restored = Ledger::fromArray($original->toArray());

        // Try to apply the same spend ID again
        $restored->apply(new Spend(
            id: new SpendId('tx1'),
            inputs: [new OutputId('out')],
            outputs: [Output::open(1000, 'out2')],
        ));
    }

    public function test_json_with_pretty_print(): void
    {
        $ledger = Ledger::empty()
            ->addGenesis(Output::open(100, 'a'));

        $json = $ledger->toJson(JSON_PRETTY_PRINT);

        self::assertStringContainsString("\n", $json);
    }
}
