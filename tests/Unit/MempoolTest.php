<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit;

use Chemaclass\Unspent\Exception\DuplicateTxException;
use Chemaclass\Unspent\Exception\OutputAlreadySpentException;
use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Mempool;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\Tx;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MempoolTest extends TestCase
{
    // ========================================
    // add() tests
    // ========================================

    public function test_add_validates_transaction(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 100, 'a1'),
        );
        $mempool = new Mempool($ledger);

        $tx = Tx::create(
            spendIds: ['a1'],
            outputs: [Output::ownedBy('bob', 100)],
            signedBy: 'alice',
        );

        $txId = $mempool->add($tx);

        self::assertSame($tx->id->value, $txId);
        self::assertTrue($mempool->has($txId));
    }

    public function test_add_throws_on_invalid_transaction(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 100, 'a1'),
        );
        $mempool = new Mempool($ledger);

        $tx = Tx::create(
            spendIds: ['nonexistent'],
            outputs: [Output::ownedBy('bob', 100)],
            signedBy: 'alice',
        );

        $this->expectException(OutputAlreadySpentException::class);

        $mempool->add($tx);
    }

    public function test_add_throws_on_duplicate_transaction(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 100, 'a1'),
        );
        $mempool = new Mempool($ledger);

        $tx = Tx::create(
            spendIds: ['a1'],
            outputs: [Output::ownedBy('bob', 100)],
            signedBy: 'alice',
            id: 'tx-1',
        );

        $mempool->add($tx);

        $this->expectException(DuplicateTxException::class);

        $mempool->add($tx);
    }

    public function test_add_detects_double_spend_within_mempool(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 100, 'a1'),
        );
        $mempool = new Mempool($ledger);

        $tx1 = Tx::create(
            spendIds: ['a1'],
            outputs: [Output::ownedBy('bob', 100)],
            signedBy: 'alice',
            id: 'tx-1',
        );

        $tx2 = Tx::create(
            spendIds: ['a1'], // Same spend as tx1
            outputs: [Output::ownedBy('charlie', 100)],
            signedBy: 'alice',
            id: 'tx-2',
        );

        $mempool->add($tx1);

        $this->expectException(OutputAlreadySpentException::class);
        $this->expectExceptionMessage('conflicts with pending transaction tx-1');

        $mempool->add($tx2);
    }

    // ========================================
    // remove() tests
    // ========================================

    public function test_remove_removes_transaction(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 100, 'a1'),
        );
        $mempool = new Mempool($ledger);

        $tx = Tx::create(
            spendIds: ['a1'],
            outputs: [Output::ownedBy('bob', 100)],
            signedBy: 'alice',
            id: 'tx-1',
        );

        $mempool->add($tx);
        self::assertTrue($mempool->has('tx-1'));

        $mempool->remove('tx-1');
        self::assertFalse($mempool->has('tx-1'));
    }

    public function test_remove_nonexistent_does_nothing(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 100, 'a1'),
        );
        $mempool = new Mempool($ledger);

        // Should not throw
        $mempool->remove('nonexistent');

        self::assertSame(0, $mempool->count());
    }

    // ========================================
    // commit() tests
    // ========================================

    public function test_commit_applies_all_transactions(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 100, 'a1'),
            Output::ownedBy('bob', 200, 'b1'),
        );
        $mempool = new Mempool($ledger);

        $tx1 = Tx::create(
            spendIds: ['a1'],
            outputs: [Output::ownedBy('charlie', 100)],
            signedBy: 'alice',
        );

        $tx2 = Tx::create(
            spendIds: ['b1'],
            outputs: [Output::ownedBy('dave', 200)],
            signedBy: 'bob',
        );

        $mempool->add($tx1);
        $mempool->add($tx2);

        $committed = $mempool->commit();

        self::assertSame(2, $committed);
        self::assertSame(0, $mempool->count());
        self::assertSame(100, $ledger->totalUnspentByOwner('charlie'));
        self::assertSame(200, $ledger->totalUnspentByOwner('dave'));
    }

    public function test_commit_returns_zero_when_empty(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 100, 'a1'),
        );
        $mempool = new Mempool($ledger);

        $committed = $mempool->commit();

        self::assertSame(0, $committed);
    }

    // ========================================
    // commitOne() tests
    // ========================================

    public function test_commit_one_applies_single_transaction(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 100, 'a1'),
            Output::ownedBy('bob', 200, 'b1'),
        );
        $mempool = new Mempool($ledger);

        $tx1 = Tx::create(
            spendIds: ['a1'],
            outputs: [Output::ownedBy('charlie', 100)],
            signedBy: 'alice',
            id: 'tx-1',
        );

        $tx2 = Tx::create(
            spendIds: ['b1'],
            outputs: [Output::ownedBy('dave', 200)],
            signedBy: 'bob',
            id: 'tx-2',
        );

        $mempool->add($tx1);
        $mempool->add($tx2);

        $mempool->commitOne('tx-1');

        self::assertSame(1, $mempool->count());
        self::assertFalse($mempool->has('tx-1'));
        self::assertTrue($mempool->has('tx-2'));
        self::assertSame(100, $ledger->totalUnspentByOwner('charlie'));
    }

    public function test_commit_one_throws_on_nonexistent(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 100, 'a1'),
        );
        $mempool = new Mempool($ledger);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Transaction nonexistent not in mempool');

        $mempool->commitOne('nonexistent');
    }

    // ========================================
    // clear() tests
    // ========================================

    public function test_clear_removes_all_transactions(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 100, 'a1'),
            Output::ownedBy('bob', 200, 'b1'),
        );
        $mempool = new Mempool($ledger);

        $tx1 = Tx::create(
            spendIds: ['a1'],
            outputs: [Output::ownedBy('charlie', 100)],
            signedBy: 'alice',
        );

        $tx2 = Tx::create(
            spendIds: ['b1'],
            outputs: [Output::ownedBy('dave', 200)],
            signedBy: 'bob',
        );

        $mempool->add($tx1);
        $mempool->add($tx2);
        self::assertSame(2, $mempool->count());

        $mempool->clear();

        self::assertSame(0, $mempool->count());
    }

    // ========================================
    // get() and all() tests
    // ========================================

    public function test_get_returns_transaction(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 100, 'a1'),
        );
        $mempool = new Mempool($ledger);

        $tx = Tx::create(
            spendIds: ['a1'],
            outputs: [Output::ownedBy('bob', 100)],
            signedBy: 'alice',
            id: 'tx-1',
        );

        $mempool->add($tx);

        $retrieved = $mempool->get('tx-1');

        self::assertNotNull($retrieved);
        self::assertSame($tx->id->value, $retrieved->id->value);
    }

    public function test_get_returns_null_for_nonexistent(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 100, 'a1'),
        );
        $mempool = new Mempool($ledger);

        self::assertNull($mempool->get('nonexistent'));
    }

    public function test_all_returns_all_transactions(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 100, 'a1'),
            Output::ownedBy('bob', 200, 'b1'),
        );
        $mempool = new Mempool($ledger);

        $tx1 = Tx::create(
            spendIds: ['a1'],
            outputs: [Output::ownedBy('charlie', 100)],
            signedBy: 'alice',
            id: 'tx-1',
        );

        $tx2 = Tx::create(
            spendIds: ['b1'],
            outputs: [Output::ownedBy('dave', 200)],
            signedBy: 'bob',
            id: 'tx-2',
        );

        $mempool->add($tx1);
        $mempool->add($tx2);

        $all = $mempool->all();

        self::assertCount(2, $all);
        self::assertArrayHasKey('tx-1', $all);
        self::assertArrayHasKey('tx-2', $all);
    }

    // ========================================
    // totalPendingFees() tests
    // ========================================

    public function test_total_pending_fees(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 100, 'a1'),
            Output::ownedBy('bob', 200, 'b1'),
        );
        $mempool = new Mempool($ledger);

        $tx1 = Tx::create(
            spendIds: ['a1'],
            outputs: [Output::ownedBy('charlie', 90)], // 10 fee
            signedBy: 'alice',
        );

        $tx2 = Tx::create(
            spendIds: ['b1'],
            outputs: [Output::ownedBy('dave', 180)], // 20 fee
            signedBy: 'bob',
        );

        $mempool->add($tx1);
        $mempool->add($tx2);

        self::assertSame(30, $mempool->totalPendingFees());
    }

    // ========================================
    // replace() tests (RBF - Replace-By-Fee)
    // ========================================

    public function test_replace_replaces_transaction(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 100, 'a1'),
        );
        $mempool = new Mempool($ledger);

        $tx1 = Tx::create(
            spendIds: ['a1'],
            outputs: [Output::ownedBy('bob', 95)], // 5 fee
            signedBy: 'alice',
            id: 'tx-1',
        );

        $tx2 = Tx::create(
            spendIds: ['a1'],
            outputs: [Output::ownedBy('charlie', 90)], // 10 fee (higher)
            signedBy: 'alice',
            id: 'tx-2',
        );

        $mempool->add($tx1);
        $mempool->replace('tx-1', $tx2);

        self::assertFalse($mempool->has('tx-1'));
        self::assertTrue($mempool->has('tx-2'));
        self::assertSame(1, $mempool->count());
    }

    public function test_replace_throws_on_nonexistent(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 100, 'a1'),
        );
        $mempool = new Mempool($ledger);

        $tx = Tx::create(
            spendIds: ['a1'],
            outputs: [Output::ownedBy('bob', 100)],
            signedBy: 'alice',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Transaction nonexistent not in mempool');

        $mempool->replace('nonexistent', $tx);
    }
}
