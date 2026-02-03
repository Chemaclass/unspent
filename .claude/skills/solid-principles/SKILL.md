# SOLID Principles Skill

## Activation Triggers
- Refactoring discussions
- Code review requests
- Design pattern questions
- "How should I structure this?" questions

## SOLID Overview

| Principle | Summary | Key Question |
|-----------|---------|--------------|
| **S**ingle Responsibility | One class, one reason to change | "What is the ONE thing this class does?" |
| **O**pen/Closed | Open for extension, closed for modification | "Can I add behavior without changing existing code?" |
| **L**iskov Substitution | Subtypes must be substitutable | "Can I use any implementation where the interface is expected?" |
| **I**nterface Segregation | Many specific interfaces > one general | "Does every implementer use every method?" |
| **D**ependency Inversion | Depend on abstractions | "Am I depending on interfaces or implementations?" |

---

## Single Responsibility Principle (SRP)

> A class should have only one reason to change.

**In Unspent:**
```php
// GOOD: Each class has one responsibility
Ledger       → Manages UTXO state
Output       → Represents a value chunk
Tx           → Represents a transaction
TxBuilder    → Builds transactions fluently
OutputLock   → Authorizes spending
```

```php
// BAD: Mixed responsibilities
class Ledger {
    public function transfer(...) { ... }
    public function saveToDatabase() { ... }  // Persistence concern!
    public function sendNotification() { ... } // Notification concern!
}
```

---

## Open/Closed Principle (OCP)

> Open for extension, closed for modification.

**In Unspent:**
```php
// GOOD: New lock types without modifying existing code
interface OutputLock {
    public function validate(Tx $tx, int $inputIndex): void;
}

// Add new lock by implementing interface
final readonly class TimeLock implements OutputLock {
    public function validate(Tx $tx, int $inputIndex): void {
        if (time() < $this->unlockTime) {
            throw new AuthorizationException('Time lock not expired');
        }
    }
}
```

```php
// BAD: Modifying existing code for new types
class Ledger {
    public function authorize(Output $output, Tx $tx): void {
        match ($output->lockType()) {
            'owner' => $this->checkOwner($output, $tx),
            'timelock' => $this->checkTimelock($output, $tx), // Must modify!
        };
    }
}
```

---

## Liskov Substitution Principle (LSP)

> Objects of a superclass should be replaceable with objects of its subclasses.

**In Unspent:**
```php
// GOOD: Any OutputLock implementation works anywhere locks are used
function transferWithLock(Ledger $ledger, OutputLock $lock): void {
    // Works with Owner, PublicKey, NoLock, or any custom lock
    $output = Output::withLock($lock, 1000);
    $ledger->apply($tx);
}

// All these are interchangeable:
transferWithLock($ledger, new Owner('alice'));
transferWithLock($ledger, new PublicKey($key));
transferWithLock($ledger, new NoLock());
```

---

## Interface Segregation Principle (ISP)

> No client should be forced to depend on methods it doesn't use.

**In Unspent:**
```php
// GOOD: Separate focused interfaces
interface LedgerRepository {
    public function find(string $ledgerId): ?Ledger;
    public function save(string $ledgerId, Ledger $ledger): void;
}

interface HistoryRepository {
    public function recordTransaction(string $txId, int $fee = 0): void;
    public function recordOutput(OutputId $id, string $createdBy): void;
    public function recordSpend(OutputId $id, string $spentBy): void;
}

interface SelectionStrategy {
    public function select(UnspentSet $unspent, int $amount): array;
}
```

```php
// BAD: Fat interface
interface Repository {
    public function findLedger($id);
    public function saveLedger($id, $ledger);
    public function recordTransaction($txId);
    public function recordOutput($id, $createdBy);
    public function selectOutputs($unspent, $amount);  // Not all repos need this!
}
```

---

## Dependency Inversion Principle (DIP)

> High-level modules should not depend on low-level modules. Both should depend on abstractions.

**In Unspent:**
```php
// GOOD: Domain depends on interface
final class Ledger implements LedgerInterface
{
    public function __construct(
        private ?HistoryRepository $history = null, // Interface
    ) {}
}

// Infrastructure implements interface
final class SqliteHistoryRepository implements HistoryRepository { ... }
final class InMemoryHistoryRepository implements HistoryRepository { ... }
```

```php
// BAD: Domain depends on implementation
final class Ledger
{
    public function __construct(
        private SqliteHistoryRepository $history, // Concrete!
    ) {}
}
```

---

## Quick Reference

### Code Smell → Principle Violated

| Smell | Likely Violation |
|-------|------------------|
| Class does too much | SRP |
| Switch on type | OCP |
| Type checking with `instanceof` | LSP |
| Empty method implementations | ISP |
| `new` for dependencies in domain | DIP |
| Hard to test in isolation | DIP |
| Changes ripple through codebase | SRP, OCP |

### Refactoring Patterns

| Problem | Pattern |
|---------|---------|
| Multiple responsibilities | Extract Class |
| Switch on type | Strategy Pattern (like SelectionStrategy) |
| Fat interface | Interface Segregation |
| Concrete dependencies | Dependency Injection |
| Complex conditionals | Replace with Polymorphism |

## Application in Unspent

| Component | SOLID Application |
|-----------|-------------------|
| `OutputLock` | OCP (new locks) + LSP (interchangeable) + ISP (focused) |
| `SelectionStrategy` | OCP (new strategies) + LSP (interchangeable) |
| `LedgerRepository` | DIP (abstraction) + ISP (separate from History) |
| `Ledger` | SRP (state management only) + DIP (uses interfaces) |
| `Output`, `Tx` | SRP (immutable data) |
