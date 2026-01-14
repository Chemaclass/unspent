# Troubleshooting Guide

Common issues and their solutions when using Unspent.

## Exceptions Reference

| Exception | Code | Cause | Solution |
|-----------|------|-------|----------|
| `AuthorizationException` | 1000-1002 | Spend not authorized | Check signer/signature |
| `DuplicateOutputIdException` | 1100 | Output ID already exists | Use unique IDs or auto-generate |
| `DuplicateTxException` | 1101 | Transaction already applied | Check if tx was already processed |
| `OutputAlreadySpentException` | 1200 | Output not in unspent set | Output was already spent |
| `InsufficientSpendsException` | 1201 | Inputs < outputs | Add more inputs or reduce outputs |
| `GenesisNotAllowedException` | 1300 | Ledger not empty | Genesis only works on empty ledger |

## Common Issues

### "Output is not in the unspent set"

**Problem:** Trying to spend an output that doesn't exist or was already spent.

```php
OutputAlreadySpentException: Output 'my-output' is not in the unspent set
```

**Solutions:**

1. Check if the output was already spent:
```php
$history = $ledger->outputHistory('my-output');
if ($history?->isSpent()) {
    echo "Output was spent in tx: " . $history->spentByTxId;
}
```

2. Verify the output exists:
```php
$unspent = $ledger->unspent();
if (!$unspent->has('my-output')) {
    echo "Output does not exist";
}
```

3. Check for typos in output ID.

### "Output owned by X, but spend signed by Y"

**Problem:** The `signedBy` field doesn't match the output owner.

```php
AuthorizationException: Output owned by 'alice', but spend signed by 'bob'
```

**Solutions:**

1. Ensure the correct user is signing:
```php
$ledger->apply(Tx::create(
    spendIds: ['alice-funds'],
    outputs: [...],
    signedBy: 'alice', // Must match the output owner
));
```

2. Check the output's actual owner:
```php
$output = $ledger->unspent()->get('alice-funds');
echo "Owner: " . $output->lock->name; // If Owner lock
```

### "Invalid signature for spend N"

**Problem:** Ed25519 signature verification failed.

```php
AuthorizationException: Invalid signature for spend 0
```

**Solutions:**

1. Verify you're signing the correct transaction ID:
```php
$txId = 'my-tx-id';
$signature = base64_encode(
    sodium_crypto_sign_detached($txId, $privateKey)
);

$ledger->apply(Tx::create(
    spendIds: ['secure-funds'],
    outputs: [...],
    proofs: [$signature],
    id: $txId, // Must match what was signed
));
```

2. Check the signature is at the correct index (matches spend order).

3. Verify you're using the correct private key for the output's public key.

### "Duplicate output id"

**Problem:** Trying to create an output with an ID that already exists.

```php
DuplicateOutputIdException: Duplicate output id: 'payment-001'
```

**Solutions:**

1. Let the library auto-generate IDs:
```php
Output::ownedBy('alice', 100); // Auto-generated ID
```

2. Use unique identifiers:
```php
Output::ownedBy('alice', 100, 'payment-' . uniqid());
```

### "Insufficient spends"

**Problem:** Total input amount is less than total output amount.

```php
InsufficientSpendsException: spend amount (100) < output amount (150)
```

**Solutions:**

1. Add more inputs to cover the outputs:
```php
$ledger->apply(Tx::create(
    spendIds: ['output-1', 'output-2'], // Combine outputs
    outputs: [Output::open(150)],
));
```

2. Reduce output amount or add change output:
```php
$ledger->apply(Tx::create(
    spendIds: ['output-100'],
    outputs: [
        Output::open(80),
        Output::open(20, 'change'), // Use all input value
    ],
));
```

### Memory Issues with Large Datasets

**Problem:** Running out of memory with many outputs.

**Solutions:**

1. Use store-backed mode instead of in-memory:
```php
// Instead of:
$ledger = Ledger::inMemory();

// Use:
$repository = new SqliteHistoryRepository($pdo, 'my-ledger');
$ledger = Ledger::withRepository($repository);
```

2. Increase PHP memory limit for initial data import:
```php
ini_set('memory_limit', '512M');
```

3. Process large datasets in batches.

### SQLite "Database is locked"

**Problem:** Concurrent access to SQLite database.

**Solutions:**

1. Use WAL mode for better concurrency:
```php
$pdo->exec('PRAGMA journal_mode=WAL');
```

2. Implement application-level locking for writes.

3. Use a client-server database (PostgreSQL, MySQL) for high concurrency.

### Custom Lock Not Restoring from JSON

**Problem:** Custom lock types not working after `fromJson()`.

```php
RuntimeException: Unknown lock type: timelock
```

**Solutions:**

1. Register custom lock handlers before deserializing:
```php
LockFactory::register('timelock', fn(array $data) => new TimeLock(
    $data['unlockTime'],
    $data['owner'],
));

$ledger = Ledger::fromJson($json); // Now works
```

2. Ensure the lock's `type()` method returns the registered type name.

## Debugging Tips

### Inspect Ledger State

```php
// Check total value
echo "Total: " . $ledger->totalUnspentAmount();

// List all unspent outputs
foreach ($ledger->unspent() as $output) {
    echo "{$output->id}: {$output->amount}\n";
}

// Check specific output history
$history = $ledger->outputHistory('my-output');
var_dump($history);
```

### Validate Before Applying

```php
$exception = $ledger->canApply($tx);
if ($exception !== null) {
    echo "Transaction would fail: " . $exception->getMessage();
    echo "Error code: " . $exception->getCode();
}
```

### Export State for Debugging

```php
// Serialize to JSON for inspection
$json = $ledger->toJson();
file_put_contents('debug-ledger.json', $json);
```

## Getting Help

If your issue isn't covered here:

1. Check the [API Reference](api-reference.md)
2. Review the [example code](../example/)
3. Open an issue on [GitHub](https://github.com/Chemaclass/unspent/issues)

## Next Steps

- [Migration Guide](migration.md) - Moving from balance-based systems
- [Security](../SECURITY.md) - Security best practices
