# Unspent

**Track value like physical cash in your PHP apps.** Every unit has an origin, can only be spent once, and leaves a complete audit trail.

```php
// Start with 1000 units
$ledger = InMemoryLedger::withGenesis(Output::open(1000, 'funds'));

// Spend 600, keep 400 as change
$ledger = $ledger->apply(Tx::create(
    spendIds: ['funds'],
    outputs: [Output::open(600), Output::open(400)],
));

// The original 1000 is gone forever - can't be double-spent.
```

## Why?

Traditional balance tracking (`balance: 500`) is just a number you mutate. There's no history, no proof of where it came from, and race conditions can corrupt it.

**Unspent** tracks value like physical cash. You can't photocopy a $20 bill - you spend it and get change back. This gives you:

- **Double-spend prevention** - A unit can only be spent once, ever
- **Complete audit trail** - Trace any value back to its origin
- **Immutable history** - State changes are additive, never mutated
- **Zero external dependencies** - Pure PHP 8.4+

Inspired by Bitcoin's UTXO model, decoupled as a standalone library.

## When is UTXO right for you?

| Need | Traditional Balance | Unspent |
|------|---------------------|---------|
| Simple spending | ✅ Easy | Overkill |
| "Who authorized this?" | Requires extra logging | ✅ Built-in |
| "Trace this value's origin" | Requires event sourcing | ✅ Built-in |
| Concurrent spending safety | Race conditions | ✅ Atomic |
| Conditional spending rules | Custom logic needed | ✅ Lock system |
| Regulatory audit trail | Reconstruct from logs | ✅ Native |

**Use Unspent when:**
- Value moves between parties (not just a single user's balance)
- You need to prove who authorized what
- Audit trail is a requirement, not a nice-to-have

**Skip it when:**
- You just need a simple counter or balance
- Single-user scenarios with no authorization needs
- No audit requirements

## Install

```bash
composer require chemaclass/unspent
```

## Quick Start

### Create and transfer value

```php
// Initial value
$ledger = InMemoryLedger::withGenesis(Output::open(1000, 'funds'));

// Transfer: spend existing outputs, create new ones
$ledger = $ledger->apply(Tx::create(
    spendIds: ['funds'],
    outputs: [
        Output::open(600, 'payment'),
        Output::open(400, 'change'),
    ],
));

// Query state
$ledger->totalUnspentAmount();  // 1000
$ledger->unspent()->count();    // 2 outputs
```

### Add authorization

When you need to control who can spend:

```php
// Server-side ownership (sessions, JWT, etc.)
$ledger = InMemoryLedger::withGenesis(
    Output::ownedBy('alice', 1000, 'alice-funds'),
);

$ledger = $ledger->apply(Tx::create(
    spendIds: ['alice-funds'],
    outputs: [
        Output::ownedBy('bob', 600),
        Output::ownedBy('alice', 400),
    ],
    signedBy: 'alice',  // Must match the owner
));
```

### Output types

| Method | Use case |
|-|-|
| `Output::open(100)` | No lock - pure bookkeeping |
| `Output::ownedBy('alice', 100)` | Server-side auth (sessions, JWT) |
| `Output::signedBy($pubKey, 100)` | Ed25519 crypto (trustless) |
| `Output::lockedWith($lock, 100)` | Custom locks (multisig, timelock) |

## Use Cases

| What you're building | Topics |
|-|-|
| [In-game currency](example/Console/VirtualCurrencyCommand.php) | Ownership, double-spend prevention, implicit fees |
| [Loyalty points](example/Console/LoyaltyPointsCommand.php) | Minting new value, redemption, audit trails |
| [Internal accounting](example/Console/InternalAccountingCommand.php) | Multi-party authorization, reconciliation |
| [Crypto wallet](example/Console/CryptoWalletCommand.php) | Ed25519 signatures, trustless verification |
| [Event sourcing](example/Console/EventSourcingCommand.php) | State machines, immutable history tracing |
| [Bitcoin simulation](example/Console/BitcoinSimulationCommand.php) | Coinbase mining, fees, UTXO consolidation |
| [Custom locks](example/Console/CustomLocksCommand.php) | Timelocks, custom lock types, serialization |
| [SQLite persistence](example/Console/SqlitePersistenceCommand.php) | Database storage, querying, ScalableLedger |

## Persistence

```php
// JSON
$json = $ledger->toJson();
$ledger = InMemoryLedger::fromJson($json);

// SQLite (built-in)
$repo = SqliteRepositoryFactory::createFromFile('ledger.db');
$repo->save('wallet-1', $ledger);
$ledger = $repo->find('wallet-1');
```

## Documentation

| Topic | What you'll learn |
|-|-|
| [Core Concepts](docs/concepts.md) | How outputs, transactions, and the ledger work |
| [Ownership](docs/ownership.md) | Locks, authorization, custom lock types |
| [History](docs/history.md) | Tracing value through transactions |
| [Fees & Minting](docs/fees-and-minting.md) | Implicit fees, coinbase transactions |
| [Persistence](docs/persistence.md) | JSON, SQLite, custom storage |
| [Scalability](docs/scalability.md) | InMemoryLedger vs ScalableLedger for large datasets |
| [API Reference](docs/api-reference.md) | Complete method reference |
