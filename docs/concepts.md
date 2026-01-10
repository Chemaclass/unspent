# Core Concepts

## The Cash Register Model

Imagine tracking money in your app. The typical approach:

```php
$balances = ['alice' => 500, 'bob' => 300];
$balances['alice'] -= 100;
$balances['bob'] += 100;
```

Simple, but problematic. Where did Alice's 500 come from? Can you prove she had it? What if two processes update simultaneously?

**Unspent** takes a different approach. Instead of tracking balances, it tracks individual "chunks" of value - like physical bills in a cash register.

```php
// Alice has a 500-unit "bill"
$ledger = Ledger::withGenesis(Output::ownedBy('alice', 500, 'alice-bill'));

// She "spends" it and gets "change" back
$ledger = $ledger->apply(Tx::create(
    spendIds: ['alice-bill'],     // This bill is now gone
    outputs: [
        Output::ownedBy('bob', 100),   // Bob gets 100
        Output::ownedBy('alice', 400), // Alice gets change
    ],
    signedBy: 'alice',
));
```

The original 500-unit "bill" no longer exists. It's been spent. Alice now has a new 400-unit "bill", and Bob has a 100-unit "bill".

## Outputs

An **Output** is a chunk of value. It has:

- **Amount** - How much it's worth
- **Lock** - Who can spend it
- **ID** - Unique identifier

```php
Output::ownedBy('alice', 1000)              // Alice owns it
Output::ownedBy('alice', 1000, 'my-id')     // With custom ID
Output::signedBy($publicKey, 1000)          // Crypto-locked
Output::open(1000)                           // Anyone can spend
```

Think of each output as a banknote with the owner's name written on it.

## Transactions

A **Tx** spends existing outputs and creates new ones.

```php
Tx::create(
    spendIds: ['alice-funds'],    // What to spend
    outputs: [                     // What to create
        Output::ownedBy('bob', 600),
        Output::ownedBy('alice', 400),
    ],
    signedBy: 'alice',            // Who's authorizing
);
```

**Rules:**

1. You can only spend outputs that exist and haven't been spent
2. You must be authorized to spend them (signer matches lock)
3. You can't create more value than you spend
4. Any difference becomes a fee (value removed from circulation)

### Combining and Splitting

Combine multiple outputs:

```php
Tx::create(
    spendIds: ['alice-100', 'alice-200'], // 300 total
    outputs: [Output::ownedBy('alice', 300, 'alice-combined')],
    signedBy: 'alice',
);
```

Split to multiple recipients:

```php
Tx::create(
    spendIds: ['alice-funds'],
    outputs: [
        Output::ownedBy('bob', 100),
        Output::ownedBy('charlie', 100),
        Output::ownedBy('alice', 800), // change
    ],
    signedBy: 'alice',
);
```

## Ledger

The **Ledger** holds all state. It's immutable - every operation returns a new ledger.

```php
$v1 = Ledger::empty();
$v2 = $v1->addGenesis(Output::ownedBy('alice', 1000));
$v3 = $v2->apply($tx);
// $v1, $v2, $v3 are separate, immutable snapshots
```

### Genesis

Initial value enters via genesis:

```php
$ledger = Ledger::withGenesis(
    Output::ownedBy('alice', 1000),
    Output::ownedBy('bob', 500),
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
