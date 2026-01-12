<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Persistence\Sqlite;

use Chemaclass\Unspent\Persistence\AbstractLedgerRepository;
use Chemaclass\Unspent\Persistence\DatabaseSchema;
use Deprecated;
use PDO;

/**
 * SQLite schema management for the ledger persistence layer.
 *
 * Implements DatabaseSchema for instance-based usage:
 *
 *     $schema = new SqliteSchema($pdo);
 *     $schema->create();
 *
 * Also provides static methods for convenience:
 *
 *     SqliteSchema::createSchema($pdo);
 */
final readonly class SqliteSchema implements DatabaseSchema
{
    public function __construct(
        private PDO $pdo,
    ) {
    }

    public function create(): void
    {
        $this->pdo->exec($this->ledgersTable());
        $this->pdo->exec($this->outputsTable());
        $this->pdo->exec($this->transactionsTable());
        $this->createIndexes();
    }

    public function exists(): bool
    {
        $result = $this->pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='ledgers'",
        );

        return $result !== false && $result->fetch() !== false;
    }

    public function drop(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS transactions');
        $this->pdo->exec('DROP TABLE IF EXISTS outputs');
        $this->pdo->exec('DROP TABLE IF EXISTS ledgers');
    }

    public function getVersion(): int
    {
        return AbstractLedgerRepository::SCHEMA_VERSION;
    }

    #[Deprecated(message: 'Use instance method: (new SqliteSchema($pdo))->create()')]
    public static function createSchema(PDO $pdo): void
    {
        new self($pdo)->create();
    }

    /**
     * Check schema exists using static method.
     */
    #[Deprecated(message: 'Use instance method: (new SqliteSchema($pdo))->exists()')]
    public static function schemaExists(PDO $pdo): bool
    {
        return new self($pdo)->exists();
    }

    /**
     * Drop schema using static method.
     */
    #[Deprecated(message: 'Use instance method: (new SqliteSchema($pdo))->drop()')]
    public static function dropSchema(PDO $pdo): void
    {
        new self($pdo)->drop();
    }

    private function ledgersTable(): string
    {
        return <<<'SQL'
            CREATE TABLE IF NOT EXISTS ledgers (
                id TEXT PRIMARY KEY,
                version INTEGER NOT NULL DEFAULT 1,
                total_unspent INTEGER NOT NULL DEFAULT 0,
                total_fees INTEGER NOT NULL DEFAULT 0,
                total_minted INTEGER NOT NULL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
            SQL;
    }

    private function outputsTable(): string
    {
        return <<<'SQL'
            CREATE TABLE IF NOT EXISTS outputs (
                id TEXT NOT NULL,
                ledger_id TEXT NOT NULL,
                amount INTEGER NOT NULL,
                lock_type TEXT NOT NULL,
                lock_owner TEXT,
                lock_pubkey TEXT,
                lock_custom_data TEXT,
                is_spent INTEGER NOT NULL DEFAULT 0,
                created_by TEXT NOT NULL,
                spent_by TEXT,
                PRIMARY KEY (ledger_id, id),
                FOREIGN KEY (ledger_id) REFERENCES ledgers(id) ON DELETE CASCADE
            )
            SQL;
    }

    private function transactionsTable(): string
    {
        return <<<'SQL'
            CREATE TABLE IF NOT EXISTS transactions (
                id TEXT NOT NULL,
                ledger_id TEXT NOT NULL,
                is_coinbase INTEGER NOT NULL DEFAULT 0,
                signed_by TEXT,
                fee INTEGER,
                coinbase_amount INTEGER,
                PRIMARY KEY (ledger_id, id),
                FOREIGN KEY (ledger_id) REFERENCES ledgers(id) ON DELETE CASCADE
            )
            SQL;
    }

    private function createIndexes(): void
    {
        $indexes = [
            'CREATE INDEX IF NOT EXISTS idx_outputs_ledger_unspent ON outputs(ledger_id, is_spent)',
            'CREATE INDEX IF NOT EXISTS idx_outputs_owner ON outputs(ledger_id, lock_owner)',
            'CREATE INDEX IF NOT EXISTS idx_outputs_amount ON outputs(ledger_id, amount)',
            'CREATE INDEX IF NOT EXISTS idx_outputs_created_by ON outputs(ledger_id, created_by)',
            'CREATE INDEX IF NOT EXISTS idx_outputs_lock_type ON outputs(ledger_id, lock_type)',
            'CREATE INDEX IF NOT EXISTS idx_transactions_ledger ON transactions(ledger_id)',
            'CREATE INDEX IF NOT EXISTS idx_transactions_coinbase ON transactions(ledger_id, is_coinbase)',
        ];

        foreach ($indexes as $index) {
            $this->pdo->exec($index);
        }
    }
}
