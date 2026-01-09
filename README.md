# Unspent

UTXO-style bookkeeping for PHP. Think Bitcoin's transaction model, but for your app.

## The Problem

You're tracking balances with a single number: `alice_balance = 500`. Cool, but:

- Where did those 500 come from?
- Were any spent twice? (oops)
- Can you prove the history?

Traditional balance tracking is a black box.

## The Solution

Track value like Bitcoin tracks coins. Every unit has an origin, can only be spent once, and leaves a trail.

```php
$ledger = Ledger::empty()->addGenesis(
    Output::ownedBy('alice', 1000, 'genesis'),
);

$ledger = $ledger->apply(Spend::create(
    inputIds: ['genesis'],
    outputs: [
        Output::ownedBy('bob', 600),
        Output::ownedBy('alice', 390),
    ],
    signedBy: 'alice',
));

$ledger->totalUnspentAmount();  // 990 (10 went to fees)
```

Full audit trail. Double-spend protection. Ownership verification.

## Use Cases

- **Virtual currencies** - in-game gold, tokens, credits
- **Loyalty points** - every point traceable from earn to burn
- **Internal accounting** - audit-ready, no hidden mutations
- **Event sourcing** - fits naturally with event-sourced domains

## Install

```bash
composer require chemaclass/unspent
```

Requires PHP 8.4+

## Quick Start

```php
use Chemaclass\Unspent\{Ledger, Output, Spend};

// 1. Create initial value
$ledger = Ledger::empty()->addGenesis(
    Output::ownedBy('alice', 1000, 'alice-funds'),
);

// 2. Transfer value
$ledger = $ledger->apply(Spend::create(
    inputIds: ['alice-funds'],
    outputs: [
        Output::ownedBy('bob', 600, 'bob-funds'),
        Output::ownedBy('alice', 400, 'alice-change'),
    ],
    signedBy: 'alice',
));

// 3. Query state
$ledger->totalUnspentAmount();                    // 1000
$ledger->unspent()->get(new OutputId('bob-funds'))->amount;  // 600
```

## Documentation

| Topic | Description |
|-------|-------------|
| [Core Concepts](docs/concepts.md) | UTXO model, outputs, spends, ledger |
| [Ownership](docs/ownership.md) | Simple, cryptographic, and custom locks |
| [History & Provenance](docs/history.md) | Trace outputs through transactions |
| [Fees & Minting](docs/fees-and-minting.md) | Implicit fees and coinbase transactions |
| [Persistence](docs/persistence.md) | JSON/array serialization |
| [API Reference](docs/api-reference.md) | Complete method reference |

## Examples

See the [`example/`](example/) directory:

- [`demo.php`](example/demo.php) - Comprehensive feature walkthrough
- [`bitcoin-simulation.php`](example/bitcoin-simulation.php) - Multi-block Bitcoin-style simulation

## Contributing

See [CONTRIBUTING.md](.github/CONTRIBUTING.md).

## License

MIT
