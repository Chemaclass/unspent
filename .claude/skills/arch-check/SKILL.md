# Architecture Validation

Validate hexagonal architecture compliance. If `$ARGUMENTS` specifies files, focus on those. Otherwise check the full codebase.

## Checks

### 1. Domain Independence
Scan imports in domain files: `src/Ledger.php`, `src/Output.php`, `src/Tx.php`, `src/UnspentSet.php`, `src/TxBuilder.php`, `src/CoinbaseTx.php`.

**Forbidden in domain:** `Persistence\Sqlite\*`, `PDO`, external libraries.

### 2. Dependency Direction
Adapters (`src/Persistence/`, `src/Lock/`, `src/Selection/`) → Ports (interfaces) → Domain.

### 3. Port Coverage
All external interactions via interfaces: `LedgerRepository`, `HistoryRepository`, `OutputLock`, `SelectionStrategy`, `LedgerInterface`.

### 4. Immutability
Value objects (`OutputId`, `TxId`, `Id`, `Output`, `Tx`) must be `readonly`.

## Report Format

| Check | Status | Issues |
|-------|--------|--------|
| Domain independence | PASS/FAIL | details |
| Dependency direction | PASS/FAIL | details |
| Port coverage | PASS/FAIL | details |
| Immutability | PASS/FAIL | details |

### Violations (if any)
- `file:line` — description

### Placement Rules
| Component | Location |
|-----------|----------|
| Lock | `src/Lock/` implements `OutputLock` |
| Strategy | `src/Selection/` implements `SelectionStrategy` |
| Repository | `src/Persistence/{Name}/` implements repo interfaces |
| Domain | `src/` root |
