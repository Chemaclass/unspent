# Examples

Interactive demos showing Unspent library use cases.

## Quick Start

```bash
php example/run           # List all examples
php example/run game      # Run virtual currency demo
```

## Available Examples

| Alias        | Description                                       |
|--------------|---------------------------------------------------|
| `game`       | In-game currency with trades and taxes            |
| `loyalty`    | Customer rewards with minting and redemption      |
| `accounting` | Department budgets with audit trails              |
| `events`     | Order lifecycle as state transitions              |
| `btc`        | Bitcoin simulation with mining and fees           |
| `wallet`     | Crypto wallet with Ed25519 signatures             |
| `locks`      | Custom time-locked outputs                        |
| `sqlite`     | SQLite persistence demo                           |

## Database Mode

Some examples support persistent storage:

```bash
composer init-db              # Initialize database (once)
php example/run btc --run-on=db   # Run with persistence
```

Run multiple times to see state accumulate between runs.
