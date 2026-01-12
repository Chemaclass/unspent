# Fees & Minting

## Implicit Fees

Fees are the difference between inputs and outputs:

```php
// Input: 1000, Output: 990 = Fee: 10
$ledger = $ledger->apply(Tx::create(
    spendIds: ['alice-funds'],     // Worth 1000
    outputs: [Output::ownedBy('bob', 990)],
    signedBy: 'alice',
));

$ledger->feeForTx(new TxId('...')); // 10
```

No explicit fee field. Just output less than you input.

### Query Fees

```php
$ledger->feeForTx(new TxId('tx-001')); // Fee for specific tx
$ledger->totalFeesCollected();          // Sum of all fees
$ledger->allTxFees();                   // ['tx-001' => 10, ...]
```

### What Happens to Fees?

Fees are removed from circulation. Your app can:

- Burn them (deflationary)
- Redistribute via coinbase (like miners)
- Collect in a treasury

## Minting (Coinbase)

Create new value out of nothing:

```php
$ledger = Ledger::inMemory()->applyCoinbase(CoinbaseTx::create(
    outputs: [Output::ownedBy('miner', 50, 'block-reward')],
    id: 'block-1',
));

$ledger->totalMinted(); // 50
```

### Coinbase vs Regular Transactions

| | Regular Tx | Coinbase |
|-|-|-|
| Inputs | Required | None |
| Value | From existing outputs | Created |
| Method | `apply()` | `applyCoinbase()` |

### Query Coinbase

```php
$ledger->isCoinbase(new TxId('block-1'));     // true
$ledger->coinbaseAmount(new TxId('block-1')); // 50
$ledger->totalMinted();                       // All coinbases
```

## Economic Models

### Deflationary (burn fees)

```php
$ledger->totalMinted();        // 1000
$ledger->totalFeesCollected(); // 50 (burned)
$ledger->totalUnspentAmount(); // 950
```

### Inflationary (block rewards)

```php
foreach ($blocks as $block) {
    $ledger = $ledger->applyCoinbase(CoinbaseTx::create([
        Output::ownedBy($block->miner, 50),
    ], $block->id));
}
```

### Fixed supply

```php
$ledger = Ledger::withGenesis(
    Output::ownedBy('treasury', 21_000_000, 'total-supply'),
);
// Never call applyCoinbase()
```

## Next Steps

- [Persistence](persistence.md) - Save and restore state
- [API Reference](api-reference.md) - Complete method reference
