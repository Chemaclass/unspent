# Contributing

## Setup

```bash
git clone https://github.com/chemaclass/unspent.git
cd unspent
composer install   # Installs dependencies + pre-commit hook
composer init-db   # Initialize database for persistence examples
```

The pre-commit hook runs `composer test` automatically before each commit.

## Development

```bash
composer test       # All checks (cs-fixer, rector, phpstan, phpunit)
composer bashunit   # Bash tests for scripts
composer phpunit    # PHP tests only
composer stan       # Static analysis only
composer csfix      # Fix code style
composer rector     # Apply rector refactorings
```

## Project Structure

```
src/
├── Ledger.php              # Interface for ledger operations
├── InMemoryLedger.php      # Full history in memory
├── ScalableLedger.php      # Delegated history storage
├── Tx.php                  # Transactions (spend inputs, create outputs)
├── CoinbaseTx.php          # Minting transactions
├── Output.php              # Value with ownership lock
├── UnspentSet.php          # UTXO collection
├── Lock/                   # Authorization (Owner, PublicKey, NoLock)
├── Persistence/            # SQLite storage, repositories
├── Validation/             # Input validation
└── Exception/              # Domain errors

tests/
├── Unit/                   # Individual components
├── Feature/                # Integration scenarios
└── bash/                   # Bash script tests

example/
├── run                     # Console application entry point
└── Console/                # Example commands
```

## Before PR

1. Add tests for new functionality
2. Run `composer test` - all checks must pass
3. Keep backward compatibility unless discussed

## Questions

Open an issue.
