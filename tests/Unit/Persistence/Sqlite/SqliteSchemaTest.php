<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit\Persistence\Sqlite;

use Chemaclass\Unspent\Persistence\AbstractLedgerRepository;
use Chemaclass\Unspent\Persistence\DatabaseSchema;
use Chemaclass\Unspent\Persistence\Sqlite\SqliteSchema;
use PDO;
use PHPUnit\Framework\TestCase;

final class SqliteSchemaTest extends TestCase
{
    public function test_implements_database_schema_interface(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $schema = new SqliteSchema($pdo);

        self::assertInstanceOf(DatabaseSchema::class, $schema);
    }

    public function test_create_creates_all_tables(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $schema = new SqliteSchema($pdo);
        $schema->create();

        $tables = $this->getTables($pdo);
        self::assertContains('ledgers', $tables);
        self::assertContains('outputs', $tables);
        self::assertContains('transactions', $tables);
    }

    public function test_exists_returns_true_after_create(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $schema = new SqliteSchema($pdo);

        self::assertFalse($schema->exists());

        $schema->create();

        self::assertTrue($schema->exists());
    }

    public function test_drop_removes_all_tables(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $schema = new SqliteSchema($pdo);
        $schema->create();
        $schema->drop();

        self::assertFalse($schema->exists());
        self::assertSame([], $this->getTables($pdo));
    }

    public function test_create_is_idempotent(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $schema = new SqliteSchema($pdo);
        $schema->create();
        $schema->create(); // Should not throw

        self::assertTrue($schema->exists());
    }

    public function test_get_version_returns_schema_version(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $schema = new SqliteSchema($pdo);

        self::assertSame(AbstractLedgerRepository::SCHEMA_VERSION, $schema->getVersion());
    }

    // ========================================================================
    // Static Methods (Deprecated but still need coverage)
    // ========================================================================

    public function test_static_create_schema(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        SqliteSchema::createSchema($pdo);

        $tables = $this->getTables($pdo);
        self::assertContains('ledgers', $tables);
    }

    public function test_static_schema_exists(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        self::assertFalse(SqliteSchema::schemaExists($pdo));

        SqliteSchema::createSchema($pdo);

        self::assertTrue(SqliteSchema::schemaExists($pdo));
    }

    public function test_static_drop_schema(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        SqliteSchema::createSchema($pdo);
        SqliteSchema::dropSchema($pdo);

        self::assertFalse(SqliteSchema::schemaExists($pdo));
    }

    /**
     * @return list<string>
     */
    private function getTables(PDO $pdo): array
    {
        $result = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'",
        );

        return $result !== false ? array_column($result->fetchAll(PDO::FETCH_ASSOC), 'name') : [];
    }
}
