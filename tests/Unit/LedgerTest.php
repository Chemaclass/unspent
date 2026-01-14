<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit;

use Chemaclass\Unspent\CoinbaseTx;
use Chemaclass\Unspent\Exception\DuplicateOutputIdException;
use Chemaclass\Unspent\Exception\DuplicateTxException;
use Chemaclass\Unspent\Exception\GenesisNotAllowedException;
use Chemaclass\Unspent\Exception\InsufficientSpendsException;
use Chemaclass\Unspent\Exception\OutputAlreadySpentException;
use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\Tx;
use Chemaclass\Unspent\TxId;
use PHPUnit\Framework\TestCase;

final class LedgerTest extends TestCase
{
    public function test_implements_ledger_interface(): void
    {
        $ledger = Ledger::inMemory();

        self::assertInstanceOf(Ledger::class, $ledger);
    }

    public function test_empty_ledger_has_zero_unspent(): void
    {
        $ledger = Ledger::inMemory();

        self::assertSame(0, $ledger->totalUnspentAmount());
        self::assertTrue($ledger->unspent()->isEmpty());
    }

    public function test_can_add_genesis_outputs(): void
    {
        $output1 = Output::open(100, 'genesis-1');
        $output2 = Output::open(50, 'genesis-2');

        $ledger = Ledger::withGenesis($output1, $output2);

        self::assertSame(150, $ledger->totalUnspentAmount());
        self::assertSame(2, $ledger->unspent()->count());
    }

    public function test_genesis_only_allowed_on_empty_ledger(): void
    {
        $this->expectException(GenesisNotAllowedException::class);
        $this->expectExceptionMessage('Genesis outputs can only be added to an empty ledger');

        Ledger::withGenesis(Output::open(100, 'a'))
            ->addGenesis(Output::open(50, 'b'));
    }

    public function test_genesis_fails_on_duplicate_output_ids(): void
    {
        $this->expectException(DuplicateOutputIdException::class);
        $this->expectExceptionMessage("Duplicate output id: 'a'");

        Ledger::withGenesis(
            Output::open(100, 'a'),
            Output::open(50, 'a'),
        );
    }

    public function test_apply_tx_happy_path(): void
    {
        $ledger = Ledger::withGenesis(
            Output::open(100, 'a'),
            Output::open(50, 'b'),
        )
            ->apply(new Tx(
                id: new TxId('tx1'),
                spends: [new OutputId('a')],
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

    public function test_apply_tx_fails_when_input_not_in_unspent_set(): void
    {
        $this->expectException(OutputAlreadySpentException::class);
        $this->expectExceptionMessage("Output 'nonexistent' is not in the unspent set");

        Ledger::withGenesis(Output::open(100, 'a'))
            ->apply(new Tx(
                id: new TxId('tx1'),
                spends: [new OutputId('nonexistent')],
                outputs: [Output::open(100, 'b')],
            ));
    }

    public function test_apply_tx_fails_when_outputs_exceed_spends(): void
    {
        $this->expectException(InsufficientSpendsException::class);
        $this->expectExceptionMessage('Insufficient spends: spend amount (100) is less than output amount (150)');

        Ledger::withGenesis(Output::open(100, 'a'))
            ->apply(new Tx(
                id: new TxId('tx1'),
                spends: [new OutputId('a')],
                outputs: [Output::open(150, 'b')],
            ));
    }

    public function test_apply_same_tx_twice_fails(): void
    {
        $this->expectException(DuplicateTxException::class);
        $this->expectExceptionMessage("Tx 'tx1' has already been applied");

        $tx1 = new Tx(
            id: new TxId('tx1'),
            spends: [new OutputId('a')],
            outputs: [Output::open(100, 'b')],
        );

        $tx2 = new Tx(
            id: new TxId('tx1'),
            spends: [new OutputId('b')],
            outputs: [Output::open(100, 'c')],
        );

        Ledger::withGenesis(Output::open(100, 'a'))
            ->apply($tx1)
            ->apply($tx2);
    }

    public function test_output_can_only_be_spent_once(): void
    {
        $this->expectException(OutputAlreadySpentException::class);
        $this->expectExceptionMessage("Output 'a' is not in the unspent set");

        $ledger = Ledger::withGenesis(Output::open(100, 'a'))
            ->apply(new Tx(
                id: new TxId('tx1'),
                spends: [new OutputId('a')],
                outputs: [Output::open(100, 'b')],
            ));

        $ledger->apply(new Tx(
            id: new TxId('tx2'),
            spends: [new OutputId('a')],
            outputs: [Output::open(100, 'c')],
        ));
    }

    public function test_tx_output_ids_must_be_unique(): void
    {
        $this->expectException(DuplicateOutputIdException::class);
        $this->expectExceptionMessage("Duplicate output id: 'c'");

        Ledger::withGenesis(Output::open(100, 'a'))
            ->apply(new Tx(
                id: new TxId('tx1'),
                spends: [new OutputId('a')],
                outputs: [
                    Output::open(50, 'c'),
                    Output::open(50, 'c'),
                ],
            ));
    }

    public function test_tx_output_id_cannot_conflict_with_existing_unspent(): void
    {
        $this->expectException(DuplicateOutputIdException::class);
        $this->expectExceptionMessage("Duplicate output id: 'b'");

        Ledger::withGenesis(
            Output::open(100, 'a'),
            Output::open(50, 'b'),
        )
            ->apply(new Tx(
                id: new TxId('tx1'),
                spends: [new OutputId('a')],
                outputs: [Output::open(100, 'b')],
            ));
    }

    public function test_multiple_txs_in_sequence(): void
    {
        $ledger = Ledger::withGenesis(Output::open(1000, 'genesis'))
            ->apply(new Tx(
                id: new TxId('tx1'),
                spends: [new OutputId('genesis')],
                outputs: [
                    Output::open(600, 'a'),
                    Output::open(400, 'b'),
                ],
            ))
            ->apply(new Tx(
                id: new TxId('tx2'),
                spends: [new OutputId('a')],
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

    public function test_tx_with_multiple_inputs(): void
    {
        $ledger = Ledger::withGenesis(
            Output::open(100, 'a'),
            Output::open(50, 'b'),
        )
            ->apply(new Tx(
                id: new TxId('tx1'),
                spends: [new OutputId('a'), new OutputId('b')],
                outputs: [Output::open(150, 'c')],
            ));

        self::assertSame(150, $ledger->totalUnspentAmount());
        self::assertSame(1, $ledger->unspent()->count());
        self::assertTrue($ledger->unspent()->contains(new OutputId('c')));
    }

    public function test_can_query_if_tx_has_been_applied(): void
    {
        $ledger = Ledger::withGenesis(Output::open(100, 'a'));

        self::assertFalse($ledger->isTxApplied(new TxId('tx1')));

        $ledger = $ledger->apply(new Tx(
            id: new TxId('tx1'),
            spends: [new OutputId('a')],
            outputs: [Output::open(100, 'b')],
        ));

        self::assertTrue($ledger->isTxApplied(new TxId('tx1')));
        self::assertFalse($ledger->isTxApplied(new TxId('tx2')));
    }

    // ========================================================================
    // Fee Tests (Bitcoin-style implicit fees)
    // ========================================================================

    public function test_fee_calculated_when_inputs_exceed_outputs(): void
    {
        $ledger = Ledger::withGenesis(Output::open(100, 'a'))
            ->apply(new Tx(
                id: new TxId('tx1'),
                spends: [new OutputId('a')],
                outputs: [Output::open(90, 'b')],
            ));

        self::assertSame(10, $ledger->feeForTx(new TxId('tx1')));
        self::assertSame(10, $ledger->totalFeesCollected());
        self::assertSame(90, $ledger->totalUnspentAmount());
    }

    public function test_zero_fee_when_inputs_equal_outputs(): void
    {
        $ledger = Ledger::withGenesis(Output::open(100, 'a'))
            ->apply(new Tx(
                id: new TxId('tx1'),
                spends: [new OutputId('a')],
                outputs: [Output::open(100, 'b')],
            ));

        self::assertSame(0, $ledger->feeForTx(new TxId('tx1')));
        self::assertSame(0, $ledger->totalFeesCollected());
        self::assertSame(100, $ledger->totalUnspentAmount());
    }

    public function test_total_fees_accumulate_across_txs(): void
    {
        $ledger = Ledger::withGenesis(Output::open(1000, 'genesis'))
            ->apply(new Tx(
                id: new TxId('tx1'),
                spends: [new OutputId('genesis')],
                outputs: [
                    Output::open(500, 'a'),
                    Output::open(490, 'b'),
                ],
            ))
            ->apply(new Tx(
                id: new TxId('tx2'),
                spends: [new OutputId('a')],
                outputs: [Output::open(495, 'c')],
            ));

        self::assertSame(10, $ledger->feeForTx(new TxId('tx1')));
        self::assertSame(5, $ledger->feeForTx(new TxId('tx2')));
        self::assertSame(15, $ledger->totalFeesCollected());
        self::assertSame(985, $ledger->totalUnspentAmount());
    }

    public function test_fee_for_unknown_tx_returns_null(): void
    {
        $ledger = Ledger::withGenesis(Output::open(100, 'a'));

        self::assertNull($ledger->feeForTx(new TxId('nonexistent')));
    }

    public function test_empty_ledger_has_zero_total_fees(): void
    {
        $ledger = Ledger::inMemory();

        self::assertSame(0, $ledger->totalFeesCollected());
    }

    public function test_genesis_does_not_affect_fees(): void
    {
        $ledger = Ledger::withGenesis(
            Output::open(1000, 'a'),
            Output::open(500, 'b'),
        );

        self::assertSame(0, $ledger->totalFeesCollected());
        self::assertSame([], $ledger->allTxFees());
    }

    public function test_all_tx_fees_returns_complete_map(): void
    {
        $ledger = Ledger::withGenesis(Output::open(1000, 'genesis'))
            ->apply(new Tx(
                id: new TxId('tx1'),
                spends: [new OutputId('genesis')],
                outputs: [Output::open(990, 'a')],
            ))
            ->apply(new Tx(
                id: new TxId('tx2'),
                spends: [new OutputId('a')],
                outputs: [Output::open(980, 'b')],
            ));

        $fees = $ledger->allTxFees();
        self::assertCount(2, $fees);
        self::assertSame(10, $fees['tx1']);
        self::assertSame(10, $fees['tx2']);
    }

    public function test_fees_preserved_through_immutability(): void
    {
        $ledger1 = Ledger::withGenesis(Output::open(100, 'a'))
            ->apply(new Tx(
                id: new TxId('tx1'),
                spends: [new OutputId('a')],
                outputs: [Output::open(95, 'b')],
            ));

        $ledger2 = $ledger1->apply(new Tx(
            id: new TxId('tx2'),
            spends: [new OutputId('b')],
            outputs: [Output::open(90, 'c')],
        ));

        // Original ledger unchanged
        self::assertSame(5, $ledger1->totalFeesCollected());
        self::assertNull($ledger1->feeForTx(new TxId('tx2')));

        // New ledger has both fees
        self::assertSame(10, $ledger2->totalFeesCollected());
        self::assertSame(5, $ledger2->feeForTx(new TxId('tx1')));
        self::assertSame(5, $ledger2->feeForTx(new TxId('tx2')));
    }

    // ========================================================================
    // Coinbase Tests (Minting)
    // ========================================================================

    public function test_apply_coinbase_creates_new_outputs(): void
    {
        $ledger = Ledger::inMemory()
            ->applyCoinbase(new CoinbaseTx(
                id: new TxId('block-1'),
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
        $ledger = Ledger::inMemory()
            ->applyCoinbase(CoinbaseTx::create([
                Output::open(50, 'reward-1'),
            ], 'block-1'));

        self::assertSame(50, $ledger->totalMinted());
        self::assertSame(50, $ledger->coinbaseAmount(new TxId('block-1')));
    }

    public function test_apply_coinbase_fails_on_duplicate_id(): void
    {
        $this->expectException(DuplicateTxException::class);
        $this->expectExceptionMessage("Tx 'block-1' has already been applied");

        Ledger::inMemory()
            ->applyCoinbase(CoinbaseTx::create([Output::open(50, 'a')], 'block-1'))
            ->applyCoinbase(CoinbaseTx::create([Output::open(50, 'b')], 'block-1'));
    }

    public function test_apply_coinbase_fails_on_output_id_conflict(): void
    {
        $this->expectException(DuplicateOutputIdException::class);
        $this->expectExceptionMessage("Duplicate output id: 'reward'");

        Ledger::inMemory()
            ->applyCoinbase(CoinbaseTx::create([Output::open(50, 'reward')], 'block-1'))
            ->applyCoinbase(CoinbaseTx::create([Output::open(50, 'reward')], 'block-2'));
    }

    public function test_is_coinbase_returns_true_for_coinbase_transactions(): void
    {
        $ledger = Ledger::inMemory()
            ->applyCoinbase(CoinbaseTx::create([Output::open(50, 'a')], 'block-1'));

        self::assertTrue($ledger->isCoinbase(new TxId('block-1')));
        self::assertFalse($ledger->isCoinbase(new TxId('nonexistent')));
    }

    public function test_total_minted_accumulates_across_coinbases(): void
    {
        $ledger = Ledger::inMemory()
            ->applyCoinbase(CoinbaseTx::create([Output::open(50, 'a')], 'block-1'))
            ->applyCoinbase(CoinbaseTx::create([Output::open(25, 'b')], 'block-2'))
            ->applyCoinbase(CoinbaseTx::create([Output::open(10, 'c')], 'block-3'));

        self::assertSame(85, $ledger->totalMinted());
        self::assertSame(85, $ledger->totalUnspentAmount());
    }

    public function test_coinbase_and_tx_ids_share_namespace(): void
    {
        $this->expectException(DuplicateTxException::class);
        $this->expectExceptionMessage("Tx 'tx-1' has already been applied");

        Ledger::inMemory()
            ->applyCoinbase(CoinbaseTx::create([Output::open(100, 'a')], 'tx-1'))
            ->apply(Tx::create(['a'], [Output::open(100, 'b')], id: 'tx-1'));
    }

    public function test_tx_after_coinbase_works(): void
    {
        $ledger = Ledger::inMemory()
            ->applyCoinbase(CoinbaseTx::create([Output::open(100, 'reward')], 'block-1'))
            ->apply(Tx::create(['reward'], [Output::open(90, 'spent')], id: 'tx-1'));

        self::assertSame(100, $ledger->totalMinted());
        self::assertSame(10, $ledger->totalFeesCollected());
        self::assertSame(90, $ledger->totalUnspentAmount());
    }

    public function test_coinbase_amount_returns_null_for_regular_tx(): void
    {
        $ledger = Ledger::inMemory()
            ->applyCoinbase(CoinbaseTx::create([Output::open(100, 'a')], 'block-1'))
            ->apply(Tx::create(['a'], [Output::open(100, 'b')], id: 'tx-1'));

        self::assertSame(100, $ledger->coinbaseAmount(new TxId('block-1')));
        self::assertNull($ledger->coinbaseAmount(new TxId('tx-1')));
    }

    public function test_empty_ledger_has_zero_minted(): void
    {
        $ledger = Ledger::inMemory();

        self::assertSame(0, $ledger->totalMinted());
    }

    // ========================================================================
    // Serialization Tests
    // ========================================================================

    public function test_ledger_can_be_serialized_to_array(): void
    {
        $ledger = Ledger::withGenesis(Output::open(1000, 'genesis'))
            ->apply(new Tx(
                id: new TxId('tx1'),
                spends: [new OutputId('genesis')],
                outputs: [Output::open(900, 'out1')],
            ));

        $array = $ledger->toArray();

        self::assertArrayHasKey('unspent', $array);
        self::assertArrayHasKey('appliedTxs', $array);
        self::assertArrayHasKey('txFees', $array);
        self::assertArrayHasKey('coinbaseAmounts', $array);

        self::assertCount(1, $array['unspent']);
        self::assertContains('tx1', $array['appliedTxs']);
        self::assertSame(100, $array['txFees']['tx1']);
    }

    public function test_ledger_can_be_restored_from_array(): void
    {
        $data = [
            'version' => 1,
            'unspent' => [
                'out1' => ['amount' => 900, 'lock' => ['type' => 'none']],
            ],
            'appliedTxs' => ['tx1'],
            'txFees' => ['tx1' => 100],
            'coinbaseAmounts' => [],
        ];

        $ledger = Ledger::fromArray($data);

        self::assertSame(900, $ledger->totalUnspentAmount());
        self::assertTrue($ledger->isTxApplied(new TxId('tx1')));
        self::assertSame(100, $ledger->feeForTx(new TxId('tx1')));
        self::assertSame(100, $ledger->totalFeesCollected());
    }

    public function test_ledger_round_trip_preserves_all_state(): void
    {
        $original = Ledger::inMemory()
            ->applyCoinbase(CoinbaseTx::create([Output::open(1000, 'cb-out')], 'block-1'))
            ->apply(Tx::create(['cb-out'], [
                Output::open(600, 'alice'),
                Output::open(350, 'bob'),
            ], id: 'tx1'))
            ->apply(Tx::create(['alice'], [Output::open(550, 'charlie')], id: 'tx2'));

        $restored = Ledger::fromArray($original->toArray());

        // Verify unspent state
        self::assertSame($original->totalUnspentAmount(), $restored->totalUnspentAmount());
        self::assertSame($original->unspent()->count(), $restored->unspent()->count());

        // Verify tx tracking
        self::assertTrue($restored->isTxApplied(new TxId('block-1')));
        self::assertTrue($restored->isTxApplied(new TxId('tx1')));
        self::assertTrue($restored->isTxApplied(new TxId('tx2')));

        // Verify fees
        self::assertSame($original->totalFeesCollected(), $restored->totalFeesCollected());
        self::assertSame($original->feeForTx(new TxId('tx1')), $restored->feeForTx(new TxId('tx1')));
        self::assertSame($original->feeForTx(new TxId('tx2')), $restored->feeForTx(new TxId('tx2')));

        // Verify coinbase tracking
        self::assertSame($original->totalMinted(), $restored->totalMinted());
        self::assertTrue($restored->isCoinbase(new TxId('block-1')));
        self::assertSame(1000, $restored->coinbaseAmount(new TxId('block-1')));
    }

    public function test_ledger_json_serialization(): void
    {
        $ledger = Ledger::withGenesis(Output::open(1000, 'genesis'))
            ->apply(new Tx(
                id: new TxId('tx1'),
                spends: [new OutputId('genesis')],
                outputs: [Output::open(950, 'out1')],
            ));

        $json = $ledger->toJson();
        $decoded = json_decode($json, true);

        self::assertIsArray($decoded);
        self::assertArrayHasKey('unspent', $decoded);
        self::assertArrayHasKey('appliedTxs', $decoded);
    }

    public function test_ledger_json_round_trip(): void
    {
        $original = Ledger::inMemory()
            ->applyCoinbase(CoinbaseTx::create([Output::open(500, 'reward')], 'block-1'))
            ->apply(Tx::create(['reward'], [Output::open(450, 'spent')], id: 'tx1'));

        $json = $original->toJson();
        $restored = Ledger::fromJson($json);

        self::assertSame($original->totalUnspentAmount(), $restored->totalUnspentAmount());
        self::assertSame($original->totalFeesCollected(), $restored->totalFeesCollected());
        self::assertSame($original->totalMinted(), $restored->totalMinted());
    }

    public function test_empty_ledger_serialization(): void
    {
        $empty = Ledger::inMemory();

        $array = $empty->toArray();
        self::assertSame([], $array['unspent']);
        self::assertSame([], $array['appliedTxs']);
        self::assertSame([], $array['txFees']);
        self::assertSame([], $array['coinbaseAmounts']);

        $restored = Ledger::fromArray($array);
        self::assertSame(0, $restored->totalUnspentAmount());
        self::assertSame(0, $restored->totalFeesCollected());
        self::assertSame(0, $restored->totalMinted());
    }

    public function test_restored_ledger_can_apply_new_txs(): void
    {
        $original = Ledger::withGenesis(Output::open(1000, 'genesis'));

        $restored = Ledger::fromArray($original->toArray());

        // Apply a new tx to the restored ledger
        $restored = $restored->apply(new Tx(
            id: new TxId('new-tx'),
            spends: [new OutputId('genesis')],
            outputs: [Output::open(950, 'new-out')],
        ));

        self::assertSame(950, $restored->totalUnspentAmount());
        self::assertSame(50, $restored->feeForTx(new TxId('new-tx')));
    }

    public function test_restored_ledger_prevents_duplicate_txs(): void
    {
        $this->expectException(DuplicateTxException::class);

        $original = Ledger::withGenesis(Output::open(1000, 'genesis'))
            ->apply(new Tx(
                id: new TxId('tx1'),
                spends: [new OutputId('genesis')],
                outputs: [Output::open(1000, 'out')],
            ));

        $restored = Ledger::fromArray($original->toArray());

        // Try to apply the same tx ID again
        $restored->apply(new Tx(
            id: new TxId('tx1'),
            spends: [new OutputId('out')],
            outputs: [Output::open(1000, 'out2')],
        ));
    }

    public function test_json_with_pretty_print(): void
    {
        $ledger = Ledger::withGenesis(Output::open(100, 'a'));

        $json = $ledger->toJson(JSON_PRETTY_PRINT);

        self::assertStringContainsString("\n", $json);
    }

    // ========================================================================
    // Convenience Methods Tests (transfer, debit, credit)
    // ========================================================================

    public function test_transfer_moves_amount_between_owners(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 1000, 'alice-funds'),
        );

        $ledger = $ledger->transfer('alice', 'bob', 300);

        self::assertSame(1000, $ledger->totalUnspentAmount());
        self::assertSame(700, $ledger->totalUnspentByOwner('alice'));
        self::assertSame(300, $ledger->totalUnspentByOwner('bob'));
    }

    public function test_transfer_with_fee(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 1000, 'alice-funds'),
        );

        $ledger = $ledger->transfer('alice', 'bob', 300, fee: 10);

        self::assertSame(990, $ledger->totalUnspentAmount());
        self::assertSame(690, $ledger->totalUnspentByOwner('alice'));
        self::assertSame(300, $ledger->totalUnspentByOwner('bob'));
        self::assertSame(10, $ledger->totalFeesCollected());
    }

    public function test_transfer_with_custom_tx_id(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 1000, 'alice-funds'),
        );

        $ledger = $ledger->transfer('alice', 'bob', 300, txId: 'my-transfer');

        self::assertTrue($ledger->isTxApplied(new TxId('my-transfer')));
    }

    public function test_transfer_fails_on_insufficient_balance(): void
    {
        $this->expectException(InsufficientSpendsException::class);

        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 100, 'alice-funds'),
        );

        $ledger->transfer('alice', 'bob', 200);
    }

    public function test_transfer_combines_multiple_outputs(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 300, 'alice-1'),
            Output::ownedBy('alice', 400, 'alice-2'),
            Output::ownedBy('alice', 300, 'alice-3'),
        );

        $ledger = $ledger->transfer('alice', 'bob', 600);

        self::assertSame(1000, $ledger->totalUnspentAmount());
        self::assertSame(400, $ledger->totalUnspentByOwner('alice'));
        self::assertSame(600, $ledger->totalUnspentByOwner('bob'));
    }

    public function test_transfer_exact_amount_no_change(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 500, 'alice-funds'),
        );

        $ledger = $ledger->transfer('alice', 'bob', 500);

        self::assertSame(500, $ledger->totalUnspentAmount());
        self::assertSame(0, $ledger->totalUnspentByOwner('alice'));
        self::assertSame(500, $ledger->totalUnspentByOwner('bob'));
    }

    public function test_debit_burns_amount_from_owner(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 1000, 'alice-funds'),
        );

        $ledger = $ledger->debit('alice', 300);

        self::assertSame(700, $ledger->totalUnspentAmount());
        self::assertSame(700, $ledger->totalUnspentByOwner('alice'));
        self::assertSame(300, $ledger->totalFeesCollected());
    }

    public function test_debit_with_additional_fee(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 1000, 'alice-funds'),
        );

        $ledger = $ledger->debit('alice', 300, fee: 50);

        self::assertSame(650, $ledger->totalUnspentAmount());
        self::assertSame(650, $ledger->totalUnspentByOwner('alice'));
        self::assertSame(350, $ledger->totalFeesCollected());
    }

    public function test_debit_fails_on_insufficient_balance(): void
    {
        $this->expectException(InsufficientSpendsException::class);

        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 100, 'alice-funds'),
        );

        $ledger->debit('alice', 200);
    }

    public function test_debit_exact_amount_leaves_no_change(): void
    {
        // Note: In UTXO model, you cannot create a tx with zero outputs.
        // To "burn" everything, debit most and leave minimal change.
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 500, 'alice-funds'),
        );

        $ledger = $ledger->debit('alice', 499);

        self::assertSame(1, $ledger->totalUnspentAmount());
        self::assertSame(1, $ledger->totalUnspentByOwner('alice'));
        self::assertSame(499, $ledger->totalFeesCollected());
    }

    public function test_credit_mints_amount_to_owner(): void
    {
        $ledger = Ledger::inMemory();

        $ledger = $ledger->credit('alice', 500);

        self::assertSame(500, $ledger->totalUnspentAmount());
        self::assertSame(500, $ledger->totalUnspentByOwner('alice'));
        self::assertSame(500, $ledger->totalMinted());
    }

    public function test_credit_with_custom_tx_id(): void
    {
        $ledger = Ledger::inMemory();

        $ledger = $ledger->credit('alice', 500, 'mint-alice');

        self::assertTrue($ledger->isTxApplied(new TxId('mint-alice')));
        self::assertTrue($ledger->isCoinbase(new TxId('mint-alice')));
    }

    public function test_credit_adds_to_existing_balance(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 1000, 'alice-funds'),
        );

        $ledger = $ledger->credit('alice', 500);

        self::assertSame(1500, $ledger->totalUnspentAmount());
        self::assertSame(1500, $ledger->totalUnspentByOwner('alice'));
        self::assertSame(500, $ledger->totalMinted());
    }

    public function test_convenience_methods_chain(): void
    {
        $ledger = Ledger::inMemory()
            ->credit('alice', 1000)
            ->transfer('alice', 'bob', 300)
            ->debit('bob', 100)
            ->credit('charlie', 200);

        self::assertSame(1100, $ledger->totalUnspentAmount());
        self::assertSame(700, $ledger->totalUnspentByOwner('alice'));
        self::assertSame(200, $ledger->totalUnspentByOwner('bob'));
        self::assertSame(200, $ledger->totalUnspentByOwner('charlie'));
        self::assertSame(1200, $ledger->totalMinted());
        self::assertSame(100, $ledger->totalFeesCollected());
    }
}
