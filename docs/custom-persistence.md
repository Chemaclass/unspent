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
use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\LedgerInterface;
use Chemaclass\Unspent\Persistence\LedgerRepository;

final class RedisLedgerRepository implements LedgerRepository
{
    public function __construct(private \Redis $redis) {}

    public function save(string $id, LedgerInterface $ledger): void
    {
        $this->redis->set("ledger:{$id}", $ledger->toJson());
    }

    public function find(string $id): ?LedgerInterface
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
use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\LedgerInterface;
use Chemaclass\Unspent\Persistence\AbstractLedgerRepository;

final class MySQLLedgerRepository extends AbstractLedgerRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function save(string $id, LedgerInterface $ledger): void
    {
        $this->pdo->beginTransaction();

        // Delete existing
        $this->pdo->prepare('DELETE FROM outputs WHERE ledger_id = ?')
            ->execute([$id]);

        // Insert outputs with inherited helper
        foreach ($ledger->unspent() as $outputId => $output) {
            $lock = $this->extractLockData($output); // Inherited: returns LockData
            // INSERT ($outputId, $output->amount, $lock->type, $lock->owner, ...)
        }

        $this->pdo->commit();
    }

    public function find(string $id): ?LedgerInterface
    {
        $unspentRows = $this->select('SELECT * FROM outputs WHERE ledger_id = ? AND is_spent = 0', $id);
        $spentRows   = $this->select('SELECT * FROM outputs WHERE ledger_id = ? AND is_spent = 1', $id);
        $txRows      = $this->select('SELECT * FROM transactions WHERE ledger_id = ?', $id);

        if ($unspentRows === [] && $spentRows === [] && $txRows === []) {
            return null;
        }

        // Inherited: assembles the array Ledger::fromArray() expects
        $data = $this->buildLedgerDataArray($unspentRows, $spentRows, $txRows);

        return Ledger::fromArray($data);
    }

    /** @return list<array<string, mixed>> */
    private function select(string $sql, string $id): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$id]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Implement remaining methods (delete, exists, queries)...
}
```

## Inherited Helpers

`AbstractLedgerRepository` provides:

```php
// Lock normalization
$this->extractLockData($output);
// Returns: LockData { type, owner, pubkey, custom }

// Row conversion
$this->rowsToOutputs($rows);
// Returns: list<Output>

// Build ledger data
$this->buildLedgerDataArray($unspentRows, $spentRows, $transactionRows);
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
