# Examples

Runnable demos showing Unspent in action. All examples use SQLite persistence and resume state between runs.

## Quick Start

```bash
php example/run                  # List all examples
php example/run game             # Run an example
php example/run game --reset     # Reset state and rerun
```

## Available Examples

| Alias        | File                                                                | Demonstrates                                       |
|--------------|---------------------------------------------------------------------|----------------------------------------------------|
| `game`       | [VirtualCurrencyCommand.php](Console/VirtualCurrencyCommand.php)    | In-game currency, ownership, double-spend, fees    |
| `loyalty`    | [LoyaltyPointsCommand.php](Console/LoyaltyPointsCommand.php)        | Customer rewards, minting, redemption, audit       |
| `accounting` | [InternalAccountingCommand.php](Console/InternalAccountingCommand.php) | Department budgets, multi-party auth, reconcile |
| `events`     | [EventSourcingCommand.php](Console/EventSourcingCommand.php)        | Order lifecycle as state transitions               |
| `btc`        | [BitcoinSimulationCommand.php](Console/BitcoinSimulationCommand.php)| Bitcoin simulation, mining, fees, consolidation    |
| `wallet`     | [CryptoWalletCommand.php](Console/CryptoWalletCommand.php)          | Ed25519 signatures, trustless verification         |
| `locks`      | [CustomLocksCommand.php](Console/CustomLocksCommand.php)            | Custom time-locked outputs, serialization          |
| `sqlite`     | [SqlitePersistenceCommand.php](Console/SqlitePersistenceCommand.php)| SQLite persistence, querying, history              |

## Persistence

Each example writes to its own `.db` file under `example/data/`. Data persists between runs:

```bash
php example/run game        # First run: creates new ledger
php example/run game        # Second run: continues from previous state
php example/run game --reset # Deletes the db and starts fresh
```

You can also delete files manually:

```bash
rm example/data/sample:virtual-currency.db
```

## Sample outputs

<details>
<summary><strong>game</strong> — Virtual currency / in-game economy</summary>

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
```

</details>

<details>
<summary><strong>loyalty</strong> — Customer rewards</summary>

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
```

</details>

<details>
<summary><strong>accounting</strong> — Department budgets</summary>

```
Internal Accounting - Department Budgets
========================================

 Total budget: $180,000

 operations transfers $9,000 to marketing (fee: $180)

Security Demonstrations
-----------------------

 Finance tries to reallocate engineering funds...
 BLOCKED
 Marketing tries to overspend...
 BLOCKED

Reconciliation
--------------

 * Initial: $180,000
 * Fees: $180
 * Remaining: $179,820
 * Check: BALANCED
```

</details>

<details>
<summary><strong>wallet</strong> — Ed25519 signatures</summary>

```
Crypto Wallet - Ed25519 Signatures
==================================

 [Created new ledger with 2 genesis outputs]

 Alice -> Bob: 356 (signed)

 Mallory tries to steal with wrong key...
 BLOCKED
```

</details>

<details>
<summary><strong>events</strong> — Order lifecycle</summary>

```
Event Sourcing - Order Lifecycle
================================

 Order lifecycle: placed -> paid -> shipped -> delivered
 Each transition spends old state, creates new state

 order-1: placed
 order-1: paid
 order-1: shipped
 order-1: delivered
```

</details>

<details>
<summary><strong>btc</strong> — Bitcoin simulation</summary>

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
```

</details>

<details>
<summary><strong>locks</strong> — Time-locked outputs</summary>

```
Custom Locks - Time-Locked Outputs
==================================

 Registered 'timelock' handler

 alice spent unlocked funds -> charlie

Security Demonstrations
-----------------------

 bob tries to spend locked output...
 BLOCKED: Still locked until 2027-01-18
```

</details>

<details>
<summary><strong>sqlite</strong> — SQLite persistence</summary>

```
SQLite Persistence Example
==========================

 Connected to: data/ledger.db
 Ledger ID: sqlite-persistence

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
```

</details>

## Web API Example

Standalone web API demonstrating Unspent in a web context:

```bash
php -S localhost:8080 example/web-api.php
```

Then try:

```bash
# Get balance
curl "localhost:8080/balance?owner=alice"

# Transfer funds
curl -X POST localhost:8080/transfer \
  -d '{"from":"alice","to":"bob","amount":100}'

# Mint new value
curl -X POST localhost:8080/mint \
  -d '{"to":"charlie","amount":500}'

# View output history
curl "localhost:8080/history?id=alice-initial"
```

The web API uses PHP sessions for state. For production use, swap in `SqliteHistoryRepository` or a custom repository.
