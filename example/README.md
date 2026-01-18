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

## Persistence

All examples use SQLite file-based persistence by default. Data is stored in `example/data/` and persists between runs:

```bash
php example/run game     # First run: creates new ledger
php example/run game     # Second run: continues from previous state
```

Each example creates its own `.db` file. Delete the file to reset:

```bash
rm example/data/sample:virtual-currency.db   # Reset game example
```

## Web API Example

A standalone web API demonstrating Unspent in a web context:

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

The web API uses PHP sessions for state persistence. For production use, replace with `SqliteHistoryRepository` or a custom repository.
