# ADR-002: Mutable Ledger Design

## Status

Accepted

## Context

State management is a critical aspect of any ledger system. We needed to decide how state changes would be handled:

1. Mutable objects that change in place
2. Immutable objects that return new instances on modification

The choice affects API ergonomics, testability, and memory usage.

## Decision

The `Ledger` class is **mutable**. Operations modify the ledger in place and return `$this` for fluent chaining:

```php
$ledger = Ledger::inMemory();
$ledger->credit('alice', 1000);
$ledger->transfer('alice', 'bob', 300);

// Or chain them together
$ledger = Ledger::inMemory()
    ->credit('alice', 1000)
    ->transfer('alice', 'bob', 300);
```

Value objects (`Output`, `Tx`, `TxId`, `OutputId`) remain immutable as they represent data, not state.

## Consequences

### Positive

- **Simpler API**: No need to capture return values (`$ledger->apply(...)` instead of `$ledger = $ledger->apply(...)`).
- **Fluent chaining**: Method chaining still works via `return $this`.
- **Reduced memory allocation**: No new Ledger instances created on each operation.
- **Familiar pattern**: Matches common PHP collection/builder patterns.

### Negative

- **No automatic snapshots**: Cannot keep references to previous states without explicit cloning.
- **Shared reference concerns**: Passing a ledger to a function allows it to modify the original.

### Mitigations

- **Transaction history**: The UTXO model naturally provides complete history via `outputCreatedBy()`, `outputSpentBy()`, etc.
- **Explicit cloning**: If snapshots are needed, clone the ledger before operations.
- **Store-backed mode**: For high-volume applications, use `Ledger::withRepository()` to persist state.

## History: Original Immutable Design

The original design (pre-revision) used immutable Ledgers:

```php
// Old pattern (no longer required)
$ledger2 = $ledger1->apply($tx);  // $ledger1 unchanged
$ledger3 = $ledger2->credit('alice', 100);  // $ledger2 unchanged
```

This was changed because:
- The `$ledger = $ledger->...` pattern was verbose and error-prone (forgetting to capture the return value)
- The library's transaction history already provides audit trail without needing ledger snapshots
- Most use cases don't need multiple ledger versions

## Code Examples

### Simple Usage

```php
$ledger = Ledger::inMemory();
$ledger->credit('alice', 1000);
$ledger->transfer('alice', 'bob', 300);

echo $ledger->totalUnspentByOwner('alice'); // 700
echo $ledger->totalUnspentByOwner('bob');   // 300
```

### Fluent Chaining

```php
$ledger = Ledger::inMemory()
    ->credit('alice', 1000)
    ->credit('bob', 500)
    ->transfer('alice', 'bob', 200);
```

### Function Parameters

```php
function processPayment(LedgerInterface $ledger, Payment $payment): void
{
    // Note: this modifies the ledger in place
    $ledger->transfer($payment->from, $payment->to, $payment->amount);
}
```

## References

- [PHP 8.4 Readonly Classes](https://www.php.net/releases/8.4/en.php) - Still used for value objects
