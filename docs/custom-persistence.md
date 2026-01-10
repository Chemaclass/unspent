# Custom Database Persistence

This guide explains how to implement custom database persistence for other database backends (MySQL, PostgreSQL, MongoDB, etc.).

## Architecture Overview

The persistence layer follows SOLID principles:

```
LedgerRepository (interface)
    ↑
QueryableLedgerRepository (interface) - adds query methods
    ↑
AbstractLedgerRepository (abstract class) - shared logic
    ↑
SqliteLedgerRepository / YourCustomRepository (concrete)
```

**Key components:**

| Component                    | Purpose                                            |
|------------------------------|----------------------------------------------------|
| `LedgerRepository`           | Basic CRUD interface (save, find, delete, exists)  |
| `QueryableLedgerRepository`  | Extended interface with query methods              |
| `AbstractLedgerRepository`   | Shared logic for lock normalization, row conversion|
| `DatabaseSchema`             | Interface for schema creation/management           |

**SOLID Compliance:**

| Principle                 | Implementation                                                   |
|---------------------------|------------------------------------------------------------------|
| **S**ingle Responsibility | Each class has one job (CRUD, queries, schema, conversion)       |
| **O**pen/Closed           | Extend `AbstractLedgerRepository` without modifying library code |
| **L**iskov Substitution   | Any implementation can substitute the interface                  |
| **I**nterface Segregation | `LedgerRepository` (basic) vs `QueryableLedgerRepository` (full) |
| **D**ependency Inversion  | Factories return interfaces, not concrete classes                |

## Implementing a Custom Repository

### Step 1: Extend AbstractLedgerRepository

```php
<?php

use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Persistence\AbstractLedgerRepository;
use Chemaclass\Unspent\Persistence\PersistenceException;
use PDO;

final class MySQLLedgerRepository extends AbstractLedgerRepository
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    public function save(string $id, Ledger $ledger): void
    {
        try {
            $this->pdo->beginTransaction();

            // Delete existing data
            $this->pdo->prepare('DELETE FROM ledgers WHERE id = ?')->execute([$id]);

            // Insert ledger
            $stmt = $this->pdo->prepare(
                'INSERT INTO ledgers (id, version, total_unspent) VALUES (?, ?, ?)'
            );
            $stmt->execute([
                $id,
                self::SCHEMA_VERSION,  // Use inherited constant
                $ledger->totalUnspentAmount(),
            ]);

            // Insert outputs using inherited helper
            foreach ($ledger->unspent() as $outputId => $output) {
                $lockData = $this->extractLockData($output);  // Inherited method
                // ... insert into outputs table
            }

            $this->pdo->commit();
        } catch (\PDOException $e) {
            $this->pdo->rollBack();
            throw PersistenceException::saveFailed($id, $e->getMessage());
        }
    }

    public function find(string $id): ?Ledger
    {
        // Fetch rows from database
        $unspentRows = $this->fetchUnspentRows($id);
        $spentRows = $this->fetchSpentRows($id);
        $txRows = $this->fetchTransactionRows($id);

        if (empty($unspentRows) && empty($spentRows) && empty($txRows)) {
            return null;
        }

        // Use inherited helper to build ledger data
        $data = $this->buildLedgerDataArray($unspentRows, $spentRows, $txRows);

        return Ledger::fromArray($data);
    }

    public function delete(string $id): void
    {
        $this->pdo->prepare('DELETE FROM ledgers WHERE id = ?')->execute([$id]);
    }

    public function exists(string $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM ledgers WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() !== false;
    }

    // Implement query methods...
    public function findUnspentByOwner(string $ledgerId, string $owner): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM outputs WHERE ledger_id = ? AND lock_owner = ? AND is_spent = 0'
        );
        $stmt->execute([$ledgerId, $owner]);

        // Use inherited helper to convert rows
        return $this->rowsToOutputs($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // ... implement remaining query methods
}
```

### Step 2: Implement DatabaseSchema (Optional)

```php
<?php

use Chemaclass\Unspent\Persistence\AbstractLedgerRepository;
use Chemaclass\Unspent\Persistence\DatabaseSchema;
use PDO;

final class MySQLSchema implements DatabaseSchema
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    public function create(): void
    {
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS ledgers (
                id VARCHAR(64) PRIMARY KEY,
                version INT NOT NULL DEFAULT 1,
                total_unspent BIGINT NOT NULL DEFAULT 0,
                total_fees BIGINT NOT NULL DEFAULT 0,
                total_minted BIGINT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS outputs (
                id VARCHAR(64) NOT NULL,
                ledger_id VARCHAR(64) NOT NULL,
                amount BIGINT NOT NULL,
                lock_type VARCHAR(32) NOT NULL,
                lock_owner VARCHAR(255),
                lock_pubkey TEXT,
                lock_custom_data JSON,
                is_spent BOOLEAN NOT NULL DEFAULT FALSE,
                created_by VARCHAR(64) NOT NULL,
                spent_by VARCHAR(64),
                PRIMARY KEY (ledger_id, id),
                FOREIGN KEY (ledger_id) REFERENCES ledgers(id) ON DELETE CASCADE,
                INDEX idx_owner (ledger_id, lock_owner),
                INDEX idx_amount (ledger_id, amount),
                INDEX idx_unspent (ledger_id, is_spent)
            ) ENGINE=InnoDB
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS transactions (
                id VARCHAR(64) NOT NULL,
                ledger_id VARCHAR(64) NOT NULL,
                is_coinbase BOOLEAN NOT NULL DEFAULT FALSE,
                fee BIGINT,
                coinbase_amount BIGINT,
                PRIMARY KEY (ledger_id, id),
                FOREIGN KEY (ledger_id) REFERENCES ledgers(id) ON DELETE CASCADE
            ) ENGINE=InnoDB
        ');
    }

    public function exists(): bool
    {
        $result = $this->pdo->query("SHOW TABLES LIKE 'ledgers'");
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
}
```

### Step 3: Create a Factory (Optional)

Following the Dependency Inversion Principle, provide multiple factory methods:

```php
<?php

use Chemaclass\Unspent\Persistence\QueryableLedgerRepository;
use PDO;

final class MySQLRepositoryFactory
{
    /**
     * Create from existing PDO connection (DIP - inject dependency).
     */
    public static function createFromPdo(PDO $pdo): QueryableLedgerRepository
    {
        $schema = new MySQLSchema($pdo);
        if (!$schema->exists()) {
            $schema->create();
        }

        return new MySQLLedgerRepository($pdo);
    }

    /**
     * Convenience method for quick setup.
     */
    public static function create(
        string $host,
        string $database,
        string $user,
        string $password,
    ): QueryableLedgerRepository {
        $pdo = new PDO(
            "mysql:host={$host};dbname={$database};charset=utf8mb4",
            $user,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        return self::createFromPdo($pdo);
    }
}
```

## Inherited Helper Methods

`AbstractLedgerRepository` provides these protected methods:

### extractLockData(Output $output)

Normalizes an output's lock into database columns:

```php
$lockData = $this->extractLockData($output);
// Returns: ['type' => 'owner', 'owner' => 'alice', 'pubkey' => null, 'custom' => null]
```

### rowToLockArray(array $row)

Converts database columns back to a lock array:

```php
$lockArray = $this->rowToLockArray($row);
// Returns: ['type' => 'owner', 'name' => 'alice']
```

### rowsToOutputs(array $rows)

Converts database rows to Output objects:

```php
$outputs = $this->rowsToOutputs($rows);
// Returns: list<Output>
```

### buildLedgerDataArray(...)

Builds the array format expected by `Ledger::fromArray()`:

```php
$data = $this->buildLedgerDataArray($unspentRows, $spentRows, $txRows);
$ledger = Ledger::fromArray($data);
```

## Expected Column Names

The inherited methods expect these column names in database rows:

| Column             | Purpose                                          |
|--------------------|--------------------------------------------------|
| `id`               | Output/transaction ID                            |
| `amount`           | Output amount                                    |
| `lock_type`        | Lock type: 'owner', 'pubkey', 'none', or custom  |
| `lock_owner`       | Owner name (for owner locks)                     |
| `lock_pubkey`      | Base64 key (for pubkey locks)                    |
| `lock_custom_data` | JSON string (for custom locks)                   |
| `is_spent`         | Boolean/integer flag                             |
| `created_by`       | Transaction ID or 'genesis'                      |
| `spent_by`         | Transaction ID that spent the output             |
| `is_coinbase`      | Boolean/integer flag for coinbase transactions   |
| `fee`              | Transaction fee                                  |
| `coinbase_amount`  | Minted amount for coinbase transactions          |

## Implementing Basic Repository Only

If you only need basic CRUD without query methods, implement `LedgerRepository`:

```php
<?php

use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Persistence\LedgerRepository;

final class RedisLedgerRepository implements LedgerRepository
{
    public function __construct(private \Redis $redis) {}

    public function save(string $id, Ledger $ledger): void
    {
        $this->redis->set("ledger:{$id}", $ledger->toJson());
    }

    public function find(string $id): ?Ledger
    {
        $json = $this->redis->get("ledger:{$id}");
        return $json ? Ledger::fromJson($json) : null;
    }

    public function delete(string $id): void
    {
        $this->redis->del("ledger:{$id}");
    }

    public function exists(string $id): bool
    {
        return $this->redis->exists("ledger:{$id}") > 0;
    }
}
```

## Tips

1. **Use `self::SCHEMA_VERSION`** instead of hardcoding version numbers
2. **Use inherited helpers** to avoid duplicating lock normalization logic
3. **Return interface types** from factories for dependency inversion
4. **Handle custom locks** - they're stored as JSON in `lock_custom_data`
5. **Test with in-memory SQLite** first, then adapt for your target database

## See Also

- [Persistence](persistence.md) - Built-in SQLite persistence
- [API Reference](api-reference.md) - Complete method reference
