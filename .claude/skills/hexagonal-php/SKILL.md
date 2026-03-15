# Hexagonal Architecture for PHP Libraries

Reference for the Unspent library's hexagonal (ports & adapters) architecture.

## Layers

### Domain (Innermost) — `src/` root
- `Ledger.php`, `Output.php`, `Tx.php`, `UnspentSet.php`, `TxBuilder.php`, `CoinbaseTx.php`
- Identifiers: `Id.php`, `OutputId.php`, `TxId.php`
- Enums: `OutputStatus.php`
- **Rules:** NO external dependencies, NO infrastructure imports, pure PHP only

### Ports (Interfaces)
**Primary:** `LedgerInterface`
**Secondary:** `LedgerRepository`, `HistoryRepository`, `OutputLock`, `SelectionStrategy`

### Adapters — implement ports
- **Persistence:** `src/Persistence/Sqlite/`, `InMemoryHistoryRepository`
- **Locks:** `src/Lock/` (Owner, PublicKey, NoLock)
- **Selection:** `src/Selection/` (Fifo, LargestFirst, SmallestFirst, ExactMatch)

## Dependency Direction

```
Adapters → Ports → Domain (inward only)
```

**Valid:** adapter imports interface, domain imports nothing from infrastructure.
**Invalid:** domain importing `Persistence\Sqlite\*`, `PDO`, etc.

## Adding Components

| Type | Location | Implements |
|------|----------|-----------|
| Lock | `src/Lock/` | `OutputLock` |
| Strategy | `src/Selection/` | `SelectionStrategy` |
| Repository | `src/Persistence/{Name}/` | `LedgerRepository` / `HistoryRepository` |

Domain remains unchanged — new components plug in via interfaces.

## Testing Layers

| Layer | Test Type | Dependencies |
|-------|-----------|--------------|
| Domain | Unit | None (pure PHP) |
| Adapters | Integration | Real infrastructure |
| Full | Feature | Everything |
