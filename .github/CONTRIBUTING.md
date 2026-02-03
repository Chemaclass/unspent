# Contributing

## Setup

```bash
git clone https://github.com/chemaclass/unspent.git
cd unspent
composer install   # Installs dependencies + pre-commit hook
composer init-db   # Initialize database for persistence examples
```

## Pre-commit Hook

The pre-commit hook runs automatically before each commit:

```bash
# What it runs (fast - ~2s cached):
composer check:quick    # CS-Fixer + PHPUnit only

# Bypass if needed (not recommended):
git commit --no-verify
```

**Why not full `composer test`?**

The hook prioritizes fast feedback. Rector (~15s) and PHPStan (~15s) run in CI instead, keeping local commits quick.

| Check | Pre-commit | CI |
|-------|------------|-----|
| CS-Fixer | Yes | Yes |
| PHPUnit | Yes | Yes |
| Rector | No | Yes |
| PHPStan | No | Yes |
| Infection | No | Yes |

## Development Commands

### Fast Feedback (TDD)

```bash
composer test:fast      # Unit tests, stop on first failure
composer test:unit      # All unit tests
composer test:feature   # All feature tests
```

### Quality Checks

```bash
composer check:quick    # CS-Fixer + PHPUnit (~2s cached)
composer check:full     # CS-Fixer + Rector + PHPStan + PHPUnit
composer test           # Same as check:full
```

### Individual Tools

```bash
composer phpunit        # All tests with coverage
composer stan           # Static analysis
composer csfix          # Fix code style
composer csrun          # Check code style (no fix)
composer rector         # Apply rector refactorings
composer rector-dry     # Check rector (no changes)
composer infection      # Mutation testing (80% MSI required)
composer bashunit       # Bash script tests
```

## Testing Strategy

### Test Organization

```
tests/
├── Unit/               # Fast, isolated tests
│   ├── LedgerTest.php  # Core ledger behavior
│   ├── Lock/           # Lock implementations
│   └── Persistence/    # Repository tests
└── Feature/            # Integration tests
    └── ...             # End-to-end scenarios
```

### Test Naming Convention

```php
public function test_<action>_<scenario>_<expected_outcome>(): void

// Examples:
public function test_transfer_with_insufficient_balance_throws_exception(): void
public function test_credit_creates_new_output_owned_by_recipient(): void
```

### TDD Workflow

1. **Red**: Write a failing test first
   ```bash
   composer test:fast   # Run until it fails
   ```

2. **Green**: Write minimal code to pass
   ```bash
   composer test:fast   # Run until green
   ```

3. **Refactor**: Clean up while green
   ```bash
   composer test:unit   # Full unit suite
   ```

### Mutation Testing

After your tests pass, verify they're meaningful:

```bash
composer infection
```

Minimum 80% MSI (Mutation Score Indicator) required. If mutations survive, your tests may not be catching edge cases.

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
