# Custom Database Persistence

Build your own database backend (MySQL, PostgreSQL, MongoDB, etc.).

## Architecture

```
LedgerRepository (interface)
    ↑
QueryableLedgerRepository (interface) - adds queries
    ↑
AbstractLedgerRepository (abstract) - shared logic
    ↑
YourCustomRepository (concrete)
```

## Basic Implementation

For simple key-value storage (Redis, file-based):

```php
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

## Full Implementation with Queries

Extend `AbstractLedgerRepository` for normalized storage:

```php
use Chemaclass\Unspent\Persistence\AbstractLedgerRepository;

final class MySQLLedgerRepository extends AbstractLedgerRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function save(string $id, Ledger $ledger): void
    {
        $this->pdo->beginTransaction();

        // Delete existing
        $this->pdo->prepare('DELETE FROM outputs WHERE ledger_id = ?')
            ->execute([$id]);

        // Insert outputs with inherited helper
        foreach ($ledger->unspent() as $outputId => $output) {
            $lockData = $this->extractLockData($output); // Inherited
            // Insert into outputs table...
        }

        $this->pdo->commit();
    }

    public function find(string $id): ?Ledger
    {
        $rows = $this->fetchRows($id);
        if (empty($rows)) return null;

        // Use inherited helper
        $data = $this->buildLedgerDataArray($unspent, $spent, $txs);
        return Ledger::fromArray($data);
    }

    // Implement remaining methods...
}
```

## Inherited Helpers

`AbstractLedgerRepository` provides:

```php
// Lock normalization
$this->extractLockData($output);
// Returns: ['type', 'owner', 'pubkey', 'custom']

// Row conversion
$this->rowsToOutputs($rows);
// Returns: list<Output>

// Build ledger data
$this->buildLedgerDataArray($unspent, $spent, $txs);
// Returns: array for Ledger::fromArray()
```

## Expected Column Names

| Column | Purpose |
|-|-|
| `id` | Output ID |
| `amount` | Output amount |
| `lock_type` | 'owner', 'pubkey', 'none', or custom |
| `lock_owner` | Owner name |
| `lock_pubkey` | Base64 public key |
| `lock_custom_data` | JSON for custom locks |
| `is_spent` | Boolean flag |
| `created_by` | Tx ID or 'genesis' |
| `spent_by` | Tx ID that spent it |

## Factory Pattern

```php
final class MySQLRepositoryFactory
{
    public static function createFromPdo(PDO $pdo): QueryableLedgerRepository
    {
        $schema = new MySQLSchema($pdo);
        if (!$schema->exists()) {
            $schema->create();
        }
        return new MySQLLedgerRepository($pdo);
    }
}
```

## Next Steps

- [Persistence](persistence.md) - Built-in SQLite persistence
- [API Reference](api-reference.md) - Complete method reference
