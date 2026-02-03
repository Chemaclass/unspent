<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit;

use Chemaclass\Unspent\Exception\InsufficientSpendsException;
use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Output;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BatchOperationsTest extends TestCase
{
    // ========================================
    // consolidate() tests
    // ========================================

    public function test_consolidate_merges_multiple_outputs_into_one(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 100, 'a1'),
            Output::ownedBy('alice', 200, 'a2'),
            Output::ownedBy('alice', 300, 'a3'),
        );

        $ledger->consolidate('alice');

        $outputs = array_values(iterator_to_array($ledger->unspentByOwner('alice')));
        self::assertCount(1, $outputs);
        self::assertSame(600, $outputs[0]->amount);
    }

    public function test_consolidate_returns_self_for_fluent_chaining(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 100, 'a1'),
            Output::ownedBy('alice', 200, 'a2'),
        );

        $result = $ledger->consolidate('alice');

        self::assertSame($ledger, $result);
    }

    public function test_consolidate_does_nothing_with_single_output(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 100, 'a1'),
        );

        $ledger->consolidate('alice');

        $outputs = array_values(iterator_to_array($ledger->unspentByOwner('alice')));
        self::assertCount(1, $outputs);
        self::assertSame('a1', $outputs[0]->id->value);
    }

    public function test_consolidate_does_nothing_with_no_outputs(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('bob', 100, 'b1'),
        );

        $ledger->consolidate('alice');

        self::assertSame(0, $ledger->totalUnspentByOwner('alice'));
    }

    public function test_consolidate_with_fee_deducts_from_total(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 100, 'a1'),
            Output::ownedBy('alice', 200, 'a2'),
        );

        $ledger->consolidate('alice', fee: 10);

        self::assertSame(290, $ledger->totalUnspentByOwner('alice'));
        self::assertSame(10, $ledger->totalFeesCollected());
    }

    public function test_consolidate_preserves_other_owners_outputs(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 100, 'a1'),
            Output::ownedBy('alice', 200, 'a2'),
            Output::ownedBy('bob', 300, 'b1'),
        );

        $ledger->consolidate('alice');

        self::assertSame(300, $ledger->totalUnspentByOwner('alice'));
        self::assertSame(300, $ledger->totalUnspentByOwner('bob'));
    }

    // ========================================
    // batchTransfer() tests
    // ========================================

    public function test_batch_transfer_to_multiple_recipients(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 1000, 'a1'),
        );

        $ledger->batchTransfer('alice', [
            'bob' => 100,
            'charlie' => 200,
            'dave' => 50,
        ]);

        self::assertSame(650, $ledger->totalUnspentByOwner('alice'));
        self::assertSame(100, $ledger->totalUnspentByOwner('bob'));
        self::assertSame(200, $ledger->totalUnspentByOwner('charlie'));
        self::assertSame(50, $ledger->totalUnspentByOwner('dave'));
    }

    public function test_batch_transfer_returns_self_for_fluent_chaining(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 1000, 'a1'),
        );

        $result = $ledger->batchTransfer('alice', ['bob' => 100]);

        self::assertSame($ledger, $result);
    }

    public function test_batch_transfer_with_exact_amount_leaves_no_change(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 300, 'a1'),
        );

        $ledger->batchTransfer('alice', [
            'bob' => 100,
            'charlie' => 200,
        ]);

        self::assertSame(0, $ledger->totalUnspentByOwner('alice'));
        self::assertSame(100, $ledger->totalUnspentByOwner('bob'));
        self::assertSame(200, $ledger->totalUnspentByOwner('charlie'));
    }

    public function test_batch_transfer_with_fee(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 1000, 'a1'),
        );

        $ledger->batchTransfer('alice', [
            'bob' => 100,
            'charlie' => 200,
        ], fee: 50);

        self::assertSame(650, $ledger->totalUnspentByOwner('alice'));
        self::assertSame(100, $ledger->totalUnspentByOwner('bob'));
        self::assertSame(200, $ledger->totalUnspentByOwner('charlie'));
        self::assertSame(50, $ledger->totalFeesCollected());
    }

    public function test_batch_transfer_throws_on_insufficient_balance(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 100, 'a1'),
        );

        $this->expectException(InsufficientSpendsException::class);

        $ledger->batchTransfer('alice', [
            'bob' => 50,
            'charlie' => 100, // Total 150 > 100 available
        ]);
    }

    public function test_batch_transfer_throws_on_empty_recipients(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 100, 'a1'),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one recipient is required');

        $ledger->batchTransfer('alice', []);
    }

    public function test_batch_transfer_throws_on_zero_amount(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 100, 'a1'),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Amount for bob must be positive');

        $ledger->batchTransfer('alice', ['bob' => 0]);
    }

    public function test_batch_transfer_throws_on_negative_amount(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 100, 'a1'),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Amount for bob must be positive');

        $ledger->batchTransfer('alice', ['bob' => -50]);
    }

    public function test_batch_transfer_can_include_sender_as_recipient(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 1000, 'a1'),
        );

        // Alice splits funds, some back to herself
        $ledger->batchTransfer('alice', [
            'alice' => 500, // Explicit output back to self
            'bob' => 200,
        ]);

        // 1000 - 500 - 200 = 300 change + 500 explicit = 800 total for alice
        self::assertSame(800, $ledger->totalUnspentByOwner('alice'));
        self::assertSame(200, $ledger->totalUnspentByOwner('bob'));
    }

    public function test_batch_transfer_uses_multiple_inputs_if_needed(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 100, 'a1'),
            Output::ownedBy('alice', 100, 'a2'),
            Output::ownedBy('alice', 100, 'a3'),
        );

        $ledger->batchTransfer('alice', [
            'bob' => 250, // Needs at least 3 inputs
        ]);

        self::assertSame(50, $ledger->totalUnspentByOwner('alice'));
        self::assertSame(250, $ledger->totalUnspentByOwner('bob'));
    }
}
