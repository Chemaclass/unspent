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

**After (UTXO):**
```php
// Find alice's outputs
$aliceOutputs = $ledger->unspentByOwner('alice');
$outputToSpend = $aliceOutputs->first(); // or select strategically

// Create transaction
$ledger = $ledger->apply(Tx::create(
    spendIds: [$outputToSpend->id],
    outputs: [
        Output::ownedBy('bob', 100),
        Output::ownedBy('alice', $outputToSpend->amount - 100), // change
    ],
    signedBy: 'alice',
));
```

### Step 4: Output Selection Strategies

When a user has multiple outputs, choose which to spend:

```php
// Strategy 1: Largest first (fewer outputs over time)
$outputs = $ledger->unspentByOwner('alice')->sorted(
    fn($a, $b) => $b->amount <=> $a->amount
);

// Strategy 2: Smallest first (consolidate dust)
$outputs = $ledger->unspentByOwner('alice')->sorted(
    fn($a, $b) => $a->amount <=> $b->amount
);

// Strategy 3: Exact match (if available)
$outputs = $ledger->unspentByOwner('alice')->filter(
    fn($o) => $o->amount >= $requiredAmount
);
```

### Step 5: Handle Insufficient Funds

```php
$aliceTotal = $ledger->totalUnspentByOwner('alice');
if ($aliceTotal < $requiredAmount) {
    throw new InsufficientFundsException();
}

// May need to combine multiple outputs
$outputsToSpend = [];
$accumulated = 0;
foreach ($ledger->unspentByOwner('alice') as $output) {
    $outputsToSpend[] = $output->id;
    $accumulated += $output->amount;
    if ($accumulated >= $requiredAmount) {
        break;
    }
}

$ledger = $ledger->apply(Tx::create(
    spendIds: $outputsToSpend,
    outputs: [
        Output::ownedBy('bob', $requiredAmount),
        Output::ownedBy('alice', $accumulated - $requiredAmount), // change
    ],
    signedBy: 'alice',
));
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

### Debit User

```php
// Old way: simple subtraction
$user->balance -= $amount;

// UTXO way: spend and create change
$ledger = $ledger->apply(Tx::create(
    spendIds: $selectedOutputIds,
    outputs: [
        Output::open($amount, 'destination'),
        Output::ownedBy($userId, $change),
    ],
    signedBy: $userId,
));
```

### Credit User

```php
// Old way: simple addition
$user->balance += $amount;

// UTXO way: create new output (requires spending something)
// Option A: From existing pool
$ledger = $ledger->apply(Tx::create(
    spendIds: ['reward-pool'],
    outputs: [Output::ownedBy($userId, $amount)],
));

// Option B: Mint new value (if allowed in your system)
$ledger = $ledger->applyCoinbase(CoinbaseTx::create(
    outputs: [Output::ownedBy($userId, $amount)],
));
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
