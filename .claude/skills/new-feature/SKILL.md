# New Feature Implementation

Implement a new feature following hexagonal architecture and TDD. `$ARGUMENTS` describes the feature.

## Steps

1. **Clarify requirements** — Understand what, who uses it, what external systems it needs.
2. **Design interface** — Define the port (interface) if needed.
3. **TDD cycle** — Red → Green → Refactor for each behavior.
4. **Quality check** — Run `composer test` before finishing.
5. **Commit message** — Provide conventional commit message.

## Feature Type Guide

### New Lock Type
1. Test in `tests/Unit/Lock/{Name}Test.php`
2. Implement `OutputLock` in `src/Lock/{Name}.php`
3. Register in `LockFactory` if needed
4. Feature test in `tests/Feature/`

### New Selection Strategy
1. Test in `tests/Unit/Selection/{Name}Test.php`
2. Implement `SelectionStrategy` in `src/Selection/{Name}.php`

### New Persistence Adapter
1. Create `src/Persistence/{Name}/`
2. Implement `LedgerRepository` and `HistoryRepository`
3. Create schema class implementing `DatabaseSchema`
4. Tests in `tests/Unit/Persistence/{Name}/`

### New Domain Behavior
1. Test in `tests/Unit/LedgerTest.php`
2. Add to `LedgerInterface` if public API
3. Implement in `Ledger`
4. Feature test if complex

## Checklist
- [ ] Failing test written first
- [ ] Implementation is minimal
- [ ] `composer test` passes
- [ ] Domain has no infrastructure imports
