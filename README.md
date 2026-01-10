# Unspent

**Track value like physical cash in your PHP apps.** Every unit has an origin, can only be spent once, and leaves a complete audit trail.

```php
// Start with 1000 units
$ledger = Ledger::withGenesis(Output::open(1000, 'funds'));

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

## Install

```bash
composer require chemaclass/unspent
```

## Quick Start

### Create and transfer value

```php
// Initial value
$ledger = Ledger::withGenesis(Output::open(1000, 'funds'));

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
$ledger = Ledger::withGenesis(
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

| What you're building | Example |
|-|-|
| In-game currency | [virtual-currency.php](example/virtual-currency.php) |
| Loyalty points | [loyalty-points.php](example/loyalty-points.php) |
| Internal accounting | [internal-accounting.php](example/internal-accounting.php) |
| Crypto wallets | [crypto-wallet.php](example/crypto-wallet.php) |
| Event sourcing | [event-sourcing.php](example/event-sourcing.php) |

## Persistence

```php
// JSON
$json = $ledger->toJson();
$ledger = Ledger::fromJson($json);

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
| [API Reference](docs/api-reference.md) | Complete method reference |
