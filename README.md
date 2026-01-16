# Unspent

[![PHP 8.4+](https://img.shields.io/badge/PHP-8.4+-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![MIT License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Mutation testing badge](https://img.shields.io/endpoint?style=flat&url=https%3A%2F%2Fbadge-api.stryker-mutator.io%2Fgithub.com%2FChemaclass%2Funspent%2Fmain)](https://dashboard.stryker-mutator.io/reports/github.com/Chemaclass/unspent/main)

**Track value like physical cash in your PHP apps.** Every unit has an origin, can only be spent once, and leaves a complete audit trail.

```php
// 3 lines to get started
$ledger = Ledger::inMemory()->credit('alice', 100);
$ledger = $ledger->transfer('alice', 'bob', 25);
echo $ledger->totalUnspentByOwner('bob'); // 25
```

<details>
<summary><strong>Full example with all operations</strong></summary>

```php
$ledger = Ledger::inMemory()
    ->credit('alice', 1000)             // Mint 1000 for Alice
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

### Simple API

For most use cases, use the convenience methods:

```php
// Create a ledger and mint initial balances
$ledger = Ledger::inMemory()
    ->credit('alice', 1000)    // Mint 1000 to alice
    ->credit('bob', 500);      // Mint 500 to bob

// Transfer between users (auto handles change)
$ledger = $ledger->transfer('alice', 'bob', 200);

// Transfer with fee (5 units burned)
$ledger = $ledger->transfer('alice', 'bob', 100, fee: 5);

// Debit/burn value (redemption, purchase, etc.)
$ledger = $ledger->debit('bob', 50);

// Check balances
$ledger->totalUnspentByOwner('alice');  // 695
$ledger->totalUnspentByOwner('bob');    // 650
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
$ledger = $ledger->apply(Tx::create(
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

**Use coin control when you need:**
- Specific output selection (spend oldest first, consolidate dust, etc.)
- Custom output IDs for tracking
- Multiple recipients in one transaction
- Complex fee structures

### Output types

| Method                           | Use case                          |
|----------------------------------|-----------------------------------|
| `Output::open(100)`              | No lock - pure bookkeeping        |
| `Output::ownedBy('alice', 100)`  | Server-side auth (sessions, JWT)  |
| `Output::signedBy($pubKey, 100)` | Ed25519 crypto (trustless)        |
| `Output::lockedWith($lock, 100)` | Custom locks (multisig, timelock) |

## Use Cases

<details>
<summary><strong>In-game currency</strong> — Ownership, double-spend prevention, implicit fees</summary>

📄 [Source code](example/Console/VirtualCurrencyCommand.php)

```
Virtual Currency - In-Game Economy (Flagship Demo)
==================================================

 Mode: memory

 Game started: Alice=1000g, Bob=500g

Minting
-------

 Admin minted 100g daily bonus for Alice
 Total minted so far: 100g

Purchase
--------

 Alice bought sword (-200g), now has 900g total

Security
--------

 Mallory tries to steal Bob's gold...
 BLOCKED
 Alice tries to spend already-spent gold...
 BLOCKED

Quest Reward
------------

 Alice completed quest! Reward: 500g (locked for 1 hour)
 Alice tries to spend locked reward...
 BLOCKED (cooldown active)

Trade
-----

 Bob paid Alice 450g (50g fee/tax)
 Fee collected: 50g

History Tracing
---------------

 alice-change: created by 'buy-sword'
 daily-bonus: created by 'mint-daily-bonus' (minted)

Final State
-----------

   alice: 1850g
   shop: 200g

 Total in circulation: 2050g
 Total fees (burned): 50g
 Total minted: 600g
 UTXOs: 5
```

</details>

<details>
<summary><strong>Loyalty points</strong> — Minting new value, redemption, audit trails</summary>

📄 [Source code](example/Console/LoyaltyPointsCommand.php)

```
Loyalty Points - Customer Rewards Program
=========================================

 Mode: memory

 Alice bought $50 -> earned 50 pts
 Alice bought $30 -> earned 30 pts

 Total points minted: 80
 Alice's balance: 80 pts

 Alice redeemed 60 pts for coffee voucher
 Remaining: 80 pts

Audit Trail
-----------

 purchase-001: minted in earn-50, spent in redeem-coffee

Final State
-----------

   coffee-voucher: 60 pts (none)
   change: 20 pts (owner)
```

</details>

<details>
<summary><strong>Internal accounting</strong> — Multi-party authorization, reconciliation</summary>

📄 [Source code](example/Console/InternalAccountingCommand.php)

```
Internal Accounting - Department Budgets
========================================

 FY Budget: Eng=$100k, Mkt=$50k, Ops=$30k
 Total: $180000

 Engineering splits: projects=$60k, infra=$40k

 Finance tries to reallocate engineering funds...
 BLOCKED

 Ops transfers $15k to Marketing (2% admin fee)
 Fee: $600

 Marketing tries to overspend...
 BLOCKED

Audit Trail
-----------

 mkt-campaign: created by ops-to-mkt

Reconciliation
--------------

 * Initial: $180000
 * Fees: $600
 * Remaining: $179400
 * Check: BALANCED

Budget by Department
--------------------

   marketing: $65,000
   engineering: $100,000
   operations: $14,400
```

</details>

<details>
<summary><strong>Crypto wallet</strong> — Ed25519 signatures, trustless verification</summary>

📄 [Source code](example/Console/CryptoWalletCommand.php)

```
Crypto Wallet - Ed25519 Signatures
==================================

 Mode: memory

Keys Generated
--------------

 * Alice: P2n5f7zT2a3ok8QX...
 * Bob: Y+U9UIYQmP5FTJM9...

 Wallets: Alice=1000, Bob=500

 Alice -> Bob: 300 (signed)

 Mallory tries to steal with wrong key...
 BLOCKED

 Bob combined 500+300 = 800 (multi-sig)

History
-------

 bob-combined: created by tx-002

Final Balances
--------------

   alice-change: 700
   bob-combined: 800
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

 Order #1001: placed
 Order #1001: paid
 Order #1001: shipped
 Order #1001: delivered

Event Chain
-----------

   order-1001_placed: genesis -> evt_payment
   order-1001_paid: evt_payment -> evt_shipped
   order-1001_shipped: evt_shipped -> evt_delivered
   order-1001_delivered: evt_delivered (current)

Multiple Orders
---------------

   order-2002: placed
   order-2001: paid
```

</details>

<details>
<summary><strong>Bitcoin simulation</strong> — Coinbase mining, fees, UTXO consolidation</summary>

📄 [Source code](example/Console/BitcoinSimulationCommand.php)

```
Bitcoin Simulation - Multi-Block Mining
=======================================

 Mode: memory

 Block 0: Satoshi mines 50 BTC
 Block 1: Satoshi mines 50 BTC (total: 100 BTC)

 Block 2: Satoshi sends 10 BTC to Hal
   Fee: 1.0E-5 BTC

 Block 3: Hal buys pizza for 5 BTC

 Block 4: Satoshi consolidates 3 UTXOs into 1

Final State
-----------

 * Blocks mined: 5
 * Total minted: 200 BTC
 * Total fees: 0.02001 BTC
 * In circulation: 249.97999 BTC
 * UTXOs: 5

UTXOs
-----

   laszlo-pizza: 5 BTC
   hal-change: 4.99999 BTC
   miner-3: 50 BTC
   satoshi-consolidated: 139.98 BTC
   miner-4: 50 BTC
```

</details>

<details>
<summary><strong>Custom locks</strong> — Timelocks, custom lock types, serialization</summary>

📄 [Source code](example/Console/CustomLocksCommand.php)

```
Custom Locks - Time-Locked Outputs
==================================

 Registered 'timelock' handler

 Restored lock type: Example\Console\TimeLock

 Alice spent unlocked funds
 Bob tries to spend locked output...
 BLOCKED: Still locked until 2027-01-12
 Eve tries to spend Alice's output...
 BLOCKED
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
composer init-db          # Initialize database for persistence examples
```
See [example/README.md](example/README.md) for details.

## Documentation

| Topic | What you'll learn |
|-|-|
| [Core Concepts](docs/concepts.md) | How outputs, transactions, and the ledger work |
| [Ownership](docs/ownership.md) | Locks, authorization, custom lock types |
| [History](docs/history.md) | Tracing value through transactions |
| [Fees & Minting](docs/fees-and-minting.md) | Implicit fees, coinbase transactions |
| [Persistence](docs/persistence.md) | JSON, SQLite, custom storage |
| [Scalability](docs/scalability.md) | In-memory mode vs store-backed mode for large datasets |
| [Migration Guide](docs/migration.md) | Moving from balance-based systems to UTXO |
| [Troubleshooting](docs/troubleshooting.md) | Common issues and solutions |
| [API Reference](docs/api-reference.md) | Complete method reference |

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
    spendIds: ['input-100'],      // Spending 100
    outputs: [Output::open(95)],  // Creating 95
));
// Fee = 100 - 95 = 5 (implicit)
```

See [Fees & Minting docs](docs/fees-and-minting.md).
</details>

## Development

```bash
composer install  # Installs dependencies + pre-commit hook
composer test     # Runs cs-fixer, rector, phpstan, phpunit
```
