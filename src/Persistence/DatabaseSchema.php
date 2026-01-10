<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Persistence;

/**
 * Interface for database schema management.
 *
 * Implement this interface to provide schema creation and management
 * for custom database backends.
 *
 * Example implementation for MySQL:
 *
 *     class MySQLSchema implements DatabaseSchema
 *     {
 *         public function __construct(private PDO $pdo) {}
 *
 *         public function create(): void
 *         {
 *             $this->pdo->exec('CREATE TABLE ledgers (...)');
 *             $this->pdo->exec('CREATE TABLE outputs (...)');
 *             $this->pdo->exec('CREATE TABLE transactions (...)');
 *         }
 *
 *         public function exists(): bool
 *         {
 *             // Check if tables exist
 *         }
 *
 *         public function drop(): void
 *         {
 *             $this->pdo->exec('DROP TABLE IF EXISTS transactions');
 *             $this->pdo->exec('DROP TABLE IF EXISTS outputs');
 *             $this->pdo->exec('DROP TABLE IF EXISTS ledgers');
 *         }
 *
 *         public function getVersion(): int
 *         {
 *             return AbstractLedgerRepository::SCHEMA_VERSION;
 *         }
 *     }
 */
interface DatabaseSchema
{
    /**
     * Create all required tables and indexes.
     *
     * Should be idempotent - safe to call multiple times.
     */
    public function create(): void;

    /**
     * Check if the schema exists.
     *
     * @return bool True if the schema tables exist
     */
    public function exists(): bool;

    /**
     * Drop all tables.
     *
     * Use with caution - this deletes all data.
     * Primarily for testing and reset scenarios.
     */
    public function drop(): void;

    /**
     * Get the schema version.
     *
     * Used for migration compatibility checks.
     */
    public function getVersion(): int;
}
