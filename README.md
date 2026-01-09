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
    Output::create(1000, 'alice'),
);

// Alice spends 600, keeps 390, burns 10 as fee
$ledger = $ledger->apply(Spend::create(
    inputIds: ['alice'],
    outputs: [
        Output::create(600, 'bob'),
        Output::create(390, 'alice-change'),
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
Output::create(100, 'reward-42');  // 100 units, ID "reward-42"
Output::create(100);               // Auto-generated content-hash ID
```

### Spends

Consume outputs, create new ones. The transaction.

```php
Spend::create(
    inputIds: ['reward-42'],
    outputs: [Output::create(100, 'spent')],
    id: 'tx-001',  // Optional - auto-generated if omitted
);
```

Inputs must exist. Outputs can't exceed inputs (the gap is the fee).

### Ledger

Immutable state. Every operation returns a new ledger - you can't mess with history.

```php
$v1 = Ledger::empty()->addGenesis(Output::create(100, 'x'));
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
    ->applyCoinbase(Coinbase::create([
        Output::create(50, 'miner-reward'),
    ], 'block-1'));

$ledger->totalMinted();  // 50
$ledger->isCoinbase(new SpendId('block-1'));  // true

// Spend minted coins like any other output
$ledger = $ledger->apply(Spend::create(
    inputIds: ['miner-reward'],
    outputs: [Output::create(45, 'alice')],
));
// 5 goes to fees
```

Regular spends need inputs. Coinbase transactions create value out of thin air.

## Ownership (Locks)

Protect outputs so only rightful owners can spend them:

```php
use Chemaclass\Unspent\Lock\OwnerLock;

// Create output owned by Alice
$ledger = Ledger::empty()->addGenesis(
    Output::create(1000, 'alice-funds', new OwnerLock('alice')),
);

// Alice can spend her output
$ledger = $ledger->apply(Spend::create(
    inputIds: ['alice-funds'],
    outputs: [Output::create(900, 'bob-payment', new OwnerLock('bob'))],
    authorizedBy: 'alice',
));

// Bob tries to spend Alice's output - FAILS
$ledger->apply(Spend::create(
    inputIds: ['alice-funds'],
    outputs: [...],
    authorizedBy: 'bob',
)); // Throws AuthorizationException
```

Built-in locks:

| Lock | Behavior |
|------|----------|
| `NoLock` | Anyone can spend (default, backwards compatible) |
| `OwnerLock` | Only matching `authorizedBy` can spend |

Custom locks? Implement `OutputLock` interface.

## Validation

The library won't let you do dumb things:

| Mistake                         | Exception                      |
|---------------------------------|--------------------------------|
| Spend something twice           | `OutputAlreadySpentException`  |
| Spend more than you have        | `InsufficientInputsException`  |
| Duplicate output IDs            | `DuplicateOutputIdException`   |
| Reuse a spend ID                | `DuplicateSpendException`      |
| Add genesis to non-empty ledger | `GenesisNotAllowedException`   |
| Spend without authorization     | `AuthorizationException`       |

Catch everything with `UnspentException`:

```php
try {
    $ledger->apply($spend);
} catch (UnspentException $e) {
    // nope
}
```

## Persistence

Save and restore ledger state. Perfect for databases, caching, or cross-machine sync.

```php
// Save to file/database
$json = $ledger->toJson();
file_put_contents('ledger.json', $json);

// Restore later
$ledger = Ledger::fromJson(file_get_contents('ledger.json'));

// Continue where you left off
$ledger = $ledger->apply(Spend::create(...));
```

Also works with arrays for custom serialization:

```php
$array = $ledger->toArray();   // For database storage
$ledger = Ledger::fromArray($array);
```

## API

```php
// Create stuff (ID is optional - auto-generated if omitted)
Output::create(int $amount, ?string $id = null, ?OutputLock $lock = null): Output
Spend::create(array $inputIds, array $outputs, ?string $id = null, ?string $authorizedBy = null): Spend
Coinbase::create(array $outputs, ?string $id = null): Coinbase
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

// Persistence
$ledger->toArray(): array
$ledger->toJson(int $flags = 0): string
Ledger::fromArray(array $data): Ledger
Ledger::fromJson(string $json): Ledger

// UnspentSet
$set->contains(OutputId $id): bool
$set->get(OutputId $id): ?Output
$set->count(): int
$set->totalAmount(): int
$set->toArray(): array
UnspentSet::fromArray(array $data): UnspentSet
```

## Contributing

See [CONTRIBUTING.md](.github/CONTRIBUTING.md).

## License

MIT
