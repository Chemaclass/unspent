# Core Concepts

This document explains the fundamental concepts behind the Unspent library.

## The UTXO Model

UTXO stands for **Unspent Transaction Output**. It's how Bitcoin tracks ownership of value.

Instead of maintaining account balances like a bank (`alice: 500`), the UTXO model tracks individual "chunks" of value. Each chunk:

- Has a specific amount
- Has an owner (via a lock)
- Can only be spent once
- When spent, creates new chunks

Think of it like physical cash: you can't split a $20 bill, you spend it and get change back as new bills.

### Why UTXO?

| Traditional Balance | UTXO Model |
|---------------------|------------|
| Mutable state | Immutable history |
| "Trust me, it's 500" | Provable chain of ownership |
| Race conditions possible | Double-spend impossible |
| Audit = reconstruct from logs | Audit = verify the chain |

## Outputs

An **Output** is a chunk of value with ownership. It's the fundamental unit in the system.

```php
// Three ways to create outputs
Output::ownedBy('alice', 1000)           // Named owner (server-side auth)
Output::signedBy($publicKey, 1000)       // Crypto lock (trustless)
Output::open(1000)                        // No lock (anyone can spend)
```

Every output has:

- **ID** - Unique identifier (auto-generated or explicit)
- **Amount** - Positive integer value
- **Lock** - Determines who can spend it

### Output IDs

IDs can be explicit or auto-generated:

```php
Output::ownedBy('alice', 1000, 'my-custom-id')  // Explicit
Output::ownedBy('alice', 1000)                   // Auto-generated (32-char hex)
```

Auto-generated IDs are deterministic based on content, so identical outputs created separately will have different IDs (randomness is included).

## Spends

A **Spend** (transaction) consumes existing outputs and creates new ones.

```php
Spend::create(
    inputIds: ['alice-funds'],           // Outputs to consume
    outputs: [                            // New outputs to create
        Output::ownedBy('bob', 600),
        Output::ownedBy('alice', 400),
    ],
    signedBy: 'alice',                   // Authorization
    id: 'tx-001',                        // Optional transaction ID
);
```

### Rules

1. **Inputs must exist** - You can only spend unspent outputs
2. **Inputs must be authorized** - The signer must match the lock
3. **Outputs <= Inputs** - Can't create value (the gap is the fee)
4. **No duplicate IDs** - Output IDs must be unique

### Multiple Inputs

Combine multiple outputs in a single spend:

```php
Spend::create(
    inputIds: ['alice-funds-1', 'alice-funds-2'],  // Combine
    outputs: [Output::ownedBy('alice', 1500)],     // Into one
    signedBy: 'alice',
);
```

### Multiple Outputs

Split value to multiple recipients:

```php
Spend::create(
    inputIds: ['alice-funds'],
    outputs: [
        Output::ownedBy('bob', 300),
        Output::ownedBy('charlie', 300),
        Output::ownedBy('alice', 400),  // Change
    ],
    signedBy: 'alice',
);
```

## Ledger

The **Ledger** is the immutable state container. Every operation returns a new ledger.

```php
$v1 = Ledger::empty();
$v2 = $v1->addGenesis(Output::ownedBy('alice', 1000));
$v3 = $v2->apply($spend);

// $v1, $v2, $v3 are all different, immutable states
```

### Genesis

Genesis outputs are the initial value in the system. They can only be added to an empty ledger:

```php
$ledger = Ledger::empty()->addGenesis(
    Output::ownedBy('alice', 1000),
    Output::ownedBy('bob', 500),
);
```

### Querying State

```php
$ledger->totalUnspentAmount();           // Total value in circulation
$ledger->unspent()->count();             // Number of UTXOs
$ledger->hasSpendBeenApplied($spendId);  // Check if spend exists

// Access specific outputs
$unspent = $ledger->unspent();
$unspent->contains(new OutputId('x'));   // Check existence
$unspent->get(new OutputId('x'));        // Get output or null

// Iterate
foreach ($ledger->unspent() as $id => $output) {
    echo "{$id}: {$output->amount}\n";
}
```

## Validation & Errors

The library enforces invariants and throws specific exceptions:

| Exception | Cause |
|-----------|-------|
| `OutputAlreadySpentException` | Input doesn't exist or was already spent |
| `InsufficientInputsException` | Outputs exceed inputs |
| `DuplicateOutputIdException` | Output ID already exists |
| `DuplicateSpendException` | Spend ID already used |
| `GenesisNotAllowedException` | Adding genesis to non-empty ledger |
| `AuthorizationException` | Signer doesn't match lock |

All exceptions extend `UnspentException` for easy catching:

```php
try {
    $ledger = $ledger->apply($spend);
} catch (UnspentException $e) {
    // Handle any domain error
}
```

## History & Provenance

The ledger tracks where every output came from and where it went:

```php
// Where did this output come from?
$ledger->outputCreatedBy(new OutputId('bob-funds'));  // 'tx-001' or 'genesis'

// Where did it go?
$ledger->outputSpentBy(new OutputId('alice-funds'));  // 'tx-001' or null

// Get output even if spent
$ledger->getOutput(new OutputId('spent-output'));     // Output or null
```

See [History & Provenance](history.md) for full details.

## Next Steps

- [Ownership](ownership.md) - Learn about locks and authorization
- [History & Provenance](history.md) - Trace outputs through transactions
- [Fees & Minting](fees-and-minting.md) - Implicit fees and creating new value
- [API Reference](api-reference.md) - Complete method reference
