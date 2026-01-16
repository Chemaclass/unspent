# ADR-002: Immutability Design

## Status

Accepted

## Context

State management is a critical aspect of any ledger system. We needed to decide how state changes would be handled:

1. Mutable objects that change in place
2. Immutable objects that return new instances on modification

The choice affects correctness, testability, and concurrency safety.

## Decision

All core classes are **immutable** using PHP 8.4's `readonly` classes:

```php
final readonly class Ledger { ... }
final readonly class Output { ... }
final readonly class Tx { ... }
final readonly class UnspentSet { ... }
```

Every state-changing operation returns a new instance:

```php
$ledger2 = $ledger1->apply($tx);  // $ledger1 unchanged
$ledger3 = $ledger2->credit('alice', 100);  // $ledger2 unchanged
```

## Consequences

### Positive

- **No defensive copying**: Immutable objects can be safely shared without risk of mutation.
- **Time-travel debugging**: Keep references to previous states for comparison or rollback.
- **Thread-safe by design**: No locks needed for concurrent reads.
- **Predictable behavior**: A ledger at any point in time represents a consistent snapshot.
- **Easy testing**: Create a ledger, apply operations, assert on results - no setup/teardown complexity.

### Negative

- **Memory allocation**: Each operation creates new objects. For high-frequency operations, this can increase GC pressure.
- **API learning curve**: Users must capture return values (`$ledger = $ledger->apply(...)` vs. `$ledger->apply(...)`).

### Mitigations

- **Copy-on-write optimization**: `UnspentSet` uses copy-on-fork semantics. Operations on unrelated branches don't duplicate data unnecessarily.
- **Store-backed mode**: For high-volume applications, use `Ledger::withRepository()` to keep only unspent outputs in memory.

## Alternatives Considered

### Mutable Ledger

Allow in-place modification: `$ledger->apply($tx)`.

**Rejected because**:
- Surprising behavior when multiple references exist
- Complex to implement undo/rollback
- Thread safety requires explicit locking

### Command/Event Sourcing Only

Store commands/events and rebuild state on demand.

**Rejected because**:
- Rebuilding state is expensive
- The UTXO model naturally provides event history via transaction references

## Code Examples

### Branching Ledgers

```php
$base = Ledger::inMemory()->credit('alice', 1000);

// Fork for "what if" analysis
$scenario1 = $base->transfer('alice', 'bob', 500);
$scenario2 = $base->transfer('alice', 'charlie', 300);

// $base, $scenario1, and $scenario2 all exist independently
```

### Safe State Sharing

```php
function processPayment(Ledger $ledger, Payment $payment): Ledger
{
    // $ledger is immutable - caller's reference unchanged
    return $ledger->transfer($payment->from, $payment->to, $payment->amount);
}
```

## References

- [PHP 8.1 Readonly Properties](https://www.php.net/releases/8.1/en.php#readonly_properties)
- [PHP 8.2 Readonly Classes](https://www.php.net/releases/8.2/en.php#readonly_classes)
