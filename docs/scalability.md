# Scalability

The `Ledger` class supports two modes for different scale requirements.

## In-memory Mode (Simple)

The default mode stores everything in memory:

```php
$ledger = Ledger::withGenesis(Output::open(1000));
$ledger = $ledger->apply($tx);
```

**Characteristics:**
- All outputs (unspent and spent) stored in memory
- All transaction history in memory
- O(1) queries for everything
- Simple, fast, no external dependencies

**Best for:**
- < 100k total outputs
- Development and testing
- Small to medium applications

**Memory usage:**
| Total Outputs | Approximate Memory |
|---------------|-------------------|
| 10k | ~10 MB |
| 100k | ~100 MB |
| 1M | ~700 MB |

## Store-backed Mode (Production)

For large-scale applications, store-backed mode keeps only unspent outputs in memory while delegating history queries to a `HistoryStore`:

```php
use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\Persistence\Sqlite\SqliteHistoryStore;

// Create a HistoryStore (any implementation)
$pdo = new PDO('sqlite:ledger.db');
$store = new SqliteHistoryStore($pdo, 'wallet-1');

// Create store-backed ledger
$ledger = Ledger::withStore($store)->addGenesis(Output::open(1000, 'genesis'));

// Apply transactions (persisted to store immediately)
$ledger = $ledger->apply($tx);

// History queries go to database
$history = $ledger->outputHistory($outputId);
```

**Characteristics:**
- Only unspent outputs in memory
- History queries delegated to HistoryStore
- Memory bounded by unspent count, not total history
- Slightly higher latency for history queries (~1ms vs ~1us)

**Best for:**
- 100k+ total outputs
- Applications with long transaction history
- Memory-constrained environments

**Memory usage (store-backed mode):**
| Total Outputs | Unspent | Memory |
|---------------|---------|--------|
| 1M | 100k | ~100 MB |
| 10M | 100k | ~100 MB |
| 100M | 100k | ~100 MB |

## Choosing a Mode

| Factor | In-memory Mode | Store-backed Mode |
|--------|----------------|-------------------|
| Setup complexity | Simple | Requires HistoryStore |
| Memory usage | Grows with history | Bounded by unspent |
| Query latency | ~1us | ~1ms for history |
| Persistence | Manual (JSON/SQLite) | Automatic |
| Scale limit | ~1M outputs | Unlimited |

## Store-backed Mode Usage

### Creating a New Store-backed Ledger

```php
use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\Persistence\Sqlite\SqliteHistoryStore;
use Chemaclass\Unspent\Persistence\Sqlite\SqliteSchema;
use PDO;

// Setup database
$pdo = new PDO('sqlite:ledger.db');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$schema = new SqliteSchema($pdo);
if (!$schema->exists()) {
    $schema->create();
}

// Initialize ledger record
$pdo->exec("INSERT OR IGNORE INTO ledgers (id, version, total_unspent, total_fees, total_minted) VALUES ('my-wallet', 1, 0, 0, 0)");

// Create history store and ledger
$store = new SqliteHistoryStore($pdo, 'my-wallet');
$ledger = Ledger::withStore($store)->addGenesis(Output::open(1000, 'initial-funds'));

// Apply transactions
$ledger = $ledger->apply($tx);
```

### Loading an Existing Store-backed Ledger

```php
use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Persistence\Sqlite\SqliteHistoryStore;
use Chemaclass\Unspent\Persistence\Sqlite\SqliteLedgerRepository;
use PDO;

$pdo = new PDO('sqlite:ledger.db');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$repo = new SqliteLedgerRepository($pdo);
$store = new SqliteHistoryStore($pdo, 'my-wallet');

// Load only unspent outputs into memory
$data = $repo->findUnspentOnly('my-wallet');

if ($data !== null) {
    $ledger = Ledger::fromUnspentSet(
        $data['unspentSet'],
        $store,
        $data['totalFees'],
        $data['totalMinted'],
    );
}
```

## API Differences

In store-backed mode, the following methods query the HistoryStore instead of memory:

| Method | In-memory Mode | Store-backed Mode |
|--------|----------------|-------------------|
| `outputHistory()` | Memory lookup | HistoryStore query |
| `outputCreatedBy()` | Memory lookup | HistoryStore query |
| `outputSpentBy()` | Memory lookup | HistoryStore query |
| `feeForTx()` | Memory lookup | HistoryStore query |
| `isCoinbase()` | Memory lookup | HistoryStore query |
| `coinbaseAmount()` | Memory lookup | HistoryStore query |

These methods work identically in both modes (always in-memory):

| Method | Notes |
|--------|-------|
| `apply()` | Updates unspent set in memory |
| `unspent()` | Returns in-memory UnspentSet |
| `totalUnspentAmount()` | Cached in memory |
| `totalFeesCollected()` | Cached in memory |
| `totalMinted()` | Cached in memory |

## Ledger Interface

Both modes share the same `Ledger` class, enabling consistent usage:

```php
function processLedger(Ledger $ledger): void {
    // Works with in-memory or store-backed mode
    $ledger = $ledger->apply($tx);
    echo $ledger->totalUnspentAmount();
}
```

## Custom HistoryStore Implementations

The `HistoryStore` interface can be implemented for any storage backend:

```php
use Chemaclass\Unspent\Persistence\HistoryStore;

class RedisHistoryStore implements HistoryStore
{
    // Implement for Redis
}

class MySQLHistoryStore implements HistoryStore
{
    // Implement for MySQL
}
```

Then use with store-backed mode:

```php
$store = new RedisHistoryStore($redis, 'my-wallet');
$ledger = Ledger::withStore($store)->addGenesis(...$genesis);
```

## Migration

To migrate from in-memory to store-backed mode:

1. Save existing ledger to SQLite using standard persistence
2. Load using `Ledger::fromUnspentSet()` with a HistoryStore

```php
// Save existing ledger
$repo = SqliteRepositoryFactory::createFromFile('ledger.db');
$repo->save('my-wallet', $existingLedger);

// Reload with store-backed mode
$pdo = new PDO('sqlite:ledger.db');
$store = new SqliteHistoryStore($pdo, 'my-wallet');
$data = (new SqliteLedgerRepository($pdo))->findUnspentOnly('my-wallet');

$ledger = Ledger::fromUnspentSet(
    $data['unspentSet'],
    $store,
    $data['totalFees'],
    $data['totalMinted'],
);
```

## Next Steps

- [Custom Persistence](custom-persistence.md) - Build custom repository implementations
- [API Reference](api-reference.md) - Complete method reference for all classes
