# Architecture Validation

Verify the codebase follows hexagonal architecture principles.

## Checks to Perform

### 1. Dependency Direction

Verify dependencies flow inward (Adapters -> Ports -> Domain):

**Domain Layer** (`Ledger.php`, `Output.php`, `Tx.php`, `UnspentSet.php`):
- MUST NOT import from `Persistence/Sqlite/`
- MUST NOT import from infrastructure adapters
- CAN import from other domain classes and interfaces

**Check imports in core domain files**:
```
src/Ledger.php
src/Output.php
src/Tx.php
src/UnspentSet.php
src/TxBuilder.php
src/CoinbaseTx.php
```

### 2. Port Abstraction

Verify all external interactions go through interfaces:

**Primary Ports** (driving):
- `LedgerInterface` - main API contract

**Secondary Ports** (driven):
- `LedgerRepository` - persistence abstraction
- `HistoryRepository` - history storage abstraction
- `OutputLock` - authorization contract
- `SelectionStrategy` - coin selection contract

### 3. Adapter Isolation

Verify adapters only implement ports and contain no domain logic:

**Persistence Adapters**:
- `Sqlite/SqliteLedgerRepository` implements `LedgerRepository`
- `Sqlite/SqliteHistoryRepository` implements `HistoryRepository`
- `InMemoryHistoryRepository` implements `HistoryRepository`

**Lock Adapters**:
- `Owner`, `PublicKey`, `NoLock` implement `OutputLock`

### 4. Value Object Immutability

Verify value objects are immutable:
- `OutputId`, `TxId`, `Id` - should use readonly properties
- `Output` - should be readonly class
- `Tx` - should be readonly class

## Report Format

```
## Architecture Validation Report

### Dependency Direction
- [x] Domain has no infrastructure imports
- [x] Ports define abstractions
- [ ] Issue: {description}

### Port Coverage
- [x] Persistence goes through LedgerRepository
- [x] History goes through HistoryRepository
- [x] Authorization goes through OutputLock

### Adapter Compliance
- [x] SqliteLedgerRepository implements interface correctly
- [x] Lock implementations are stateless

### Immutability
- [x] Value objects are readonly
- [x] Domain objects use immutable patterns

### Recommendations
{List any architectural improvements}
```

## What to Check

Search for anti-patterns:
1. Domain classes importing from `Sqlite/` namespace
2. Direct database access without repository
3. Business logic in adapter classes
4. Mutable value objects
5. Missing interface abstractions
