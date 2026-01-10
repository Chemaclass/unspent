<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit;

use Chemaclass\Unspent\CoinbaseTx;
use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\Persistence\Sqlite\SqliteHistoryStore;
use Chemaclass\Unspent\Persistence\Sqlite\SqliteSchema;
use Chemaclass\Unspent\ScalableLedger;
use Chemaclass\Unspent\Tx;
use PDO;
use PHPUnit\Framework\TestCase;

final class ScalableLedgerTest extends TestCase
{
    public function test_implements_ledger_interface(): void
    {
        $ledger = $this->createScalableLedger('test-wallet', [Output::open(1000, 'genesis')]);

        self::assertInstanceOf(Ledger::class, $ledger);
    }

    public function test_scalable_mode_creates_ledger_with_genesis(): void
    {
        $ledger = $this->createScalableLedger('test-wallet', [Output::open(1000, 'genesis')]);

        self::assertSame(1000, $ledger->totalUnspentAmount());
        self::assertTrue($ledger->unspent()->contains(new OutputId('genesis')));
    }

    public function test_scalable_mode_applies_transaction(): void
    {
        $ledger = $this->createScalableLedger('test-wallet', [Output::open(1000, 'genesis')]);

        $ledger = $ledger->apply(Tx::create(
            spendIds: ['genesis'],
            outputs: [
                Output::open(600, 'alice'),
                Output::open(400, 'bob'),
            ],
        ));

        self::assertSame(1000, $ledger->totalUnspentAmount());
        self::assertFalse($ledger->unspent()->contains(new OutputId('genesis')));
        self::assertTrue($ledger->unspent()->contains(new OutputId('alice')));
        self::assertTrue($ledger->unspent()->contains(new OutputId('bob')));
    }

    public function test_scalable_mode_tracks_fees(): void
    {
        $ledger = $this->createScalableLedger('test-wallet', [Output::open(1000, 'genesis')]);

        $tx = Tx::create(
            spendIds: ['genesis'],
            outputs: [Output::open(990, 'output')],  // 10 units as fee
        );

        $ledger = $ledger->apply($tx);

        self::assertSame(10, $ledger->totalFeesCollected());
        self::assertSame(10, $ledger->feeForTx($tx->id));
    }

    public function test_scalable_mode_tracks_output_history(): void
    {
        $ledger = $this->createScalableLedger('test-wallet', [Output::open(1000, 'genesis')]);

        // Check genesis output history
        $history = $ledger->outputHistory(new OutputId('genesis'));
        self::assertNotNull($history);
        self::assertSame(1000, $history->amount);
        self::assertSame('genesis', $history->createdBy);
        self::assertTrue($history->isUnspent());

        // Spend the genesis output
        $tx = Tx::create(
            spendIds: ['genesis'],
            outputs: [Output::open(1000, 'output')],
        );
        $ledger = $ledger->apply($tx);

        // Check spent output history
        $spentHistory = $ledger->outputHistory(new OutputId('genesis'));
        self::assertNotNull($spentHistory);
        self::assertSame(1000, $spentHistory->amount);
        self::assertSame('genesis', $spentHistory->createdBy);
        self::assertSame($tx->id->value, $spentHistory->spentBy);
        self::assertTrue($spentHistory->isSpent());

        // Check new output history
        $newHistory = $ledger->outputHistory(new OutputId('output'));
        self::assertNotNull($newHistory);
        self::assertSame(1000, $newHistory->amount);
        self::assertSame($tx->id->value, $newHistory->createdBy);
        self::assertTrue($newHistory->isUnspent());
    }

    public function test_scalable_mode_tracks_coinbase(): void
    {
        $ledger = $this->createScalableLedger('test-wallet', [Output::open(100, 'genesis')]);

        $coinbase = CoinbaseTx::create(
            outputs: [Output::open(50, 'minted')],
            id: 'coinbase-1',
        );

        $ledger = $ledger->applyCoinbase($coinbase);

        self::assertSame(150, $ledger->totalUnspentAmount());
        self::assertSame(50, $ledger->totalMinted());
        self::assertTrue($ledger->isCoinbase($coinbase->id));
        self::assertSame(50, $ledger->coinbaseAmount($coinbase->id));
    }

    public function test_scalable_mode_output_exists(): void
    {
        $ledger = $this->createScalableLedger('test-wallet', [Output::open(1000, 'genesis')]);

        self::assertTrue($ledger->outputExists(new OutputId('genesis')));
        self::assertFalse($ledger->outputExists(new OutputId('nonexistent')));

        // Spend the output
        $ledger = $ledger->apply(Tx::create(
            spendIds: ['genesis'],
            outputs: [Output::open(1000, 'output')],
        ));

        // Spent output should still exist in history
        self::assertTrue($ledger->outputExists(new OutputId('genesis')));
        self::assertTrue($ledger->outputExists(new OutputId('output')));
    }

    public function test_scalable_mode_get_output(): void
    {
        $ledger = $this->createScalableLedger('test-wallet', [Output::open(1000, 'genesis')]);

        // Get unspent output
        $output = $ledger->getOutput(new OutputId('genesis'));
        self::assertNotNull($output);
        self::assertSame(1000, $output->amount);

        // Spend and get spent output
        $ledger = $ledger->apply(Tx::create(
            spendIds: ['genesis'],
            outputs: [Output::open(1000, 'output')],
        ));

        $spentOutput = $ledger->getOutput(new OutputId('genesis'));
        self::assertNotNull($spentOutput);
        self::assertSame(1000, $spentOutput->amount);
    }

    public function test_scalable_mode_multiple_transactions(): void
    {
        $ledger = $this->createScalableLedger('test-wallet', [Output::open(1000, 'genesis')]);

        // First transaction
        $ledger = $ledger->apply(Tx::create(
            spendIds: ['genesis'],
            outputs: [
                Output::open(600, 'alice'),
                Output::open(400, 'bob'),
            ],
        ));

        // Second transaction
        $ledger = $ledger->apply(Tx::create(
            spendIds: ['alice'],
            outputs: [
                Output::open(300, 'charlie'),
                Output::open(300, 'dave'),
            ],
        ));

        // Third transaction
        $ledger = $ledger->apply(Tx::create(
            spendIds: ['bob', 'charlie'],
            outputs: [Output::open(700, 'eve')],
        ));

        self::assertSame(1000, $ledger->totalUnspentAmount());
        self::assertSame(2, $ledger->unspent()->count());
        self::assertTrue($ledger->unspent()->contains(new OutputId('dave')));
        self::assertTrue($ledger->unspent()->contains(new OutputId('eve')));
    }

    public function test_exposes_history_store(): void
    {
        $ledger = $this->createScalableLedger('test-wallet', [Output::open(1000, 'genesis')]);

        $store = $ledger->historyStore();

        self::assertInstanceOf(SqliteHistoryStore::class, $store);
    }
    /**
     * Create a ScalableLedger with in-memory SQLite for testing.
     *
     * @param Output[] $genesis
     */
    private function createScalableLedger(string $ledgerId, array $genesis = []): ScalableLedger
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $schema = new SqliteSchema($pdo);
        $schema->create();

        // Initialize ledger record
        $pdo->exec("INSERT INTO ledgers (id, version, total_unspent, total_fees, total_minted) VALUES ('{$ledgerId}', 1, 0, 0, 0)");

        $store = new SqliteHistoryStore($pdo, $ledgerId);

        return ScalableLedger::create($store, ...$genesis);
    }
}
