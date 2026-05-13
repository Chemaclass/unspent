# Unspent

[![PHP 8.4+](https://img.shields.io/badge/PHP-8.4+-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![MIT License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/Chemaclass/unspent/badges/quality-score.png?b=main)](https://scrutinizer-ci.com/g/Chemaclass/unspent/?branch=main)
[![Code Coverage](https://scrutinizer-ci.com/g/Chemaclass/unspent/badges/coverage.png?b=main)](https://scrutinizer-ci.com/g/Chemaclass/unspent/?branch=main)
[![Mutation testing badge](https://img.shields.io/endpoint?style=flat&url=https%3A%2F%2Fbadge-api.stryker-mutator.io%2Fgithub.com%2FChemaclass%2Funspent%2Fmain)](https://dashboard.stryker-mutator.io/reports/github.com/Chemaclass/unspent/main)

**Track value like physical cash in your PHP apps.** Every unit has an origin, can only be spent once, and leaves a complete audit trail.

```php
$ledger = Ledger::inMemory();
$ledger->credit('alice', 100)->transfer('alice', 'bob', 25);
echo $ledger->totalUnspentByOwner('bob'); // 25
```

Zero-dependency PHP 8.4+ library implementing the UTXO model, inspired by Bitcoin and decoupled as a standalone library.

## Table of Contents

- [Why?](#why)
- [When to use it](#when-to-use-it)
- [Install](#install)
- [Quick Start](#quick-start)
- [Output types](#output-types)
- [Examples](#examples)
- [Documentation](#documentation)
- [Learning Path](#learning-path)
- [FAQ](#faq)
- [Contributing](#contributing)

## Why?

Traditional balance tracking (`balance: 500`) is just a number you mutate. There's no history, no proof of where it came from, and race conditions can corrupt it.

**Unspent** tracks value like physical cash. You can't photocopy a $20 bill - you spend it and get change back. This gives you:

- **Double-spend prevention** — a unit can only be spent once, ever
- **Complete audit trail** — trace any value back to its origin
- **Immutable history** — state changes are additive, never mutated
- **Advanced locks** — timelocks, multisig, hash-locked outputs (HTLCs)
- **Zero external dependencies** — pure PHP 8.4+

## When to use it

| Need                        | Traditional Balance     | Unspent       |
|-----------------------------|-------------------------|---------------|
| Simple spending             | Easy                    | Overkill      |
| "Who authorized this?"      | Requires extra logging  | Built-in      |
| "Trace this value's origin" | Requires event sourcing | Built-in      |
| Concurrent spending safety  | Race conditions         | Atomic        |
| Conditional spending rules  | Custom logic needed     | Lock system   |
| Regulatory audit trail      | Reconstruct from logs   | Native        |

**Use Unspent when** value moves between parties, you need to prove who authorized what, or audit trail is a requirement.

**Skip it when** you only need a simple counter, single-user balance, or no audit requirements.

### Limitations

| Limitation                       | Details                                                                                  |
|----------------------------------|------------------------------------------------------------------------------------------|
| **Integer bounds**               | Amounts bounded by `PHP_INT_MAX` (~9.2e18). Wrap for arbitrary precision.                |
| **Single-node model**            | Single-node operation. For distributed consensus, add Raft/blockchain infrastructure.    |
| **No built-in rate limiting**    | Your application must implement rate limiting.                                           |
| **Memory for large datasets**    | In-memory ~1MB/1k outputs. Use store-backed mode for >100k outputs.                      |
| **Not for sub-second precision** | Timestamps not enforced; not a real-time trading engine.                                 |

## Install

```bash
composer require chemaclass/unspent
```

## Quick Start

### In-Memory (prototyping)

```php
use Chemaclass\Unspent\Ledger;

$ledger = Ledger::inMemory();
$ledger->credit('alice', 1000)
    ->credit('bob', 500)
    ->transfer('alice', 'bob', 200)
    ->transfer('alice', 'bob', 100, fee: 5)
    ->debit('bob', 50);

$ledger->totalUnspentByOwner('alice');  // 695
$ledger->totalUnspentByOwner('bob');    // 750
```

| Method                                | Description               |
|---------------------------------------|---------------------------|
| `credit($owner, $amount)`             | Mint new value to owner   |
| `transfer($from, $to, $amount, $fee)` | Move value between owners |
| `debit($owner, $amount, $fee)`        | Burn value from owner     |

### SQLite persistence (production)

```php
use Chemaclass\Unspent\Persistence\Sqlite\SqliteRepositoryFactory;
use Chemaclass\Unspent\Output;

$repo = SqliteRepositoryFactory::createFromFile('ledger.db');

$ledger = $repo->find('my-ledger')
    ?? Ledger::withGenesis(Output::ownedBy('alice', 1000));

$ledger->transfer('alice', 'bob', 200);
$repo->save('my-ledger', $ledger);
```

### Coin Control (full input/output control)

```php
use Chemaclass\Unspent\Tx;

$ledger = Ledger::withGenesis(
    Output::ownedBy('alice', 500, 'alice-savings'),
    Output::ownedBy('alice', 300, 'alice-checking'),
);

$ledger->apply(Tx::create(
    spendIds: ['alice-checking'],
    outputs: [
        Output::ownedBy('bob', 200),
        Output::ownedBy('alice', 100, 'alice-change'),
    ],
    signedBy: 'alice',
));
```

Use Coin Control for: specific output selection, custom IDs, multiple recipients, complex fees.

### Batch operations

```php
$ledger->consolidate('alice', fee: 10);

$ledger->batchTransfer('alice', [
    'bob' => 100,
    'charlie' => 200,
    'dave' => 300,
], fee: 5);
```

### Transaction Mempool

Stage transactions for validation before commit:

```php
use Chemaclass\Unspent\Mempool;

$mempool = new Mempool($ledger);
$mempool->add($tx1);
$mempool->add($tx2);
// $mempool->add($conflictingTx); // Throws OutputAlreadySpentException
$mempool->commit();
```

## Output types

| Method                                       | Use case                          |
|----------------------------------------------|-----------------------------------|
| `Output::open(100)`                          | No lock - pure bookkeeping        |
| `Output::ownedBy('alice', 100)`              | Server-side auth (sessions, JWT)  |
| `Output::signedBy($pubKey, 100)`             | Ed25519 crypto (trustless)        |
| `Output::timelocked('alice', 100, $time)`    | Vesting, delayed payments         |
| `Output::multisig(2, ['a','b','c'], 100)`    | Joint accounts, escrow            |
| `Output::hashlocked($hash, 100)`             | Atomic swaps, HTLCs               |
| `Output::lockedWith($lock, 100)`             | Custom lock implementations       |

## Examples

Runnable demos under [`example/`](example/):

```bash
php example/run               # List all
php example/run game          # Run a demo (also: loyalty, accounting, events, btc, wallet, locks, sqlite)
php example/run game --reset  # Reset state
```

| Alias        | Demonstrates                                       |
|--------------|----------------------------------------------------|
| `game`       | In-game currency, ownership, double-spend, fees    |
| `loyalty`    | Customer rewards, minting, redemption, audit       |
| `accounting` | Department budgets, multi-party auth, reconcile    |
| `events`     | Order lifecycle as state transitions               |
| `btc`        | Bitcoin simulation, mining, fees, consolidation    |
| `wallet`     | Ed25519 signatures, trustless verification         |
| `locks`      | Custom time-locked outputs, serialization          |
| `sqlite`     | SQLite persistence, querying, history              |

See [example/README.md](example/README.md) for full output samples and the web API demo.

## Documentation

Start at [`docs/README.md`](docs/README.md) for the full index.

| Topic | What you'll learn |
|-|-|
| [Core Concepts](docs/concepts.md) | How outputs, transactions, and the ledger work |
| [Ownership](docs/ownership.md) | Locks (owner, timelock, multisig, hashlock), authorization |
| [History](docs/history.md) | Tracing value through transactions |
| [Fees & Minting](docs/fees-and-minting.md) | Implicit fees, coinbase transactions |
| [Selection Strategies](docs/selection-strategies.md) | FIFO, largest-first, exact-match, random, custom |
| [Persistence](docs/persistence.md) | JSON, SQLite, custom storage |
| [Scalability](docs/scalability.md) | In-memory vs store-backed mode |
| [Events](docs/events.md) | PSR-14 event dispatching, integrations |
| [Migration Guide](docs/migration.md) | Moving from balance-based systems to UTXO |
| [Troubleshooting](docs/troubleshooting.md) | Common issues and solutions |
| [API Reference](docs/api-reference.md) | Ledger, Output, Tx, Mempool, UtxoAnalytics |

## Learning Path

| Level | Topic | Docs | Example |
|-------|-------|------|---------|
| 1. Basics | Outputs, transactions | [Concepts](docs/concepts.md) | `php example/run game` |
| 2. Ownership | Locks, authorization | [Ownership](docs/ownership.md) | `php example/run wallet` |
| 3. Persistence | SQLite storage | [Persistence](docs/persistence.md) | `php example/run sqlite` |
| 4. Scale | Mode selection | [Scalability](docs/scalability.md) | - |
| 5. Advanced | Timelocks, multisig, HTLC | [Ownership](docs/ownership.md) | `php example/run locks` |
| 6. Operations | Batch, mempool, analytics | [API Reference](docs/api-reference.md) | - |

## FAQ

<details>
<summary><strong>Can two outputs have the same ID?</strong></summary>

No. Output IDs must be unique across the ledger. If you omit the ID, a unique one is auto-generated (128-bit random entropy). Custom IDs that collide throw `DuplicateOutputIdException`. This mirrors Bitcoin's `txid:vout` model.
</details>

<details>
<summary><strong>When should I use in-memory vs store-backed mode?</strong></summary>

| Scenario | Recommendation |
|-|-|
| < 100k total outputs | `Ledger::inMemory()` or `Ledger::withGenesis(...)` |
| > 100k total outputs | `Ledger::withRepository($repository)` |
| Need full history in memory | `Ledger::inMemory()` |
| Memory-constrained environment | `Ledger::withRepository($repository)` |

See [Scalability docs](docs/scalability.md).
</details>

<details>
<summary><strong>How are fees calculated?</strong></summary>

Fees are implicit, like in Bitcoin. The difference between inputs and outputs is the fee:

```php
$ledger->apply(Tx::create(
    spendIds: ['input-100'],       // Spending 100
    outputs: [Output::open(95)],   // Creating 95
));
// Fee = 100 - 95 = 5 (implicit)
```

See [Fees & Minting docs](docs/fees-and-minting.md).
</details>

## Contributing

See [CONTRIBUTING.md](.github/CONTRIBUTING.md) for setup, TDD workflow, quality gates, and commit format.

```bash
composer install     # Installs dependencies + pre-commit hook
composer check:quick # Fast feedback: cs-fixer + phpunit
composer test        # Full: cs-fixer + rector + phpstan + phpunit
```

Docker workflow available via `make help`.

## License

MIT — see [LICENSE](LICENSE).
