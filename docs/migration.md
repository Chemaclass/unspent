# Migration Guide

How to migrate from traditional balance-based systems to the UTXO model.

## Balance-Based vs UTXO

| Aspect              | Balance-Based         | UTXO (Unspent)                     |
|---------------------|-----------------------|------------------------------------|
| **Data model**      | `user.balance = 500`  | Collection of outputs with amounts |
| **Spend operation** | `user.balance -= 100` | Consume outputs, create new ones   |
| **History**         | Separate audit log    | Built into the model               |
| **Concurrency**     | Race conditions       | Atomic by design                   |
| **Authorization**   | External check        | Lock attached to each output       |

## Migration Strategy

### Step 1: Snapshot Current Balances

Export all user balances from your existing system:

```php
// Old system
$balances = [
    'alice' => 1500,
    'bob' => 800,
    'charlie' => 2000,
];
```

### Step 2: Create Genesis Outputs

Convert balances to genesis outputs:

```php
$outputs = [];
foreach ($balances as $owner => $amount) {
    $outputs[] = Output::ownedBy($owner, $amount, "{$owner}-initial");
}

$ledger = Ledger::withGenesis(...$outputs);
```

### Step 3: Update Application Code

**Before (balance-based):**
```php
// Transfer 100 from alice to bob
$alice->balance -= 100;
$bob->balance += 100;
```

**After (UTXO) - using convenience methods:**
```php
// One line! Automatic output selection and change handling
$ledger->transfer('alice', 'bob', 100);
```

That's it! The `transfer()` method automatically:
- Finds alice's unspent outputs
- Selects enough to cover the amount
- Creates the transfer output for bob
- Returns change to alice
- Handles authorization

### Step 4: Handle Fees

```php
// Transfer with a 5-unit fee
$ledger->transfer('alice', 'bob', 100, fee: 5);

// Alice loses 105 total (100 to bob + 5 fee)
```

### Step 5: Error Handling

```php
// Throws InsufficientSpendsException if alice can't afford it
try {
    $ledger->transfer('alice', 'bob', 1000000);
} catch (InsufficientSpendsException $e) {
    echo "Alice doesn't have enough funds";
}

// Or check first
if ($ledger->totalUnspentByOwner('alice') >= $amount) {
    $ledger->transfer('alice', 'bob', $amount);
}
```

## Common Patterns

### Get User Balance

```php
// Old way
$balance = $user->balance;

// UTXO way
$balance = $ledger->totalUnspentByOwner($userId);
```

### Check If User Can Afford

```php
// Old way
if ($user->balance >= $amount) { ... }

// UTXO way
if ($ledger->totalUnspentByOwner($userId) >= $amount) { ... }
```

### Transfer Between Users

```php
// Old way
$alice->balance -= 100;
$bob->balance += 100;

// UTXO way
$ledger->transfer('alice', 'bob', 100);

// With fee
$ledger->transfer('alice', 'bob', 100, fee: 5);
```

### Debit User (Burn Value)

```php
// Old way
$user->balance -= $amount;

// UTXO way - burns the amount (e.g., redemption, purchase)
$ledger->debit($userId, $amount);

// With additional fee
$ledger->debit($userId, $amount, fee: 10);
```

### Credit User (Mint Value)

```php
// Old way
$user->balance += $amount;

// UTXO way - mints new value
$ledger->credit($userId, $amount);

// With custom transaction ID
$ledger->credit($userId, $amount, 'daily-bonus-123');
```

### Chain Operations

```php
// Multiple operations in sequence
$ledger = Ledger::inMemory()
    ->credit('alice', 1000)           // Mint 1000 for alice
    ->transfer('alice', 'bob', 300)   // Alice sends 300 to bob
    ->debit('bob', 50)                // Bob redeems 50
    ->credit('charlie', 200);         // Mint 200 for charlie
```

## Gradual Migration

For systems that can't migrate all at once:

1. **Run in parallel**: Keep both systems, sync periodically
2. **Shadow writes**: Write to UTXO system but read from balance system
3. **Feature flags**: Migrate users/features incrementally
4. **Reconciliation**: Regularly verify UTXO totals match balance totals

```php
// Reconciliation check
$balanceTotal = array_sum($balances);
$utxoTotal = $ledger->totalUnspentAmount();
assert($balanceTotal === $utxoTotal, 'Systems out of sync!');
```

## Next Steps

- [Core Concepts](concepts.md) - Understand outputs and transactions
- [Persistence](persistence.md) - Save your ledger state
- [Troubleshooting](troubleshooting.md) - Common issues and solutions
