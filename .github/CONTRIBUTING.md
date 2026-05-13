# Contributing

Thanks for your interest in improving Unspent. This document covers setup, the TDD workflow, quality gates, and conventions.

## Table of Contents

- [Setup](#setup)
- [Development Workflow (TDD)](#development-workflow-tdd)
- [Quality Gates](#quality-gates)
- [Commands Reference](#commands-reference)
- [Testing](#testing)
- [Docker Workflow](#docker-workflow)
- [Project Structure](#project-structure)
- [Architecture Rules](#architecture-rules)
- [Commit & PR](#commit--pr)

## Setup

### Native (PHP 8.4+)

```bash
git clone https://github.com/chemaclass/unspent.git
cd unspent
composer install      # Installs deps + symlinks pre-commit hook
composer init-db      # (optional) initialize the example SQLite DB
composer test         # Run full quality gate
```

### Docker

If you don't want to install PHP locally:

```bash
make build            # Build the dev image
make install          # Install composer deps inside the container
make test             # Run full quality gate
make shell            # Open an interactive shell in the container
```

Run `make help` for all targets.

## Development Workflow (TDD)

**TDD is mandatory.** Red → Green → Refactor. No production code without a failing test first.

```bash
composer test:fast        # Unit tests, stop on first failure (fast feedback)
composer test:unit        # All unit tests
composer test:feature     # Integration tests
composer check:quick      # CS-Fixer + PHPUnit (pre-commit speed)
composer test             # CS-Fixer + Rector + PHPStan + PHPUnit (full)
```

Workflow:

1. **Red** — write a failing test
2. **Green** — write the minimum code to pass
3. **Refactor** — clean up with the suite green

## Quality Gates

All gates must pass before commit. The pre-commit hook runs `check:quick`; CI runs the full suite.

| Tool         | Command                | Pass Criteria          | Pre-commit | CI  |
|--------------|------------------------|------------------------|------------|-----|
| PHP-CS-Fixer | `composer csrun`       | No violations          | Yes        | Yes |
| PHPUnit      | `composer phpunit`     | All pass               | Yes        | Yes |
| Rector       | `composer rector-dry`  | No suggested changes   | No         | Yes |
| PHPStan      | `composer stan`        | Level 8, 0 errors      | No         | Yes |
| Infection    | `composer infection`   | 90%+ MSI               | No         | Yes |

**Why not full `composer test` on pre-commit?** Rector and PHPStan take ~15s each. The hook prioritizes fast feedback (~2s cached); the full suite runs in CI.

Bypass with `git commit --no-verify` only when you know what you're doing.

## Commands Reference

### Fast feedback

```bash
composer test:fast        # Unit, stop on first failure
composer test:unit        # All unit tests
composer test:feature     # Integration tests
```

### Full quality gate

```bash
composer check:quick      # CS-Fixer + PHPUnit (~2s cached)
composer check:full       # CS-Fixer + Rector + PHPStan + PHPUnit
composer test             # Same as check:full
```

### Individual tools

```bash
composer phpunit          # All tests with coverage
composer stan             # PHPStan static analysis
composer csfix            # Apply CS-Fixer changes
composer csrun            # CS-Fixer dry-run
composer rector           # Apply Rector changes
composer rector-dry       # Rector dry-run
composer infection        # Mutation testing
composer benchmark        # PHPBench
composer coverage         # HTML coverage report under coverage/
```

### Auto-fix

```bash
composer fix              # csfix + rector
```

## Testing

### Layout

```
tests/
├── Unit/        # Fast, isolated unit tests
├── Feature/     # End-to-end integration scenarios
├── Benchmark/   # PHPBench performance tests
└── bash/        # Shell script tests
```

### Naming

```php
public function test_<action>_<scenario>_<expected_outcome>(): void

// Examples:
public function test_transfer_with_insufficient_balance_throws_exception(): void
public function test_credit_creates_new_output_owned_by_recipient(): void
```

Use Arrange–Act–Assert and `assertSame()` for strict comparison. Test behavior, not implementation.

### Mocks

Use PHPUnit's `mock()` directly (no Mockery). For stubs without expectations, use `createStub()` to avoid notices.

### Mutation testing

After tests pass, verify they're meaningful:

```bash
composer infection
```

Minimum **90% MSI** required.

## Docker Workflow

Common targets — see the full list with `make help`:

```bash
make test           # Full quality gate
make phpunit        # Tests only
make coverage       # HTML coverage report
make stan           # PHPStan
make csfix          # Apply CS-Fixer
make rector         # Apply Rector
make shell          # Interactive shell
make clean          # Remove caches and coverage
```

## Project Structure

```
src/
├── Ledger.php              # Main ledger (mutable, fluent)
├── LedgerInterface.php     # Public contract
├── Tx.php                  # Transactions (spend inputs, create outputs)
├── CoinbaseTx.php          # Minting transactions
├── Output.php              # Value with ownership lock
├── UnspentSet.php          # UTXO collection
├── Mempool.php             # Pending-tx staging area
├── UtxoAnalytics.php       # Stats helpers
├── Lock/                   # Owner, PublicKey, NoLock, TimeLock, MultisigLock, HashLock
├── Persistence/            # InMemory + SQLite repositories, schema
├── Selection/              # FIFO, LargestFirst, SmallestFirst, ExactMatch, Random
├── Event/                  # EventDispatchingLedger + LedgerEvent types
├── Logging/                # LoggingLedger decorator
├── Validation/             # Input validation
├── Exception/              # Domain errors
└── Console/                # Built-in CLI commands

tests/
├── Unit/                   # Per-class tests
├── Feature/                # End-to-end scenarios
├── Benchmark/              # Performance
└── bash/                   # Shell tests

example/
├── run                     # Examples entry point
└── Console/                # Demo commands
```

## Architecture Rules

1. **Hexagonal**: domain (`src/*.php`) never depends on infrastructure. Dependencies flow inward only.
2. **Immutability**: domain objects (`Output`, `Tx`, `CoinbaseTx`) are `readonly`. Value objects self-validate.
3. **Strict types**: every file declares `declare(strict_types=1);`. Typed properties and return types everywhere.
4. **Ports & adapters**: implement `LedgerInterface`, `LedgerRepository`, `HistoryRepository`, `OutputLock`, `SelectionStrategy` to extend the system without touching the domain.

## Commit & PR

### Conventional Commits

```
<type>(<scope>): <description>

Types: feat, fix, ref, test, docs, chore
Scope: ledger, tx, lock, persistence, selection, event
```

Example:

```
feat(selection): add RandomStrategy for privacy
```

Use `ref:` (not `refactor:`) for refactoring commits. Never mention Claude/Anthropic in commits.

### Before opening a PR

1. Add tests for new functionality
2. `composer test` — all gates must pass
3. Update CHANGELOG.md under `[Unreleased]`
4. Keep backward compatibility unless previously discussed in an issue

## Questions

Open an issue: <https://github.com/Chemaclass/unspent/issues>
