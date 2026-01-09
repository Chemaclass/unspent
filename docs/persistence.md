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

## Database Storage

### Simple Approach (Store Full State)

```php
// Save to database
$pdo->prepare('UPDATE ledger SET data = ? WHERE id = 1')
    ->execute([$ledger->toJson()]);

// Load from database
$json = $pdo->query('SELECT data FROM ledger WHERE id = 1')->fetchColumn();
$ledger = Ledger::fromJson($json);
```

### Normalized Approach

For larger systems, store components separately:

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
    inputIds: ['funds'],
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
