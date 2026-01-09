# Fees & Minting

## Implicit Fees

Fees work like Bitcoin: the difference between inputs and outputs is the fee.

```php
// Input: 1000, Output: 990 = Fee: 10
$ledger = $ledger->apply(Spend::create(
    inputIds: ['alice-funds'],  // Worth 1000
    outputs: [Output::ownedBy('bob', 990)],
    signedBy: 'alice',
));

$ledger->feeForSpend(new SpendId('tx-id'));  // 10
```

### No Explicit Fee Field

You don't specify fees explicitly. Just output less than you input:

```php
// 1000 in -> 600 + 350 out = 50 fee
Spend::create(
    inputIds: ['funds'],        // 1000
    outputs: [
        Output::ownedBy('bob', 600),
        Output::ownedBy('alice', 350),  // Change
    ],
    signedBy: 'alice',
);
```

### Zero Fees

Fees can be zero if inputs equal outputs:

```php
// 1000 in -> 1000 out = 0 fee
Spend::create(
    inputIds: ['funds'],        // 1000
    outputs: [
        Output::ownedBy('bob', 600),
        Output::ownedBy('alice', 400),
    ],
    signedBy: 'alice',
);
```

### Querying Fees

```php
// Fee for specific spend
$ledger->feeForSpend(new SpendId('tx-001'));  // int or null if not found

// Total fees collected
$ledger->totalFeesCollected();  // Sum of all fees

// All fees as map
$ledger->allSpendFees();  // ['tx-001' => 10, 'tx-002' => 5, ...]
```

### What Happens to Fees?

Fees are "burned" - removed from circulation. The library tracks them but doesn't assign them anywhere. In a real system, you might:

- Redistribute to miners/validators via coinbase
- Burn them permanently (deflationary)
- Collect them in a treasury

## Coinbase Transactions (Minting)

Coinbase transactions create new value out of nothing. Like Bitcoin miner rewards.

```php
// Mint 50 units (no inputs required)
$ledger = Ledger::empty()->applyCoinbase(Coinbase::create([
    Output::ownedBy('miner', 50, 'block-reward'),
], 'block-1'));

$ledger->totalMinted();  // 50
```

### Coinbase vs Regular Spends

| Aspect | Regular Spend | Coinbase |
|--------|---------------|----------|
| Inputs | Required | None |
| Value source | Existing outputs | Created |
| Fees | Can have fees | No fees |
| Method | `apply()` | `applyCoinbase()` |

### Multiple Outputs

Coinbase can create multiple outputs:

```php
$ledger->applyCoinbase(Coinbase::create([
    Output::ownedBy('miner', 45, 'block-reward'),
    Output::ownedBy('treasury', 5, 'dev-fund'),
], 'block-1'));
```

### Querying Coinbase Info

```php
$ledger->isCoinbase(new SpendId('block-1'));       // true
$ledger->coinbaseAmount(new SpendId('block-1'));   // 50 (total minted)
$ledger->totalMinted();                             // Sum of all coinbases
```

### Spending Minted Coins

Coinbase outputs work like any other output:

```php
$ledger = Ledger::empty()
    ->applyCoinbase(Coinbase::create([
        Output::ownedBy('miner', 50, 'reward'),
    ], 'block-1'))
    ->apply(Spend::create(
        inputIds: ['reward'],
        outputs: [Output::ownedBy('alice', 45)],
        signedBy: 'miner',
    ));

$ledger->totalMinted();        // 50
$ledger->totalFeesCollected(); // 5
$ledger->totalUnspentAmount(); // 45
```

## Economic Model Examples

### Deflationary (Burn Fees)

```php
// Fees disappear, reducing supply over time
$ledger->totalMinted();        // 1000 (initial supply)
$ledger->totalFeesCollected(); // 50 (burned)
$ledger->totalUnspentAmount(); // 950 (circulating)
```

### Inflationary (Block Rewards)

```php
// New value enters via coinbase
foreach ($blocks as $block) {
    $ledger = $ledger->applyCoinbase(Coinbase::create([
        Output::ownedBy($block->miner, 50),
    ], $block->id));
}
$ledger->totalMinted();  // Grows with each block
```

### Fixed Supply

```php
// All value created at genesis, no coinbase
$ledger = Ledger::empty()->addGenesis(
    Output::ownedBy('treasury', 21_000_000, 'total-supply'),
);
// Never call applyCoinbase() - supply is fixed
```

## Next Steps

- [Persistence](persistence.md) - Save and restore ledger state
- [API Reference](api-reference.md) - Complete method reference
