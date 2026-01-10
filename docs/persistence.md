# Persistence

The ledger can be serialized to JSON or arrays for storage and transmission.

## JSON Serialization

```php
// Save
$json = $ledger->toJson();
file_put_contents('ledger.json', $json);

// Restore
$ledger = Ledger::fromJson(file_get_contents('ledger.json'));
```

### Pretty Print

```php
$json = $ledger->toJson(JSON_PRETTY_PRINT);
```

### Example Output

```json
{
  "version": 1,
  "unspent": [
    {
      "id": "alice-funds",
      "amount": 1000,
      "lock": {
        "type": "owner",
        "name": "alice"
      }
    }
  ],
  "appliedTxs": ["tx-001", "tx-002"],
  "txFees": {
    "tx-001": 10,
    "tx-002": 5
  },
  "coinbaseAmounts": {
    "block-1": 50
  }
}
```

## Array Serialization

For custom storage backends (databases, caches):

```php
// Save
$array = $ledger->toArray();
$this->cache->set('ledger', $array);

// Restore
$ledger = Ledger::fromArray($this->cache->get('ledger'));
```

### Array Structure

```php
[
    'version' => 1,
    'unspent' => [
        ['id' => 'alice-funds', 'amount' => 1000, 'lock' => ['type' => 'owner', 'name' => 'alice']],
    ],
    'appliedTxs' => ['tx-001', 'tx-002'],
    'txFees' => ['tx-001' => 10, 'tx-002' => 5],
    'coinbaseAmounts' => ['block-1' => 50],
]
```

## SQLite Persistence (Built-in)

The library includes a built-in SQLite persistence layer with normalized column-based storage for efficient querying.

### Quick Start

```php
use Chemaclass\Unspent\Persistence\Sqlite\SqliteRepositoryFactory;

// Create repository (file-based)
$repo = SqliteRepositoryFactory::createFromFile('ledger.db');

// Save ledger
$repo->save('wallet-1', $ledger);

// Load ledger
$ledger = $repo->find('wallet-1');

// Check existence
if ($repo->exists('wallet-1')) {
    // ...
}

// Delete
$repo->delete('wallet-1');
```

### In-Memory Database (Testing)

```php
// Perfect for unit tests - no cleanup needed
$repo = SqliteRepositoryFactory::createInMemory();
```

### Query Capabilities

The SQLite repository provides efficient database-level queries:

```php
// Find outputs by owner
$aliceOutputs = $repo->findUnspentByOwner('wallet-1', 'alice');

// Find by amount range
$largeOutputs = $repo->findUnspentByAmountRange('wallet-1', min: 1000);
$mediumOutputs = $repo->findUnspentByAmountRange('wallet-1', min: 100, max: 500);

// Find by lock type
$ownerLocked = $repo->findUnspentByLockType('wallet-1', 'owner');
$cryptoLocked = $repo->findUnspentByLockType('wallet-1', 'pubkey');

// Find outputs created by transaction
$outputs = $repo->findOutputsCreatedBy('wallet-1', 'tx-123');

// Aggregations
$count = $repo->countUnspent('wallet-1');
$balance = $repo->sumUnspentByOwner('wallet-1', 'alice');

// Transaction queries
$coinbases = $repo->findCoinbaseTransactions('wallet-1');
$highFeeTxs = $repo->findTransactionsByFeeRange('wallet-1', min: 100);
```

### Normalized Schema

Data is stored in proper columns for efficient indexing (not as JSON blobs):

```sql
-- Outputs table with indexed columns
CREATE TABLE outputs (
    id TEXT NOT NULL,
    ledger_id TEXT NOT NULL,
    amount INTEGER NOT NULL,
    lock_type TEXT NOT NULL,
    lock_owner TEXT,
    lock_pubkey TEXT,
    is_spent INTEGER NOT NULL DEFAULT 0,
    created_by TEXT NOT NULL,
    spent_by TEXT,
    PRIMARY KEY (ledger_id, id)
);

-- Indexes for common queries
CREATE INDEX idx_outputs_owner ON outputs(ledger_id, lock_owner);
CREATE INDEX idx_outputs_amount ON outputs(ledger_id, amount);
```

### Custom PDO Connection

```php
$pdo = new PDO('sqlite:/path/to/database.db');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$repo = SqliteRepositoryFactory::createFromPdo($pdo);
```

### Custom Database Implementations

The persistence layer is designed for extensibility. You can implement your own database backend by extending `AbstractLedgerRepository`:

```php
use Chemaclass\Unspent\Persistence\AbstractLedgerRepository;

class MySQLLedgerRepository extends AbstractLedgerRepository
{
    public function save(string $id, Ledger $ledger): void
    {
        // Use inherited helpers:
        // - $this->extractLockData($output) for lock normalization
        // - self::SCHEMA_VERSION for version constant
    }

    public function find(string $id): ?Ledger
    {
        // Use inherited helpers:
        // - $this->buildLedgerDataArray($unspent, $spent, $txs)
        // - $this->rowsToOutputs($rows)
    }

    // ... implement remaining methods
}
```

See [Custom Persistence](custom-persistence.md) for a complete guide.

## Manual Database Storage

For custom database backends or specific requirements:

### Simple Approach (Store Full State)

```php
// Save to database
$pdo->prepare('UPDATE ledger SET data = ? WHERE id = 1')
    ->execute([$ledger->toJson()]);

// Load from database
$json = $pdo->query('SELECT data FROM ledger WHERE id = 1')->fetchColumn();
$ledger = Ledger::fromJson($json);
```

### Custom Normalized Approach

For systems not using the built-in SQLite repository:

```php
// Outputs table
foreach ($ledger->unspent() as $id => $output) {
    $stmt->execute([
        'id' => $id,
        'amount' => $output->amount,
        'lock_type' => $output->lock->toArray()['type'],
        'lock_data' => json_encode($output->lock->toArray()),
    ]);
}

// Transactions table
foreach ($ledger->allTxFees() as $txId => $fee) {
    $stmt->execute([
        'id' => $txId,
        'fee' => $fee,
        'is_coinbase' => $ledger->isCoinbase(new TxId($txId)),
    ]);
}
```

## What Gets Persisted

| Data | Included | Notes |
|------|----------|-------|
| Unspent outputs | Yes | ID, amount, lock |
| Applied spend IDs | Yes | For duplicate detection |
| Spend fees | Yes | Historical record |
| Coinbase amounts | Yes | Minting history |
| Spent outputs | No | Only unspent are stored |
| Full transaction history | No | Only IDs, not full spends |

## Ownership Preservation

Locks are fully preserved through serialization:

```php
$original = Ledger::withGenesis(
    Output::ownedBy('alice', 1000, 'funds'),
);

$restored = Ledger::fromJson($original->toJson());

// Ownership still enforced!
$restored->apply(Tx::create(
    spendIds: ['funds'],
    outputs: [Output::open(1000)],
    signedBy: 'bob',  // Throws AuthorizationException
));
```

## Lock Serialization Format

### Owner Lock

```json
{"type": "owner", "name": "alice"}
```

### PublicKey Lock

```json
{"type": "pubkey", "key": "base64-encoded-public-key"}
```

### NoLock

```json
{"type": "none"}
```

## Custom Lock Types

If you use custom `OutputLock` implementations, register their handlers before deserialization:

```php
use Chemaclass\Unspent\Lock\LockFactory;

// Bootstrap: register custom handlers
LockFactory::register('timelock', fn(array $data) => new TimeLock(
    $data['unlockTimestamp'],
    $data['owner'],
));

// Now Ledger::fromJson() handles custom locks transparently
$ledger = Ledger::fromJson(file_get_contents('ledger.json'));
```

See [Custom Locks](ownership.md#custom-locks) for more details.

## Versioning

The ledger includes a `version` field in serialized data for future schema evolution:

```php
$array = $ledger->toArray();
// $array['version'] === 1

// The version field is automatically included in toArray() and toJson()
// Future versions may add migration logic in fromArray() if the format changes
```

## Next Steps

- [API Reference](api-reference.md) - Complete method reference
- [Core Concepts](concepts.md) - Understanding the model
