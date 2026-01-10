<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit\Persistence\Sqlite;

use Chemaclass\Unspent\CoinbaseTx;
use Chemaclass\Unspent\InMemoryLedger;
use Chemaclass\Unspent\Lock\LockFactory;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\OutputLock;
use Chemaclass\Unspent\Persistence\Sqlite\SqliteRepositoryFactory;
use Chemaclass\Unspent\Tx;
use PHPUnit\Framework\TestCase;

final class SqliteLedgerRepositoryTest extends TestCase
{
    protected function tearDown(): void
    {
        LockFactory::reset();
    }

    // ========================================================================
    // Basic CRUD Operations
    // ========================================================================

    public function test_save_and_load_empty_ledger(): void
    {
        $repo = SqliteRepositoryFactory::createInMemory();
        $ledger = InMemoryLedger::empty();

        $repo->save('test', $ledger);
        $loaded = $repo->find('test');

        self::assertNotNull($loaded);
        self::assertSame(0, $loaded->totalUnspentAmount());
    }

    public function test_save_and_load_ledger_with_genesis(): void
    {
        $repo = SqliteRepositoryFactory::createInMemory();
        $ledger = InMemoryLedger::withGenesis(
            Output::ownedBy('alice', 1000, 'alice-funds'),
            Output::ownedBy('bob', 500, 'bob-funds'),
        );

        $repo->save('wallet', $ledger);
        $loaded = $repo->find('wallet');

        self::assertNotNull($loaded);
        self::assertSame(1500, $loaded->totalUnspentAmount());

        $alice = $loaded->unspent()->get(new OutputId('alice-funds'));
        self::assertNotNull($alice);
        self::assertSame(1000, $alice->amount);

        $bob = $loaded->unspent()->get(new OutputId('bob-funds'));
        self::assertNotNull($bob);
        self::assertSame(500, $bob->amount);
    }

    public function test_save_and_load_ledger_with_transactions(): void
    {
        $repo = SqliteRepositoryFactory::createInMemory();
        $ledger = InMemoryLedger::withGenesis(
            Output::ownedBy('alice', 1000, 'alice-funds'),
        )->apply(Tx::create(
            spendIds: ['alice-funds'],
            outputs: [
                Output::ownedBy('bob', 600, 'bob-funds'),
                Output::ownedBy('alice', 390, 'alice-change'),
            ],
            signedBy: 'alice',
            id: 'tx-1',
        ));

        $repo->save('wallet', $ledger);
        $loaded = $repo->find('wallet');

        self::assertNotNull($loaded);
        self::assertSame(990, $loaded->totalUnspentAmount());
        self::assertSame(10, $loaded->totalFeesCollected());
        self::assertSame(10, $loaded->feeForTx(new \Chemaclass\Unspent\TxId('tx-1')));
    }

    public function test_load_returns_null_for_nonexistent_ledger(): void
    {
        $repo = SqliteRepositoryFactory::createInMemory();

        self::assertNull($repo->find('nonexistent'));
    }

    public function test_exists_returns_true_for_existing_ledger(): void
    {
        $repo = SqliteRepositoryFactory::createInMemory();
        $repo->save('test', InMemoryLedger::empty());

        self::assertTrue($repo->exists('test'));
    }

    public function test_exists_returns_false_for_nonexistent_ledger(): void
    {
        $repo = SqliteRepositoryFactory::createInMemory();

        self::assertFalse($repo->exists('nonexistent'));
    }

    public function test_delete_removes_ledger(): void
    {
        $repo = SqliteRepositoryFactory::createInMemory();
        $repo->save('test', InMemoryLedger::empty());

        $repo->delete('test');

        self::assertFalse($repo->exists('test'));
        self::assertNull($repo->find('test'));
    }

    public function test_save_overwrites_existing_ledger(): void
    {
        $repo = SqliteRepositoryFactory::createInMemory();

        $ledger1 = InMemoryLedger::withGenesis(Output::ownedBy('alice', 100, 'funds'));
        $repo->save('test', $ledger1);

        $ledger2 = InMemoryLedger::withGenesis(Output::ownedBy('bob', 200, 'funds'));
        $repo->save('test', $ledger2);

        $loaded = $repo->find('test');
        self::assertNotNull($loaded);
        self::assertSame(200, $loaded->totalUnspentAmount());
    }

    // ========================================================================
    // History Preservation
    // ========================================================================

    public function test_preserves_output_created_by(): void
    {
        $repo = SqliteRepositoryFactory::createInMemory();
        $ledger = InMemoryLedger::withGenesis(
            Output::ownedBy('alice', 1000, 'genesis-output'),
        )->apply(Tx::create(
            spendIds: ['genesis-output'],
            outputs: [Output::ownedBy('bob', 1000, 'tx-output')],
            signedBy: 'alice',
            id: 'tx-1',
        ));

        $repo->save('test', $ledger);
        $loaded = $repo->find('test');

        self::assertNotNull($loaded);
        self::assertSame('genesis', $loaded->outputCreatedBy(new OutputId('genesis-output')));
        self::assertSame('tx-1', $loaded->outputCreatedBy(new OutputId('tx-output')));
    }

    public function test_preserves_output_spent_by(): void
    {
        $repo = SqliteRepositoryFactory::createInMemory();
        $ledger = InMemoryLedger::withGenesis(
            Output::ownedBy('alice', 1000, 'funds'),
        )->apply(Tx::create(
            spendIds: ['funds'],
            outputs: [Output::ownedBy('bob', 1000, 'bob-funds')],
            signedBy: 'alice',
            id: 'tx-1',
        ));

        $repo->save('test', $ledger);
        $loaded = $repo->find('test');

        self::assertNotNull($loaded);
        self::assertSame('tx-1', $loaded->outputSpentBy(new OutputId('funds')));
    }

    public function test_preserves_spent_outputs(): void
    {
        $repo = SqliteRepositoryFactory::createInMemory();
        $ledger = InMemoryLedger::withGenesis(
            Output::ownedBy('alice', 1000, 'spent-output'),
        )->apply(Tx::create(
            spendIds: ['spent-output'],
            outputs: [Output::ownedBy('bob', 1000, 'new-output')],
            signedBy: 'alice',
        ));

        $repo->save('test', $ledger);
        $loaded = $repo->find('test');

        self::assertNotNull($loaded);
        $spentOutput = $loaded->getOutput(new OutputId('spent-output'));
        self::assertNotNull($spentOutput);
        self::assertSame(1000, $spentOutput->amount);
    }

    // ========================================================================
    // Lock Types
    // ========================================================================

    public function test_preserves_owner_lock(): void
    {
        $repo = SqliteRepositoryFactory::createInMemory();
        $ledger = InMemoryLedger::withGenesis(Output::ownedBy('alice', 100, 'funds'));

        $repo->save('test', $ledger);
        $loaded = $repo->find('test');

        self::assertNotNull($loaded);
        $output = $loaded->unspent()->get(new OutputId('funds'));
        self::assertNotNull($output);
        self::assertSame(['type' => 'owner', 'name' => 'alice'], $output->lock->toArray());
    }

    public function test_preserves_no_lock(): void
    {
        $repo = SqliteRepositoryFactory::createInMemory();
        $ledger = InMemoryLedger::withGenesis(Output::open(100, 'open-funds'));

        $repo->save('test', $ledger);
        $loaded = $repo->find('test');

        self::assertNotNull($loaded);
        $output = $loaded->unspent()->get(new OutputId('open-funds'));
        self::assertNotNull($output);
        self::assertSame(['type' => 'none'], $output->lock->toArray());
    }

    public function test_preserves_pubkey_lock(): void
    {
        $repo = SqliteRepositoryFactory::createInMemory();

        $keypair = sodium_crypto_sign_keypair();
        $publicKey = base64_encode(sodium_crypto_sign_publickey($keypair));

        $ledger = InMemoryLedger::withGenesis(Output::signedBy($publicKey, 100, 'crypto-funds'));

        $repo->save('test', $ledger);
        $loaded = $repo->find('test');

        self::assertNotNull($loaded);
        $output = $loaded->unspent()->get(new OutputId('crypto-funds'));
        self::assertNotNull($output);
        self::assertSame(['type' => 'pubkey', 'key' => $publicKey], $output->lock->toArray());
    }

    public function test_preserves_custom_lock(): void
    {
        // Register custom lock handler
        LockFactory::register('timelock', static fn (array $data): OutputLock => new class($data['unlockTimestamp'], $data['owner']) implements OutputLock {
            public function __construct(
                public int $unlockTimestamp,
                public string $owner,
            ) {
            }

            public function validate(Tx $tx, int $inputIndex): void
            {
            }

            public function toArray(): array
            {
                return [
                    'type' => 'timelock',
                    'unlockTimestamp' => $this->unlockTimestamp,
                    'owner' => $this->owner,
                ];
            }
        });

        $repo = SqliteRepositoryFactory::createInMemory();
        $customLock = new class(strtotime('2020-01-01'), 'alice') implements OutputLock {
            public function __construct(
                public int $unlockTimestamp,
                public string $owner,
            ) {
            }

            public function validate(Tx $tx, int $inputIndex): void
            {
            }

            public function toArray(): array
            {
                return [
                    'type' => 'timelock',
                    'unlockTimestamp' => $this->unlockTimestamp,
                    'owner' => $this->owner,
                ];
            }
        };

        $ledger = InMemoryLedger::withGenesis(Output::lockedWith($customLock, 100, 'locked-funds'));

        $repo->save('test', $ledger);
        $loaded = $repo->find('test');

        self::assertNotNull($loaded);
        $output = $loaded->unspent()->get(new OutputId('locked-funds'));
        self::assertNotNull($output);
        self::assertSame([
            'type' => 'timelock',
            'unlockTimestamp' => strtotime('2020-01-01'),
            'owner' => 'alice',
        ], $output->lock->toArray());
    }

    // ========================================================================
    // Coinbase Transactions
    // ========================================================================

    public function test_preserves_coinbase_transactions(): void
    {
        $repo = SqliteRepositoryFactory::createInMemory();
        $ledger = InMemoryLedger::empty()->applyCoinbase(CoinbaseTx::create(
            outputs: [Output::ownedBy('miner', 50, 'reward')],
            id: 'coinbase-1',
        ));

        $repo->save('test', $ledger);
        $loaded = $repo->find('test');

        self::assertNotNull($loaded);
        self::assertTrue($loaded->isCoinbase(new \Chemaclass\Unspent\TxId('coinbase-1')));
        self::assertSame(50, $loaded->coinbaseAmount(new \Chemaclass\Unspent\TxId('coinbase-1')));
        self::assertSame(50, $loaded->totalMinted());
    }

    // ========================================================================
    // Query Methods
    // ========================================================================

    public function test_find_unspent_by_owner(): void
    {
        $repo = SqliteRepositoryFactory::createInMemory();
        $ledger = InMemoryLedger::withGenesis(
            Output::ownedBy('alice', 100, 'alice-1'),
            Output::ownedBy('alice', 200, 'alice-2'),
            Output::ownedBy('bob', 300, 'bob-1'),
        );
        $repo->save('test', $ledger);

        $aliceOutputs = $repo->findUnspentByOwner('test', 'alice');

        self::assertCount(2, $aliceOutputs);
        $amounts = array_map(static fn (Output $o): int => $o->amount, $aliceOutputs);
        sort($amounts);
        self::assertSame([100, 200], $amounts);
    }

    public function test_find_unspent_by_amount_range(): void
    {
        $repo = SqliteRepositoryFactory::createInMemory();
        $ledger = InMemoryLedger::withGenesis(
            Output::ownedBy('alice', 50, 'small'),
            Output::ownedBy('alice', 150, 'medium'),
            Output::ownedBy('alice', 500, 'large'),
        );
        $repo->save('test', $ledger);

        $outputs = $repo->findUnspentByAmountRange('test', 100, 200);

        self::assertCount(1, $outputs);
        self::assertSame(150, $outputs[0]->amount);
    }

    public function test_find_unspent_by_amount_range_no_max(): void
    {
        $repo = SqliteRepositoryFactory::createInMemory();
        $ledger = InMemoryLedger::withGenesis(
            Output::ownedBy('alice', 50, 'small'),
            Output::ownedBy('alice', 150, 'medium'),
            Output::ownedBy('alice', 500, 'large'),
        );
        $repo->save('test', $ledger);

        $outputs = $repo->findUnspentByAmountRange('test', 100);

        self::assertCount(2, $outputs);
    }

    public function test_find_unspent_by_lock_type(): void
    {
        $repo = SqliteRepositoryFactory::createInMemory();

        $keypair = sodium_crypto_sign_keypair();
        $publicKey = base64_encode(sodium_crypto_sign_publickey($keypair));

        $ledger = InMemoryLedger::withGenesis(
            Output::ownedBy('alice', 100, 'owner-lock'),
            Output::open(50, 'no-lock'),
            Output::signedBy($publicKey, 200, 'pubkey-lock'),
        );
        $repo->save('test', $ledger);

        $ownerOutputs = $repo->findUnspentByLockType('test', 'owner');
        self::assertCount(1, $ownerOutputs);
        self::assertSame(100, $ownerOutputs[0]->amount);

        $pubkeyOutputs = $repo->findUnspentByLockType('test', 'pubkey');
        self::assertCount(1, $pubkeyOutputs);
        self::assertSame(200, $pubkeyOutputs[0]->amount);

        $noLockOutputs = $repo->findUnspentByLockType('test', 'none');
        self::assertCount(1, $noLockOutputs);
        self::assertSame(50, $noLockOutputs[0]->amount);
    }

    public function test_find_outputs_created_by(): void
    {
        $repo = SqliteRepositoryFactory::createInMemory();
        $ledger = InMemoryLedger::withGenesis(
            Output::ownedBy('alice', 1000, 'genesis-output'),
        )->apply(Tx::create(
            spendIds: ['genesis-output'],
            outputs: [
                Output::ownedBy('bob', 600, 'bob-output'),
                Output::ownedBy('alice', 400, 'alice-change'),
            ],
            signedBy: 'alice',
            id: 'tx-1',
        ));
        $repo->save('test', $ledger);

        $txOutputs = $repo->findOutputsCreatedBy('test', 'tx-1');
        self::assertCount(2, $txOutputs);

        $genesisOutputs = $repo->findOutputsCreatedBy('test', 'genesis');
        self::assertCount(1, $genesisOutputs);
    }

    public function test_count_unspent(): void
    {
        $repo = SqliteRepositoryFactory::createInMemory();
        $ledger = InMemoryLedger::withGenesis(
            Output::ownedBy('alice', 100, 'a'),
            Output::ownedBy('bob', 200, 'b'),
            Output::ownedBy('charlie', 300, 'c'),
        );
        $repo->save('test', $ledger);

        self::assertSame(3, $repo->countUnspent('test'));
    }

    public function test_sum_unspent_by_owner(): void
    {
        $repo = SqliteRepositoryFactory::createInMemory();
        $ledger = InMemoryLedger::withGenesis(
            Output::ownedBy('alice', 100, 'alice-1'),
            Output::ownedBy('alice', 250, 'alice-2'),
            Output::ownedBy('bob', 300, 'bob-1'),
        );
        $repo->save('test', $ledger);

        self::assertSame(350, $repo->sumUnspentByOwner('test', 'alice'));
        self::assertSame(300, $repo->sumUnspentByOwner('test', 'bob'));
        self::assertSame(0, $repo->sumUnspentByOwner('test', 'charlie'));
    }

    public function test_find_coinbase_transactions(): void
    {
        $repo = SqliteRepositoryFactory::createInMemory();
        $ledger = InMemoryLedger::empty()
            ->applyCoinbase(CoinbaseTx::create([Output::ownedBy('miner', 50, 'r1')], 'cb-1'))
            ->applyCoinbase(CoinbaseTx::create([Output::ownedBy('miner', 50, 'r2')], 'cb-2'))
            ->apply(Tx::create(['r1'], [Output::ownedBy('alice', 50, 'a1')], 'miner', 'tx-1'));
        $repo->save('test', $ledger);

        $coinbases = $repo->findCoinbaseTransactions('test');
        sort($coinbases);

        self::assertSame(['cb-1', 'cb-2'], $coinbases);
    }

    public function test_find_transactions_by_fee_range(): void
    {
        $repo = SqliteRepositoryFactory::createInMemory();
        $ledger = InMemoryLedger::withGenesis(
            Output::ownedBy('alice', 1000, 'funds'),
        )->apply(Tx::create(
            spendIds: ['funds'],
            outputs: [Output::ownedBy('bob', 990, 'bob-funds')], // 10 fee
            signedBy: 'alice',
            id: 'low-fee-tx',
        ));

        $ledger = $ledger->applyCoinbase(CoinbaseTx::create(
            [Output::ownedBy('alice', 500, 'more-funds')],
            'cb-1',
        ))->apply(Tx::create(
            spendIds: ['more-funds'],
            outputs: [Output::ownedBy('charlie', 450, 'charlie-funds')], // 50 fee
            signedBy: 'alice',
            id: 'high-fee-tx',
        ));

        $repo->save('test', $ledger);

        $lowFees = $repo->findTransactionsByFeeRange('test', 0, 20);
        self::assertCount(1, $lowFees);
        self::assertSame('low-fee-tx', $lowFees[0]->id);
        self::assertSame(10, $lowFees[0]->fee);

        $highFees = $repo->findTransactionsByFeeRange('test', 30);
        self::assertCount(1, $highFees);
        self::assertSame('high-fee-tx', $highFees[0]->id);
    }

    // ========================================================================
    // Edge Cases
    // ========================================================================

    public function test_multiple_ledgers_are_isolated(): void
    {
        $repo = SqliteRepositoryFactory::createInMemory();

        $repo->save('ledger-1', InMemoryLedger::withGenesis(Output::ownedBy('alice', 100, 'a')));
        $repo->save('ledger-2', InMemoryLedger::withGenesis(Output::ownedBy('bob', 200, 'b')));

        $ledger1 = $repo->find('ledger-1');
        $ledger2 = $repo->find('ledger-2');

        self::assertNotNull($ledger1);
        self::assertNotNull($ledger2);
        self::assertSame(100, $ledger1->totalUnspentAmount());
        self::assertSame(200, $ledger2->totalUnspentAmount());
    }

    public function test_query_returns_empty_for_nonexistent_ledger(): void
    {
        $repo = SqliteRepositoryFactory::createInMemory();

        self::assertSame([], $repo->findUnspentByOwner('nonexistent', 'alice'));
        self::assertSame(0, $repo->countUnspent('nonexistent'));
        self::assertSame(0, $repo->sumUnspentByOwner('nonexistent', 'alice'));
    }
}
