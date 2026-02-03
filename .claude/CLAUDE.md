# Unspent - Claude Code Instructions

## Project Overview

Unspent is a PHP 8.4+ library implementing UTXO (Unspent Transaction Output) model for value tracking and ledger management. Zero external dependencies for core functionality.

## Architecture: Hexagonal / Ports & Adapters

```
src/
├── Core Domain (Ledger, Output, Tx, UnspentSet)
├── Ports
│   ├── LedgerInterface          # Primary port
│   ├── LedgerRepository         # Secondary port (persistence)
│   ├── HistoryRepository        # Secondary port (history)
│   └── OutputLock               # Authorization contract
└── Adapters
    ├── Sqlite/                  # Persistence adapter
    ├── InMemoryHistoryRepository
    └── Lock implementations (Owner, PublicKey, NoLock)
```

### Architecture Rules

1. **Domain Independence**: Core domain (`Ledger`, `Output`, `Tx`) must NEVER depend on infrastructure
2. **Dependency Direction**: Dependencies flow inward (Adapters → Ports → Domain)
3. **Port Abstractions**: All external interactions go through interfaces (ports)
4. **Adapter Isolation**: Adapters implement ports and contain infrastructure details
5. **Value Objects**: Use immutable value objects for identifiers (`OutputId`, `TxId`, `Id`)

## TDD Workflow

**Red-Green-Refactor cycle is mandatory for all changes:**

1. **Red**: Write a failing test first that describes the expected behavior
2. **Green**: Write the minimal code to make the test pass
3. **Refactor**: Clean up while keeping tests green

### Test Structure

```
tests/
├── Unit/           # Test individual components in isolation
│   ├── LedgerTest.php
│   ├── Lock/       # Test lock implementations
│   └── Persistence/ # Test repositories
└── Feature/        # Integration and end-to-end tests
```

### Test Naming Convention

```php
public function test_<action>_<scenario>_<expected_outcome>(): void
// Examples:
public function test_transfer_with_insufficient_balance_throws_exception(): void
public function test_credit_creates_new_output_owned_by_recipient(): void
```

## Code Quality Standards

### Must Pass Before Commit

```bash
composer test    # Runs: csrun, rector-dry, stan, phpunit
```

### Tools and Thresholds

| Tool | Command | Threshold |
|------|---------|-----------|
| PHPUnit | `composer phpunit` | All tests pass |
| PHPStan | `composer stan` | Level 2, no errors |
| PHP-CS-Fixer | `composer csrun` | No violations |
| Rector | `composer rector-dry` | No changes needed |
| Infection | `composer infection` | 80% MSI minimum |
| Coverage | `composer coverage:local` | Target 86%+ |

## Clean Code Principles

### Immutability by Default

- Domain objects (`Output`, `Tx`) use `readonly` properties
- `Ledger` is mutable but returns `$this` for fluent chaining
- `UnspentSet` uses copy-on-fork for efficient immutable interface

### Single Responsibility

- One class = one reason to change
- Small, focused methods (max ~20 lines)
- Extract strategies for algorithms (see `SelectionStrategy`)

### Explicit Over Implicit

- Use typed properties and return types everywhere
- Throw specific exceptions (see `src/Exception/` hierarchy)
- No magic methods or dynamic behavior

### Naming Conventions

| Type | Convention | Example |
|------|------------|---------|
| Interfaces | `*Interface` or descriptive | `LedgerInterface`, `OutputLock` |
| Repositories | `*Repository` | `LedgerRepository` |
| Strategies | `*Strategy` | `FifoStrategy` |
| Exceptions | `*Exception` | `OutputAlreadySpentException` |
| Value Objects | Noun | `OutputId`, `TxId` |

## When Adding New Features

### 1. New Lock Type

1. Create test in `tests/Unit/Lock/`
2. Implement `OutputLock` interface
3. Register in `LockFactory` if needed
4. Add feature test in `tests/Feature/`

### 2. New Selection Strategy

1. Create test in `tests/Unit/Selection/`
2. Implement `SelectionStrategy` interface
3. Document in API reference

### 3. New Persistence Adapter

1. Create directory in `src/Persistence/`
2. Implement `LedgerRepository` and `HistoryRepository`
3. Create schema class implementing `DatabaseSchema`
4. Add integration tests

### 4. New Domain Behavior

1. Start with test in `tests/Unit/LedgerTest.php`
2. Add to `LedgerInterface` if public API
3. Implement in `Ledger`
4. Add feature test if complex

## Code Style Requirements

- PHP 8.4+ features (readonly classes, typed properties, enums)
- Strict types: `declare(strict_types=1);` in every file
- PSR-12 coding style (enforced by PHP-CS-Fixer)
- No `@var`, `@param`, `@return` when types are declared
- Use constructor property promotion
- Prefer named arguments for clarity

## Documentation Requirements

- Update `docs/api-reference.md` for public API changes
- Create ADR in `docs/adr/` for architectural decisions
- Keep `README.md` examples working

## Commit Message Format

Always use conventional commits:
```
<type>(<scope>): <description>

Types: feat, fix, ref, test, docs, chore
Scope: ledger, tx, lock, persistence, selection, event, etc.
```

Examples:
```
feat(lock): add TimeLockedOutput for delayed spending
fix(ledger): prevent negative amounts in credit
ref(selection): extract common logic to base strategy
test(persistence): add sqlite repository edge cases
```
