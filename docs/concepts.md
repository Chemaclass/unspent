# Core Concepts

## The Cash Register Model

Imagine tracking money in your app. The typical approach:

```php
$balances = ['alice' => 500, 'bob' => 300];
$balances['alice'] -= 100;
$balances['bob'] += 100;
```

Simple, but problematic. Where did the 500 come from? Can you prove it existed? What if two processes update simultaneously?

**Unspent** takes a different approach. Instead of tracking balances, it tracks individual "chunks" of value - like physical bills in a cash register.

```php
// A 500-unit "bill"
$ledger = InMemoryLedger::withGenesis(Output::open(500, 'bill'));

// Spend it and get change back
$ledger = $ledger->apply(Tx::create(
    spendIds: ['bill'],
    outputs: [
        Output::open(100, 'payment'),
        Output::open(400, 'change'),
    ],
));
```

The original 500-unit "bill" no longer exists. It's been spent. Now there's a 100-unit output and a 400-unit output.

## Outputs

An **Output** is a chunk of value. It has:

- **Amount** - How much it's worth
- **ID** - Unique identifier
- **Lock** - Who can spend it (optional)

```php
Output::open(1000)                           // No lock - anyone can spend
Output::open(1000, 'my-id')                  // With custom ID
Output::ownedBy('alice', 1000)               // Owned by alice
Output::signedBy($publicKey, 1000)           // Crypto-locked
```

Think of each output as a banknote. Without a lock, it's bearer cash. With a lock, it has a name on it.

## Transactions

A **Tx** spends existing outputs and creates new ones.

```php
// Simple transfer (no authorization)
Tx::create(
    spendIds: ['funds'],
    outputs: [
        Output::open(600),
        Output::open(400),
    ],
);

// With ownership (requires authorization)
Tx::create(
    spendIds: ['alice-funds'],
    outputs: [
        Output::ownedBy('bob', 600),
        Output::ownedBy('alice', 400),
    ],
    signedBy: 'alice',
);
```

**Rules:**

1. You can only spend outputs that exist and haven't been spent
2. If the output has a lock, you must be authorized to spend it
3. You can't create more value than you spend
4. Any difference becomes a fee (value removed from circulation)

### Combining and Splitting

Combine multiple outputs:

```php
Tx::create(
    spendIds: ['out-100', 'out-200'], // 300 total
    outputs: [Output::open(300, 'combined')],
);
```

Split to multiple recipients:

```php
Tx::create(
    spendIds: ['funds'],
    outputs: [
        Output::open(100, 'part-1'),
        Output::open(100, 'part-2'),
        Output::open(800, 'part-3'),
    ],
);
```

## Ledger

The **Ledger** holds all state. It's immutable - every operation returns a new ledger.

```php
$v1 = InMemoryLedger::empty();
$v2 = $v1->addGenesis(Output::open(1000, 'initial'));
$v3 = $v2->apply($tx);
// $v1, $v2, $v3 are separate, immutable snapshots
```

### Genesis

Initial value enters via genesis:

```php
$ledger = InMemoryLedger::withGenesis(
    Output::open(1000, 'fund-a'),
    Output::open(500, 'fund-b'),
);
```

### Querying

```php
$ledger->totalUnspentAmount();           // Total value
$ledger->unspent()->count();             // Number of outputs
$ledger->unspent()->get(new OutputId('x')); // Get specific output

foreach ($ledger->unspent() as $id => $output) {
    echo "{$id}: {$output->amount}\n";
}
```

## Errors

The library prevents invalid operations:

| Error | Cause |
|-|-|
| `OutputAlreadySpentException` | Trying to spend something that doesn't exist or is already spent |
| `InsufficientSpendsException` | Creating more value than you're spending |
| `DuplicateOutputIdException` | Output ID already exists |
| `AuthorizationException` | Signer doesn't match the lock |

```php
try {
    $ledger = $ledger->apply($tx);
} catch (UnspentException $e) {
    // Handle any domain error
}
```

## History

Every output knows where it came from:

```php
$ledger->outputCreatedBy(new OutputId('x')); // 'tx-001' or 'genesis'
$ledger->outputSpentBy(new OutputId('x'));   // 'tx-002' or null
$ledger->getOutput(new OutputId('x'));       // Output data (even if spent)
```

## Next Steps

- [Ownership](ownership.md) - How locks and authorization work
- [History](history.md) - Full provenance tracking
- [Fees & Minting](fees-and-minting.md) - Fees and creating new value
