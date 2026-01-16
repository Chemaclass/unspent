<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit\Persistence\Sqlite;

use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\Persistence\QueryableLedgerRepository;
use Chemaclass\Unspent\Persistence\Sqlite\SqliteRepositoryFactory;
use PDO;
use PHPUnit\Framework\TestCase;

final class SqliteRepositoryFactoryTest extends TestCase
{
    private string $tempDbPath;

    protected function setUp(): void
    {
        $this->tempDbPath = sys_get_temp_dir() . '/unspent_test_' . uniqid() . '.db';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempDbPath)) {
            unlink($this->tempDbPath);
        }
    }

    public function test_create_in_memory_returns_queryable_repository(): void
    {
        $repo = SqliteRepositoryFactory::createInMemory();

        self::assertInstanceOf(QueryableLedgerRepository::class, $repo);
    }

    public function test_create_in_memory_creates_working_repository(): void
    {
        $repo = SqliteRepositoryFactory::createInMemory();
        $ledger = Ledger::withGenesis(Output::ownedBy('alice', 100, 'funds'));

        $repo->save('test', $ledger);
        $loaded = $repo->find('test');

        self::assertNotNull($loaded);
        self::assertSame(100, $loaded->totalUnspentAmount());
    }

    public function test_create_from_file_returns_queryable_repository(): void
    {
        $repo = SqliteRepositoryFactory::createFromFile($this->tempDbPath);

        self::assertInstanceOf(QueryableLedgerRepository::class, $repo);
        self::assertFileExists($this->tempDbPath);
    }

    public function test_create_from_file_creates_working_repository(): void
    {
        $repo = SqliteRepositoryFactory::createFromFile($this->tempDbPath);
        $ledger = Ledger::withGenesis(Output::ownedBy('alice', 100, 'funds'));

        $repo->save('test', $ledger);
        $loaded = $repo->find('test');

        self::assertNotNull($loaded);
        self::assertSame(100, $loaded->totalUnspentAmount());
    }

    public function test_create_from_file_persists_data(): void
    {
        // First create and save
        $repo1 = SqliteRepositoryFactory::createFromFile($this->tempDbPath);
        $repo1->save('test', Ledger::withGenesis(Output::ownedBy('alice', 100, 'funds')));

        // Create new repository from same file
        $repo2 = SqliteRepositoryFactory::createFromFile($this->tempDbPath);
        $loaded = $repo2->find('test');

        self::assertNotNull($loaded);
        self::assertSame(100, $loaded->totalUnspentAmount());
    }

    public function test_create_from_pdo_returns_queryable_repository(): void
    {
        $pdo = new PDO('sqlite::memory:');

        $repo = SqliteRepositoryFactory::createFromPdo($pdo);

        self::assertInstanceOf(QueryableLedgerRepository::class, $repo);
    }

    public function test_create_from_pdo_creates_working_repository(): void
    {
        $pdo = new PDO('sqlite::memory:');

        $repo = SqliteRepositoryFactory::createFromPdo($pdo);
        $ledger = Ledger::withGenesis(Output::ownedBy('alice', 100, 'funds'));

        $repo->save('test', $ledger);
        $loaded = $repo->find('test');

        self::assertNotNull($loaded);
        self::assertSame(100, $loaded->totalUnspentAmount());
    }

    public function test_create_from_pdo_does_not_recreate_schema(): void
    {
        // Create repository and save data
        $pdo = new PDO('sqlite::memory:');
        $repo1 = SqliteRepositoryFactory::createFromPdo($pdo);
        $repo1->save('test', Ledger::withGenesis(Output::ownedBy('alice', 100, 'funds')));

        // Create another repository from same PDO
        $repo2 = SqliteRepositoryFactory::createFromPdo($pdo);

        // Data should still exist
        $loaded = $repo2->find('test');
        self::assertNotNull($loaded);
        self::assertSame(100, $loaded->totalUnspentAmount());
    }
}
