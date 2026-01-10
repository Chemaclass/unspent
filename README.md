# Unspent

**Track value like physical cash in your PHP apps.** Every unit has an origin, can only be spent once, and leaves a complete audit trail.

```php
// Alice has 1000 units
$ledger = Ledger::withGenesis(
    Output::ownedBy('alice', 1000, 'alice-funds'),
);

// She sends 600 to Bob
$ledger = $ledger->apply(Tx::create(
    spendIds: ['alice-funds'],
    outputs: [
        Output::ownedBy('bob', 600),
        Output::ownedBy('alice', 400), // change
    ],
    signedBy: 'alice',
));

// Done. Bob has 600. Alice has 400.
// The original 1000 is gone forever - can't be double-spent.
```

## Why?

Traditional balance tracking (`alice: 500`) is just a number you mutate. There's no history, no proof of where it came from, and race conditions can corrupt it.

**Unspent** tracks value like physical cash. You can't photocopy a $20 bill - you spend it and get change back. This gives you:

- **Double-spend prevention** - A unit can only be spent once, ever
- **Complete audit trail** - Trace any value back to its origin
- **Immutable history** - State changes are additive, never mutated
- **Zero external dependencies** - Pure PHP 8.4+

## Install

```bash
composer require chemaclass/unspent
```

## Use Cases

| What you're building | Example | Key features used |
|-|-|-|
| In-game currency | [virtual-currency.php](example/virtual-currency.php) | Genesis, ownership, fees |
| Loyalty points | [loyalty-points.php](example/loyalty-points.php) | Minting, redemption, audit |
| Internal accounting | [internal-accounting.php](example/internal-accounting.php) | History, provenance |
| Crypto wallets | [crypto-wallet.php](example/crypto-wallet.php) | Ed25519 signatures |
| Event sourcing | [event-sourcing.php](example/event-sourcing.php) | State as transactions |

## Quick Reference

### Create value

```php
// Genesis - initial value
$ledger = Ledger::withGenesis(
    Output::ownedBy('alice', 1000, 'alice-funds'),
);

// Minting - create new value anytime
$ledger = $ledger->applyCoinbase(CoinbaseTx::create([
    Output::ownedBy('miner', 50, 'reward'),
]));
```

### Transfer value

```php
$ledger = $ledger->apply(Tx::create(
    spendIds: ['alice-funds'],
    outputs: [
        Output::ownedBy('bob', 600),
        Output::ownedBy('alice', 390), // 10 goes to fees
    ],
    signedBy: 'alice',
));
```

### Query state

```php
$ledger->totalUnspentAmount();    // 990
$ledger->unspent()->count();      // 2 outputs
$ledger->feeForTx(new TxId('x')); // 10
$ledger->outputHistory(new OutputId('bob-funds')); // full provenance
```

### Save & restore

```php
// JSON
$json = $ledger->toJson();
$ledger = Ledger::fromJson($json);

// SQLite (built-in)
$repo = SqliteRepositoryFactory::createFromFile('ledger.db');
$repo->save('wallet-1', $ledger);
$ledger = $repo->find('wallet-1');
```

## Ownership

Three ways to lock value:

```php
Output::ownedBy('alice', 100)       // Server-side auth (sessions, JWT)
Output::signedBy($publicKey, 100)   // Ed25519 crypto (trustless)
Output::open(100)                    // Anyone can spend
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
