# Unspent - UTXO Library (PHP 8.4+)

Zero-dependency PHP library implementing the UTXO model for value tracking and ledger management.

## Hard Rules

1. **TDD mandatory**: Red-Green-Refactor. No production code without a failing test first.
2. **Hexagonal architecture**: Domain NEVER depends on infrastructure. Dependencies flow inward only.
3. **Immutability**: Domain objects (`Output`, `Tx`) are `readonly`. Value objects are self-validating.
4. **`declare(strict_types=1)`** in every file. Typed properties and return types everywhere.
5. **All quality gates must pass before commit**: `composer test` (runs csrun, rector-dry, stan, phpunit).

## Quality Gates

| Tool | Command | Pass Criteria |
|------|---------|---------------|
| PHP-CS-Fixer | `composer csrun` | No violations |
| Rector | `composer rector-dry` | No changes |
| PHPStan | `composer stan` | Level 8, 0 errors |
| PHPUnit | `composer phpunit` | All pass |
| Infection | `composer infection` | 90%+ MSI |
| Quick check | `composer check:quick` | csrun + phpunit |
| Auto-fix | `composer csfix && composer rector` | Apply fixes |

## Architecture Layers

```
Domain (src/*.php)          → Pure PHP, no infra imports
Ports (interfaces)          → LedgerInterface, LedgerRepository, HistoryRepository, OutputLock, SelectionStrategy
Adapters (src/Persistence/, src/Lock/, src/Selection/) → Implement ports
```

## Test Conventions

- Location: `tests/Unit/` (isolated) and `tests/Feature/` (integration)
- Naming: `test_<action>_<scenario>_<expected_outcome>()`
- Pattern: Arrange-Act-Assert
- Use `assertSame()` for strict comparison
- Test behavior, not implementation

## Commit Format

```
<type>(<scope>): <description>
Types: feat, fix, ref, test, docs, chore
Scope: ledger, tx, lock, persistence, selection, event
```

## Skills (Slash Commands)

| Skill | Purpose |
|-------|---------|
| `/tdd-workflow` | Red-Green-Refactor cycle |
| `/new-feature` | Implement new feature with TDD |
| `/new-test` | Generate test file |
| `/quality` | Run all quality tools |
| `/mutation` | Mutation testing analysis |
| `/review` | Code review |
| `/arch-check` | Architecture validation |
| `/refactor` | Safe refactoring |
| `/hexagonal-php` | Architecture reference |
| `/solid-principles` | SOLID reference |
