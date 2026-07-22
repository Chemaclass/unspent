# Persistence

## JSON Serialization

```php
// Save
$json = $ledger->toJson();
file_put_contents('ledger.json', $json);

// Restore
$ledger = Ledger::fromJson(file_get_contents('ledger.json'));
```

## Array Serialization

```php
$array = $ledger->toArray();
$ledger = Ledger::fromArray($array);
```

## SQLite (Built-in)

The library includes a normalized SQLite backend with query support.

### Quick Start

```php
use Chemaclass\Unspent\Persistence\Sqlite\SqliteRepositoryFactory;

// Create repository
$repo = SqliteRepositoryFactory::createInMemory();          // Testing
$repo = SqliteRepositoryFactory::createFromFile('ledger.db'); // Production

// CRUD operations
$repo->save('wallet-1', $ledger);
$ledger = $repo->find('wallet-1');
$repo->exists('wallet-1');
$repo->delete('wallet-1');
```

### Query Methods

```php
// By owner
$repo->findUnspentByOwner('wallet-1', 'alice');
$repo->sumUnspentByOwner('wallet-1', 'alice');

// By amount
$repo->findUnspentByAmountRange('wallet-1', min: 100);
$repo->findUnspentByAmountRange('wallet-1', min: 100, max: 500);

// By lock type
$repo->findUnspentByLockType('wallet-1', 'owner');
$repo->findUnspentByLockType('wallet-1', 'pubkey');

// Transactions
$repo->findCoinbaseTransactions('wallet-1');
$repo->findTransactionsByFeeRange('wallet-1', min: 10);

// Counts
$repo->countUnspent('wallet-1');
```

### Write Patterns & Performance

Two persistence models trade simplicity against write cost:

**Snapshot save (`SqliteLedgerRepository::save`)** rewrites the whole ledger — it
deletes the stored rows and re-inserts every output and transaction using chunked
multi-row `INSERT`s (bounded parameter count per statement). Simple and atomic,
but each `save()` is O(total history). Best for small/medium ledgers or periodic
checkpoints.

```php
$repo = SqliteRepositoryFactory::createFromFile('ledger.db');
$repo->save('wallet-1', $ledger); // full snapshot, batched inserts
```

**Store-backed history (`SqliteHistoryRepository`)** appends per operation, so each
`apply()` / `applyCoinbase()` writes only the new rows and memory stays bounded to
the unspent set. Prefer this for large or high-write-throughput ledgers.

```php
use Chemaclass\Unspent\Persistence\Sqlite\SqliteHistoryRepository;

$repo = new SqliteHistoryRepository($pdo, 'wallet-1');
$ledger = Ledger::withRepository($repo)
    ->addGenesis(Output::ownedBy('alice', 1000));

$ledger->credit('alice', 100); // persists incrementally
```

## Custom Locks

Register handlers before deserializing:

```php
use Chemaclass\Unspent\Lock\LockFactory;

LockFactory::register('timelock', fn($data) => new TimeLock(
    $data['unlockTime'],
    $data['owner'],
));

$ledger = Ledger::fromJson($json); // Custom locks restored
```

## What Gets Persisted

| Data | Included |
|-|-|
| Unspent outputs | Yes |
| Transaction IDs | Yes |
| Fees | Yes |
| Coinbase amounts | Yes |
| Spent outputs | Yes (for history) |
| Full tx data | No (only IDs) |

## Next Steps

- [Custom Persistence](custom-persistence.md) - Build your own backend
- [API Reference](api-reference.md) - Complete method reference
