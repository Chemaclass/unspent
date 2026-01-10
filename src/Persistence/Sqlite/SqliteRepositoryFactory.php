<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Persistence\Sqlite;

use Chemaclass\Unspent\Persistence\QueryableLedgerRepository;
use PDO;

/**
 * Factory for creating SQLite ledger repositories.
 *
 * Returns the QueryableLedgerRepository interface for dependency inversion,
 * allowing consumers to depend on abstractions rather than concrete implementations.
 *
 * Responsibilities (SRP):
 * - PDO connection creation and configuration
 * - Schema initialization
 * - Repository instantiation
 */
final class SqliteRepositoryFactory
{
    private const PRAGMA_FOREIGN_KEYS = 'PRAGMA foreign_keys = ON';

    /**
     * Create a repository with an in-memory SQLite database.
     * Ideal for testing or temporary storage.
     */
    public static function createInMemory(): QueryableLedgerRepository
    {
        $pdo = self::createConfiguredPdo('sqlite::memory:');
        self::ensureSchema($pdo);

        return new SqliteLedgerRepository($pdo);
    }

    /**
     * Create a repository with a file-based SQLite database.
     * Creates the database file and schema if they don't exist.
     */
    public static function createFromFile(string $path): QueryableLedgerRepository
    {
        $pdo = self::createConfiguredPdo("sqlite:{$path}");
        self::ensureSchema($pdo);

        return new SqliteLedgerRepository($pdo);
    }

    /**
     * Create a repository from an existing PDO connection.
     * Configures the connection and creates schema if needed.
     *
     * This method follows DIP - accepts pre-created PDO for testability.
     */
    public static function createFromPdo(PDO $pdo): QueryableLedgerRepository
    {
        self::configurePdo($pdo);
        self::ensureSchema($pdo);

        return new SqliteLedgerRepository($pdo);
    }

    /**
     * Create and configure a new PDO connection.
     */
    private static function createConfiguredPdo(string $dsn): PDO
    {
        $pdo = new PDO($dsn);
        self::configurePdo($pdo);

        return $pdo;
    }

    /**
     * Configure PDO with required settings.
     */
    private static function configurePdo(PDO $pdo): void
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(self::PRAGMA_FOREIGN_KEYS);
    }

    /**
     * Ensure schema exists, creating if necessary.
     */
    private static function ensureSchema(PDO $pdo): void
    {
        $schema = new SqliteSchema($pdo);
        if (!$schema->exists()) {
            $schema->create();
        }
    }
}
