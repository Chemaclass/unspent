# Unspent

[![PHP 8.4+](https://img.shields.io/badge/PHP-8.4+-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![MIT License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/Chemaclass/unspent/badges/quality-score.png?b=main)](https://scrutinizer-ci.com/g/Chemaclass/unspent/?branch=main)
[![Code Coverage](https://scrutinizer-ci.com/g/Chemaclass/unspent/badges/coverage.png?b=main)](https://scrutinizer-ci.com/g/Chemaclass/unspent/?branch=main)
[![Mutation testing badge](https://img.shields.io/endpoint?style=flat&url=https%3A%2F%2Fbadge-api.stryker-mutator.io%2Fgithub.com%2FChemaclass%2Funspent%2Fmain)](https://dashboard.stryker-mutator.io/reports/github.com/Chemaclass/unspent/main)

**Track value like physical cash in your PHP apps.** Every unit has an origin, can only be spent once, and leaves a complete audit trail.

```php
// 3 lines to get started
$ledger = Ledger::inMemory();
$ledger->credit('alice', 100)->transfer('alice', 'bob', 25);
echo $ledger->totalUnspentByOwner('bob'); // 25
```

<details>
<summary><strong>Full example with all operations</strong></summary>

```php
$ledger = Ledger::inMemory();
$ledger->credit('alice', 1000)          // Mint 1000 for Alice
    ->transfer('alice', 'bob', 300)     // Alice sends 300 to Bob
    ->debit('bob', 50);                 // Bob redeems 50

$ledger->totalUnspentByOwner('alice');  // 700
$ledger->totalUnspentByOwner('bob');    // 250
```

</details>

## Why?

Traditional balance tracking (`balance: 500`) is just a number you mutate. There's no history, no proof of where it came from, and race conditions can corrupt it.

**Unspent** tracks value like physical cash. You can't photocopy a $20 bill - you spend it and get change back. This gives you:

- **Double-spend prevention** - A unit can only be spent once, ever
- **Complete audit trail** - Trace any value back to its origin
- **Immutable history** - State changes are additive, never mutated
- **Advanced locks** - Timelocks, multisig, hash-locked outputs (HTLCs)
- **Zero external dependencies** - Pure PHP 8.4+

Inspired by Bitcoin's UTXO model, decoupled as a standalone library.

## When is UTXO right for you?

| Need                        | Traditional Balance     | Unspent       |
|-----------------------------|-------------------------|---------------|
| Simple spending             | ✅ Easy                  | Overkill      |
| "Who authorized this?"      | Requires extra logging  | ✅ Built-in    |
| "Trace this value's origin" | Requires event sourcing | ✅ Built-in    |
| Concurrent spending safety  | Race conditions         | ✅ Atomic      |
| Conditional spending rules  | Custom logic needed     | ✅ Lock system |
| Regulatory audit trail      | Reconstruct from logs   | ✅ Native      |

**Use Unspent when:**
- Value moves between parties (not just a single user's balance)
- You need to prove who authorized what
- Audit trail is a requirement, not a nice-to-have

**Skip it when:**
- You just need a simple counter or balance
- Single-user scenarios with no authorization needs
- No audit requirements

## When NOT to use this library

Be aware of these limitations before choosing Unspent:

| Limitation                       | Details                                                                                               |
|----------------------------------|-------------------------------------------------------------------------------------------------------|
| **Integer bounds**               | Amounts are bounded by `PHP_INT_MAX` (~9.2 quintillion). Use a wrapper for arbitrary precision.       |
| **Single-node model**            | Designed for single-node operation. For distributed consensus, add infrastructure (Raft, blockchain). |
| **No built-in rate limiting**    | Your application must implement rate limiting to prevent abuse.                                       |
| **Memory for large datasets**    | In-memory mode uses ~1MB per 1,000 outputs. Use store-backed mode for >100k outputs.                  |
| **Not for sub-second precision** | Timestamps are not enforced; this is not a real-time trading engine.                                  |

If you need distributed consensus, high-frequency trading, or arbitrary precision arithmetic, consider specialized solutions.

## Install

```bash
composer require chemaclass/unspent
```

## Quick Start

### Simple API (In-Memory)

For quick prototyping and testing:

```php
// Create a ledger and mint initial balances
$ledger = Ledger::inMemory();
$ledger->credit('alice', 1000)         // Mint 1000 to alice
    ->credit('bob', 500);              // Mint 500 to bob

// Transfer between users (auto handles change)
$ledger->transfer('alice', 'bob', 200);

// Transfer with fee (5 units burned)
$ledger->transfer('alice', 'bob', 100, fee: 5);

// Debit/burn value (redemption, purchase, etc.)
$ledger->debit('bob', 50);

// Check balances
$ledger->totalUnspentByOwner('alice');  // 695
$ledger->totalUnspentByOwner('bob');    // 650
```

### With SQLite Persistence

For production use with data persistence:

```php
use Chemaclass\Unspent\Persistence\Sqlite\SqliteRepositoryFactory;

// Create repository from file (auto-creates schema)
$repository = SqliteRepositoryFactory::createFromFile('ledger.db');

// Load existing ledger or create new one
$ledger = $repository->find('my-ledger')
    ?? Ledger::withGenesis(Output::ownedBy('alice', 1000));

// Make changes
$ledger->transfer('alice', 'bob', 200);

// Save to database
$repository->save('my-ledger', $ledger);
```

| Method                                | Description               |
|---------------------------------------|---------------------------|
| `credit($owner, $amount)`             | Mint new value to owner   |
| `transfer($from, $to, $amount, $fee)` | Move value between owners |
| `debit($owner, $amount, $fee)`        | Burn value from owner     |

### Coin Control API

For full control over inputs and outputs, use `apply()`:

```php
// Start with specific outputs
$ledger = Ledger::withGenesis(
    Output::ownedBy('alice', 500, 'alice-savings'),
    Output::ownedBy('alice', 300, 'alice-checking'),
);

// Explicitly choose which outputs to spend
$ledger->apply(Tx::create(
    spendIds: ['alice-checking'],  // Only spend from checking
    outputs: [
        Output::ownedBy('bob', 200),
        Output::ownedBy('alice', 100, 'alice-change'),
    ],
    signedBy: 'alice',
));

// alice-savings (500) is untouched
// alice-change (100) is the new output
```

### Batch Operations

Consolidate many small outputs or pay multiple recipients in one transaction:

```php
// Consolidate dust into a single output
$ledger->consolidate('alice', fee: 10);

// Pay multiple recipients at once
$ledger->batchTransfer('alice', [
    'bob' => 100,
    'charlie' => 200,
    'dave' => 300,
], fee: 5);
```

### Transaction Mempool

Stage transactions for validation before committing:

```php
$mempool = new Mempool($ledger);

// Add validated transactions
$mempool->add($tx1);
$mempool->add($tx2);

// Detect double-spend attempts
// $mempool->add($conflictingTx); // Throws OutputAlreadySpentException

// Commit all at once
$mempool->commit();
```

**Use coin control when you need:**
- Specific output selection (spend oldest first, consolidate dust, etc.)
- Custom output IDs for tracking
- Multiple recipients in one transaction
- Complex fee structures

### Output types

| Method                              | Use case                          |
|-------------------------------------|-----------------------------------|
| `Output::open(100)`                 | No lock - pure bookkeeping        |
| `Output::ownedBy('alice', 100)`     | Server-side auth (sessions, JWT)  |
| `Output::signedBy($pubKey, 100)`    | Ed25519 crypto (trustless)        |
| `Output::timelocked('alice', 100, $time)` | Vesting, delayed payments   |
| `Output::multisig(2, ['a','b','c'], 100)` | Joint accounts, escrow      |
| `Output::hashlocked($hash, 100)`    | Atomic swaps, HTLCs               |
| `Output::lockedWith($lock, 100)`    | Custom lock implementations       |

## Use Cases

<details>
<summary><strong>In-game currency</strong> — Ownership, double-spend prevention, implicit fees</summary>

📄 [Source code](example/Console/VirtualCurrencyCommand.php)

```
Virtual Currency - In-Game Economy (Flagship Demo)
==================================================

 [Created new ledger with 3 genesis outputs]

 alice bought item for 300g (tax: 50g)

Balances
--------

   alice: 650g
   bob: 500g
   shop: 5300g

 Total fees collected: 50g

Database Stats
--------------

 * Database: example/data/sample:virtual-currency.db
 * Ledger: sample:virtual-currency
 * Outputs: 5
 * Transactions: 1

 Run again to continue. Delete the DB file to reset.
```

</details>

<details>
<summary><strong>Loyalty points</strong> — Minting new value, redemption, audit trails</summary>

📄 [Source code](example/Console/LoyaltyPointsCommand.php)

```
Loyalty Points - Customer Rewards Program
=========================================

 [Created new empty ledger]

 Customer bought $75 -> earned 75 pts
 Total points minted: 75

 Customer balance: 75 pts

Points Breakdown
----------------

   earn-1: 75 pts (customer)

Database Stats
--------------

 * Database: example/data/sample:loyalty-points.db
 * Outputs: 1
 * Transactions: 1

 Run again to continue. Delete the DB file to reset.
```

</details>

<details>
<summary><strong>Internal accounting</strong> — Multi-party authorization, reconciliation</summary>

📄 [Source code](example/Console/InternalAccountingCommand.php)

```
Internal Accounting - Department Budgets
========================================

 [Created new ledger with 3 genesis outputs]

 Total budget: $180,000

 operations transfers $9,000 to marketing (fee: $180)

Security Demonstrations
-----------------------

 Finance tries to reallocate engineering funds...
 BLOCKED
 Marketing tries to overspend...
 BLOCKED

Budget by Department
--------------------

   engineering: $100,000
   marketing: $59,000
   operations: $20,820

Reconciliation
--------------

 * Initial: $180,000
 * Fees: $180
 * Remaining: $179,820
 * Check: BALANCED

Database Stats
--------------

 * Database: example/data/sample:internal-accounting.db
 * Outputs: 5
 * Transactions: 1

 Run again to continue. Delete the DB file to reset.
```

</details>

<details>
<summary><strong>Crypto wallet</strong> — Ed25519 signatures, trustless verification</summary>

📄 [Source code](example/Console/CryptoWalletCommand.php)

```
Crypto Wallet - Ed25519 Signatures
==================================

 [Created new ledger with 2 genesis outputs]

Keys Generated
--------------

 * Alice: mVfG1xZJmK8QqP2n...
 * Bob: Y+U9UIYQmP5FTJM9...

 Alice -> Bob: 356 (signed)

 Mallory tries to steal with wrong key...
 BLOCKED

Final Balances
--------------

   to-Bob-2: 356
   change-Alice-2: 634

Database Stats
--------------

 * Database: example/data/sample:crypto-wallet.db
 * Outputs: 4
 * Transactions: 1

 Run again to continue. Delete the DB file to reset.
```

</details>

<details>
<summary><strong>Event sourcing</strong> — State machines, immutable history tracing</summary>

📄 [Source code](example/Console/EventSourcingCommand.php)

```
Event Sourcing - Order Lifecycle
================================

 Order lifecycle: placed -> paid -> shipped -> delivered
 Each transition spends old state, creates new state

 [Created new empty ledger]

 order-1: placed
 order-1: paid
 order-1: shipped
 order-1: delivered

Event Chain for order-1
-----------------------

   order-1_placed: create_order-1 -> evt_order-1_payment
   order-1_paid: evt_order-1_payment -> evt_order-1_shipped
   order-1_shipped: evt_order-1_shipped -> evt_order-1_delivered
   order-1_delivered: evt_order-1_delivered (current)

All Orders (Current State)
--------------------------

   order-1: delivered

Database Stats
--------------

 * Database: example/data/sample:event-sourcing.db
 * Outputs: 4
 * Transactions: 4

 Run again to continue. Delete the DB file to reset.
```

</details>

<details>
<summary><strong>Bitcoin simulation</strong> — Coinbase mining, fees, UTXO consolidation</summary>

📄 [Source code](example/Console/BitcoinSimulationCommand.php)

```
Bitcoin Simulation - Multi-Block Mining
=======================================

 [Created new ledger with 1 genesis outputs]

Mining Block #1
---------------

 Transaction: satoshi-genesis -> recipient-1
 * Sent: 14.549999 BTC
 * Change: 33.95 BTC
 * Fee: 1.5 BTC
 Mined: 50 BTC -> miner-1

Blockchain State
----------------

 * Total minted: 100 BTC
 * Total fees: 1.5 BTC
 * In circulation: 98.5 BTC
 * UTXOs: 3

Database Stats
--------------

 * Database: example/data/sample:bitcoin-simulation.db
 * Outputs: 4
 * Transactions: 2

 Run again to continue. Delete the DB file to reset.
```

</details>

<details>
<summary><strong>Custom locks</strong> — Timelocks, custom lock types, serialization</summary>

📄 [Source code](example/Console/CustomLocksCommand.php)

```
Custom Locks - Time-Locked Outputs
==================================

 Registered 'timelock' handler

 [Created new ledger with 2 genesis outputs]

 alice spent unlocked funds -> charlie

Security Demonstrations
-----------------------

 bob tries to spend locked output...
 BLOCKED: Still locked until 2027-01-18
 Eve tries to spend alice's output...
 BLOCKED

Time-Locked Outputs
-------------------

   bob-locked: 500 (bob) - locked until 2027-01-18
   charlie-from-alice-2: 1000 (charlie)

Database Stats
--------------

 * Database: example/data/sample:custom-locks.db
 * Outputs: 3
 * Transactions: 1

 Run again to continue. Delete the DB file to reset.
```

</details>

<details>
<summary><strong>SQLite persistence</strong> — Database storage, querying, Ledger with HistoryRepository</summary>

📄 [Source code](example/Console/SqlitePersistenceCommand.php)

```
SQLite Persistence Example
==========================

 Connected to: data/ledger.db
 Ledger ID: sqlite-persistence

 Creating new ledger...
 Created ledger with genesis outputs for alice (1000) and bob (500)

Transaction example-tx-1
------------------------

 Alice spends: alice-initial (1000)
 * Created: charlie-1 (495), alice-change-1 (495)
 * Fee: 10

Query Examples
--------------

 Balances:
 * alice: 495
 * bob: 500
 * charlie: 495

 Outputs >= 100: 3
 Owner-locked outputs: 3

History Tracking
----------------

 alice-initial:
 * Amount: 1000
 * Status: spent
 * Created by: genesis
 * Spent by: example-tx-1

Ledger Summary
--------------

 * Total unspent: 1490
 * Total fees: 10
 * Total minted: 0
 * Unspent count: 3

Database Stats
--------------

 * Outputs in DB: 4
 * Transactions in DB: 1

 Run again to add more transactions.
```

</details>

```bash
php example/run game      # Run any example (loyalty, wallet, btc, etc.)
php example/run game      # Run again to continue from previous state
```
All examples use SQLite persistence. See [example/README.md](example/README.md) for details.

## Documentation

| Topic | What you'll learn |
|-|-|
| [Core Concepts](docs/concepts.md) | How outputs, transactions, and the ledger work |
| [Ownership](docs/ownership.md) | Locks (owner, timelock, multisig, hashlock), authorization |
| [History](docs/history.md) | Tracing value through transactions |
| [Fees & Minting](docs/fees-and-minting.md) | Implicit fees, coinbase transactions |
| [Selection Strategies](docs/selection-strategies.md) | FIFO, largest-first, exact-match, custom strategies |
| [Persistence](docs/persistence.md) | JSON, SQLite, custom storage |
| [Scalability](docs/scalability.md) | In-memory mode vs store-backed mode for large datasets |
| [Migration Guide](docs/migration.md) | Moving from balance-based systems to UTXO |
| [Troubleshooting](docs/troubleshooting.md) | Common issues and solutions |
| [API Reference](docs/api-reference.md) | Ledger, Output, Tx, Mempool, UtxoAnalytics |

## FAQ

<details>
<summary><strong>Can two outputs have the same ID?</strong></summary>

No. Output IDs must be unique across the ledger. If you omit the ID parameter, a unique one is auto-generated using 128-bit random entropy. If you provide a custom ID that already exists, the library throws `DuplicateOutputIdException`.

```php
// Auto-generated IDs (recommended) - always unique
Output::ownedBy('bob', 100);  // ID: auto-generated
Output::ownedBy('bob', 200);  // ID: different auto-generated

// Custom IDs - validated for uniqueness
Output::ownedBy('bob', 100, 'payment-1');  // OK
Output::ownedBy('bob', 200, 'payment-1');  // Throws DuplicateOutputIdException
```

This mirrors Bitcoin's UTXO model where each output has a unique `txid:vout` identifier, even when sending to the same address multiple times.
</details>

<details>
<summary><strong>When should I use in-memory mode vs store-backed mode?</strong></summary>

| Scenario | Recommendation |
|-|-|
| < 100k total outputs | `Ledger::inMemory()` or `Ledger::withGenesis(...)` |
| > 100k total outputs | `Ledger::withRepository($repository)` |
| Need full history in memory | `Ledger::inMemory()` |
| Memory-constrained environment | `Ledger::withRepository($repository)` |

Store-backed mode keeps only unspent outputs in memory and delegates history to a `HistoryRepository`. See [Scalability docs](docs/scalability.md).
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

## Learning Path

| Level | Topic | Docs | Example |
|-------|-------|------|---------|
| 1. Basics | Outputs, transactions | [Concepts](docs/concepts.md) | `php example/run game` |
| 2. Ownership | Locks, authorization | [Ownership](docs/ownership.md) | `php example/run wallet` |
| 3. Persistence | SQLite storage | [Persistence](docs/persistence.md) | `php example/run sqlite` |
| 4. Scale | Mode selection | [Scalability](docs/scalability.md) | - |
| 5. Advanced | Timelocks, multisig, HTLC | [Ownership](docs/ownership.md#timelock) | `php example/run locks` |
| 6. Operations | Batch, mempool, analytics | [API Reference](docs/api-reference.md) | - |

## Development

```bash
composer install  # Installs dependencies + pre-commit hook
composer test     # Runs cs-fixer, rector, phpstan, phpunit
```
