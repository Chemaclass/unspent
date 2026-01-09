# Unspent

UTXO-style bookkeeping for PHP. Think Bitcoin's transaction model, but for your app.

## The Problem

You're tracking balances with a single number: `alice_balance = 500`. Cool, but:

- Where did those 500 come from?
- Were any spent twice? (oops)
- Can you prove the history to an auditor?

Nope. Traditional balance tracking is a black box.

## The Solution

Track value like Bitcoin tracks coins. Every unit has an origin, can only be spent once, and leaves a trail. No mutations, no surprises.

```php
$ledger = Ledger::empty()->addGenesis(
    Output::create('alice', 1000),
);

// Alice spends 600, keeps 390, burns 10 as fee
$ledger = $ledger->apply(Spend::create(
    id: 'tx-001',
    inputIds: ['alice'],
    outputs: [
        Output::create('bob', 600),
        Output::create('alice-change', 390),
    ],
));

$ledger->totalUnspentAmount();  // 990 (10 went to fees)
$ledger->feeForSpend(new SpendId('tx-001')); // 10
```

That's it. Full audit trail. Double-spend protection built in.

## When to Use This

- **Virtual currencies** - in-game gold, tokens, credits
- **Loyalty points** - every point traceable from earn to burn
- **Internal accounting** - audit-ready, no hidden mutations
- **Event sourcing** - fits naturally with event-sourced domains

If you need to answer "where did this value come from?" - this is your library.

## Install

```bash
composer require chemaclass/unspent
```

PHP 8.4+

## How It Works

### Outputs

A chunk of value with a unique ID. Spend it, and it's gone - replaced by new outputs.

```php
Output::create('reward-42', 100);  // 100 units, ID "reward-42"
```

### Spends

Consume outputs, create new ones. The transaction.

```php
Spend::create(
    id: 'tx-001',
    inputIds: ['reward-42'],
    outputs: [Output::create('spent', 100)],
);
```

Inputs must exist. Outputs can't exceed inputs (the gap is the fee).

### Ledger

Immutable state. Every operation returns a new ledger - you can't mess with history.

```php
$v1 = Ledger::empty()->addGenesis(Output::create('x', 100));
$v2 = $v1->apply($someSpend);
// $v1 unchanged, $v2 has the new state
```

## Fees

Outputs don't match inputs? The difference is the fee. Just like Bitcoin.

```php
// 1000 in, 990 out = 10 fee
$ledger->feeForSpend(new SpendId('tx-001')); // 10
$ledger->totalFeesCollected();               // all fees combined
$ledger->allSpendFees();                     // ['tx-001' => 10, ...]
```

Zero fees work too - just make inputs = outputs.

## Coinbase (Minting)

Need to create new value? Like miners getting block rewards? Use coinbase transactions:

```php
// Mint new coins (no inputs required)
$ledger = Ledger::empty()
    ->applyCoinbase(Coinbase::create('block-1', [
        Output::create('miner-reward', 50),
    ]));

$ledger->totalMinted();  // 50
$ledger->isCoinbase(new SpendId('block-1'));  // true

// Spend minted coins like any other output
$ledger = $ledger->apply(Spend::create(
    id: 'tx-001',
    inputIds: ['miner-reward'],
    outputs: [Output::create('alice', 45)],
));
// 5 goes to fees
```

Regular spends need inputs. Coinbase transactions create value out of thin air.

## Validation

The library won't let you do dumb things:

| Mistake | Exception |
|---------|-----------|
| Spend something twice | `OutputAlreadySpentException` |
| Spend more than you have | `InsufficientInputsException` |
| Duplicate output IDs | `DuplicateOutputIdException` |
| Reuse a spend ID | `DuplicateSpendException` |
| Add genesis to non-empty ledger | `GenesisNotAllowedException` |

Catch everything with `UnspentException`:

```php
try {
    $ledger->apply($spend);
} catch (UnspentException $e) {
    // nope
}
```

## API

```php
// Create stuff
Output::create(string $id, int $amount): Output
Spend::create(string $id, array $inputIds, array $outputs): Spend
Coinbase::create(string $id, array $outputs): Coinbase
Ledger::empty(): Ledger

// Do stuff
$ledger->addGenesis(Output ...$outputs): Ledger
$ledger->apply(Spend $spend): Ledger
$ledger->applyCoinbase(Coinbase $coinbase): Ledger

// Query stuff
$ledger->totalUnspentAmount(): int
$ledger->totalFeesCollected(): int
$ledger->feeForSpend(SpendId $id): ?int
$ledger->allSpendFees(): array
$ledger->hasSpendBeenApplied(SpendId $id): bool
$ledger->unspent(): UnspentSet
$ledger->totalMinted(): int
$ledger->isCoinbase(SpendId $id): bool
$ledger->coinbaseAmount(SpendId $id): ?int

// UnspentSet
$set->contains(OutputId $id): bool
$set->get(OutputId $id): ?Output
$set->count(): int
$set->totalAmount(): int
```

## Contributing

See [CONTRIBUTING.md](.github/CONTRIBUTING.md).

## License

MIT
