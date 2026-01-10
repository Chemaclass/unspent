# Unspent

UTXO-style bookkeeping for PHP. Track value like Bitcoin - every unit has an origin, can only be spent once, and leaves an audit trail.

## Install

```bash
composer require chemaclass/unspent
```

Requires PHP 8.4+

## Quick Start

```php
use Chemaclass\Unspent\{Ledger, Output, Tx};

// Create ledger with initial value
$ledger = Ledger::withGenesis(
    Output::ownedBy('alice', 1000, 'alice-funds'),
);

// Transfer value (alice -> bob, with change back to alice)
$ledger = $ledger->apply(Tx::create(
    inputIds: ['alice-funds'],
    outputs: [
        Output::ownedBy('bob', 600, 'bob-funds'),
        Output::ownedBy('alice', 390, 'alice-change'),  // 10 goes to fees
    ],
    signedBy: 'alice',
));

// Query state
$ledger->totalUnspentAmount();  // 990
$ledger->unspent()->get(new OutputId('bob-funds'))->amount;  // 600
```

## Use Cases

| Idea                  | Example                                                    | Description                             |
|-----------------------|------------------------------------------------------------|-----------------------------------------|
| Virtual currencies    | [virtual-currency.php](example/virtual-currency.php)       | In-game gold, tokens, credits           |
| Loyalty points        | [loyalty-points.php](example/loyalty-points.php)           | Every point traceable from earn to burn |
| Internal accounting   | [internal-accounting.php](example/internal-accounting.php) | Audit-ready, no hidden mutations        |
| Event sourcing        | [event-sourcing.php](example/event-sourcing.php)           | Order lifecycle as domain events        |
| Crypto wallets        | [crypto-wallet.php](example/crypto-wallet.php)             | Trustless Ed25519 signatures            |
| Custom locks          | [custom-locks.php](example/custom-locks.php)               | TimeLock example with LockFactory       |
| SQLite persistence    | [sqlite-persistence.php](example/sqlite-persistence.php)   | Database storage with query support     |
| Blockchain simulation | [bitcoin-simulation.php](example/bitcoin-simulation.php)   | Multi-block Bitcoin-style mining        |

## Documentation

| Topic                                      | Description                             |
|--------------------------------------------|-----------------------------------------|
| [Core Concepts](docs/concepts.md)          | UTXO model, outputs, transactions       |
| [Ownership](docs/ownership.md)             | Simple, cryptographic, and custom locks |
| [History & Provenance](docs/history.md)    | Trace outputs through transactions      |
| [Fees & Minting](docs/fees-and-minting.md) | Implicit fees and coinbase transactions |
| [Persistence](docs/persistence.md)         | JSON, SQLite, and custom storage        |
| [API Reference](docs/api-reference.md)     | Complete method reference               |
