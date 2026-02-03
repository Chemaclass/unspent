# Hexagonal Architecture for PHP Libraries

## Activation Triggers
- Discussing architecture
- Adding new components
- Reviewing layer boundaries
- Creating persistence adapters
- Implementing new ports

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                        INFRASTRUCTURE                            │
│  ┌───────────────────────────────────────────────────────────┐  │
│  │                       APPLICATION                          │  │
│  │  ┌─────────────────────────────────────────────────────┐  │  │
│  │  │                      DOMAIN                          │  │  │
│  │  │                                                      │  │  │
│  │  │   Entities: Ledger, Output, Tx, UnspentSet          │  │  │
│  │  │   Value Objects: OutputId, TxId, Id                 │  │  │
│  │  │   Enums: OutputStatus, LockType                     │  │  │
│  │  │                                                      │  │  │
│  │  └─────────────────────────────────────────────────────┘  │  │
│  │                                                            │  │
│  │   Ports (Interfaces):                                      │  │
│  │   - LedgerInterface (primary)                              │  │
│  │   - LedgerRepository, HistoryRepository (secondary)        │  │
│  │   - OutputLock, SelectionStrategy (secondary)              │  │
│  │                                                            │  │
│  └───────────────────────────────────────────────────────────┘  │
│                                                                  │
│   Adapters:                                                      │
│   - Sqlite/SqliteLedgerRepository, SqliteHistoryRepository       │
│   - InMemoryHistoryRepository                                    │
│   - Owner, PublicKey, NoLock (lock adapters)                     │
│   - FifoStrategy, LargestFirstStrategy, etc.                     │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

## Layer Rules

### Domain Layer (Innermost)
**Location:** `src/` root files
**Contains:**
- `Ledger.php` - Core UTXO state machine
- `Output.php` - Immutable value chunk
- `Tx.php` - Immutable transaction
- `UnspentSet.php` - Unspent output collection
- `TxBuilder.php` - Transaction builder
- `CoinbaseTx.php` - Value creation
- Identifiers: `Id.php`, `OutputId.php`, `TxId.php`
- Enums: `OutputStatus.php`

**Rules:**
- NO external dependencies
- NO infrastructure imports
- NO database code
- Pure PHP only

### Ports (Interfaces)
**Location:** Interface files in domain/application

**Primary Ports (Driving):**
```php
interface LedgerInterface {
    public function credit(string $owner, int $amount): LedgerInterface;
    public function transfer(string $from, string $to, int $amount): LedgerInterface;
    public function debit(string $owner, int $amount): LedgerInterface;
    public function apply(Tx $tx): LedgerInterface;
}
```

**Secondary Ports (Driven):**
```php
interface LedgerRepository {
    public function find(string $ledgerId): ?Ledger;
    public function save(string $ledgerId, Ledger $ledger): void;
}

interface HistoryRepository {
    public function recordTransaction(string $txId, int $fee = 0): void;
    public function recordOutput(OutputId $id, string $createdBy): void;
    public function recordSpend(OutputId $id, string $spentBy): void;
}

interface OutputLock {
    public function validate(Tx $tx, int $inputIndex): void;
    public function toArray(): array;
}

interface SelectionStrategy {
    public function select(UnspentSet $unspent, int $amount): array;
}
```

### Adapters (Infrastructure)
**Location:** `src/Persistence/`, `src/Lock/`, `src/Selection/`

**Persistence Adapters:**
```
src/Persistence/
├── Sqlite/
│   ├── SqliteLedgerRepository.php
│   ├── SqliteHistoryRepository.php
│   ├── SqliteRepositoryFactory.php
│   └── SqliteSchema.php
└── InMemoryHistoryRepository.php
```

**Lock Adapters:**
```
src/Lock/
├── Owner.php       # Server-side auth
├── PublicKey.php   # Ed25519 signatures
├── NoLock.php      # Open outputs
└── LockFactory.php # Registry for custom locks
```

**Selection Adapters:**
```
src/Selection/
├── FifoStrategy.php
├── LargestFirstStrategy.php
├── SmallestFirstStrategy.php
└── ExactMatchStrategy.php
```

## Dependency Direction

```
Infrastructure (Adapters)
        │
        ▼
Application (Ports/Interfaces)
        │
        ▼
    Domain (Core)
```

**Valid imports:**
```php
// Adapter imports interface ✓
use Chemaclass\Unspent\LedgerRepository;
class SqliteLedgerRepository implements LedgerRepository { }

// Domain imports nothing from infrastructure ✓
class Ledger { /* no Sqlite imports */ }
```

**Invalid imports:**
```php
// Domain importing infrastructure ✗
use Chemaclass\Unspent\Persistence\Sqlite\SqliteLedgerRepository;
class Ledger { /* WRONG */ }
```

## Adding New Components

### New Lock Type

1. **Create implementation** in `src/Lock/`:
```php
final readonly class TimeLock implements OutputLock
{
    public function __construct(
        private int $unlockTimestamp,
    ) {}

    public function validate(Tx $tx, int $inputIndex): void
    {
        if (time() < $this->unlockTimestamp) {
            throw new AuthorizationException('Time lock not expired');
        }
    }

    public function toArray(): array
    {
        return ['type' => 'timelock', 'unlockAt' => $this->unlockTimestamp];
    }
}
```

2. **Register in LockFactory** (if needed for deserialization)
3. **Domain unchanged** - uses `OutputLock` interface

### New Selection Strategy

1. **Create implementation** in `src/Selection/`:
```php
final readonly class RandomStrategy implements SelectionStrategy
{
    public function select(UnspentSet $unspent, int $amount): array
    {
        // Implementation
    }
}
```

2. **Domain unchanged** - uses `SelectionStrategy` interface

### New Persistence Adapter

1. **Create directory** `src/Persistence/NewAdapter/`
2. **Implement interfaces:**
```php
final class NewAdapterLedgerRepository implements LedgerRepository { }
final class NewAdapterHistoryRepository implements HistoryRepository { }
```
3. **Domain unchanged** - uses repository interfaces

## Testing Layers

| Layer | Test Type | Dependencies |
|-------|-----------|--------------|
| Domain | Unit | None (pure PHP) |
| Ports | Unit | Mock implementations |
| Adapters | Integration | Real infrastructure |
| Full | Feature | Everything |

```php
// Domain test - no mocks needed
public function test_ledger_transfer(): void
{
    $ledger = Ledger::withGenesis(Output::ownedBy('alice', 1000));
    $ledger->transfer('alice', 'bob', 300);
    self::assertSame(700, $ledger->totalUnspentByOwner('alice'));
}

// Adapter test - uses real SQLite
public function test_sqlite_repository_saves_and_finds(): void
{
    $repo = new SqliteLedgerRepository($this->pdo);
    $ledger = Ledger::inMemory();
    $repo->save('test', $ledger);
    self::assertNotNull($repo->find('test'));
}
```

## Architecture Checklist

- [ ] Domain has no external imports
- [ ] All external interactions via interfaces
- [ ] Adapters implement interfaces correctly
- [ ] Dependencies flow inward only
- [ ] Domain can be tested without infrastructure
- [ ] New features don't modify domain for infrastructure needs
