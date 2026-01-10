# Examples

Interactive demos showing different use cases for the Unspent library.

## Quick Start

```bash
# List all available examples
php example/run

# Run a specific example
php example/run btc
```

## Available Examples

| Command                      | Alias        | Description                                        |
|------------------------------|--------------|----------------------------------------------------|
| `sample:bitcoin-simulation`  | `btc`        | Multi-block mining with coinbase rewards and fees  |
| `sample:crypto-wallet`       | `wallet`     | Ed25519 signatures for trustless transactions      |
| `sample:loyalty-points`      | `loyalty`    | Customer rewards program with minting/redemption   |
| `sample:virtual-currency`    | `game`       | In-game economy with ownership, trades, and taxes  |
| `sample:custom-locks`        | `locks`      | Time-locked outputs with custom lock types         |
| `sample:event-sourcing`      | `events`     | Order lifecycle as state transitions               |
| `sample:internal-accounting` | `accounting` | Department budget tracking with audit trails       |
| `sample:sqlite-persistence`  | `sqlite`     | Database storage with query capabilities           |

## Run Modes

Most examples support two modes:

```bash
# Memory mode (default) - runs full demo, resets each time
php example/run btc

# Database mode - persists state between runs
php example/run btc --run-on=db
```

Database mode requires initializing the database first:

```bash
composer init-db
```

## Example Structure

```
example/
├── Console/
│   ├── AbstractExampleCommand.php   # Shared base class
│   ├── BitcoinSimulationCommand.php
│   ├── CryptoWalletCommand.php
│   └── ...
├── run                              # CLI entry point
└── README.md
```

Each command extends `AbstractExampleCommand` which provides:
- `--run-on=memory|db` option handling
- Ledger loading/creation helpers
- Database stats display
