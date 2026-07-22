# ADR-004: Copy-on-Fork Optimization

## Status

Accepted (revised to match the implementation)

## Context

The `UnspentSet` class manages the set of unspent outputs and can hold thousands
of entries. The `Ledger` is mutable (see ADR-002) and applies many transactions
in sequence, each removing spent outputs and adding new ones. Copying the whole
output array on every mutation would make a run of N transactions O(N²).

We needed a strategy that:

1. Mutates in place during normal ledger operation (no per-op copy).
2. Never lets an externally exposed view corrupt the ledger's internal state.
3. Answers owner-scoped queries without scanning the whole set.

## Decision

`UnspentSet` tracks a single boolean, `$owned`, that records whether this
instance is free to mutate its own backing array:

```php
final class UnspentSet
{
    private bool $owned = true;

    private function __construct(
        private array $outputs,     // id => Output
        private int $cachedTotal,   // running sum, kept O(1)
        private array $ownerIndex,  // owner name => set of owned output ids
    ) {}
}
```

- **Owned (`$owned === true`)** — `add`/`addAll`/`remove`/`removeAll` mutate
  `$outputs` in place and return `$this`. O(1) per element, no copy.
- **Shared (`$owned === false`)** — the same operations **fork**: they copy the
  array, apply the change to the copy, and return a new `UnspentSet`. The
  original is left untouched.

A set becomes shared through `release()`. External reads go through
`snapshot()`, which returns a *released copy* that shares the array copy-on-write
while leaving the internal set owned — so `Ledger::unspent()` never degrades the
ledger's own subsequent writes (see the interleaved read/write case below).

A secondary **owner index** (`owner name => set of owned output ids`) is
maintained incrementally alongside every mutation, so owner-scoped lookups cost
O(outputs-owned-by-owner) instead of O(total outputs).

## Consequences

### Positive

- **Linear ledger runs**: applying N transactions is O(N) because the internal
  set stays owned and mutates in place.
- **Safe exposure**: `snapshot()` hands out an isolated view; a caller mutating
  it forks and cannot touch the ledger.
- **Cheap owner queries**: `ownedBy()` / `totalAmountOwnedBy()` resolve through
  the owner index rather than filtering the whole set.

### Negative

- **Two states to reason about**: contributors must keep the owned and forked
  branches of each mutator in sync.
- **Index bookkeeping**: every mutator must keep `ownerIndex` consistent (covered
  by tests, including fork and owner-replacement cases).

## How It Works

### Sequential operations (owned, in place)

```php
$set = UnspentSet::fromOutputs($a, $b, $c); // owned
$set->add($d);                              // mutates in place, returns $set
$set->remove($a->id);                       // mutates in place, returns $set
```

### Exposure and copy-on-fork

```php
$snapshot = $ledger->unspent();  // snapshot(): released copy, shares array (COW)
$snapshot->add($x);              // snapshot is shared → forks, ledger untouched
$ledger->credit('alice', 10);    // internal set still owned → mutates in place
```

Because `snapshot()` leaves the internal set owned, an interleaved
read-then-write loop stays O(1) per write instead of forking on every write.

## Performance Characteristics

| Operation | Complexity | Notes |
|-----------|------------|-------|
| `add()` / `remove()` | O(1) | In place when owned |
| `addAll()` / `removeAll()` | O(k) | k = number of ids |
| `get()` / `contains()` | O(1) | Hash lookup |
| `count()` / `totalAmount()` | O(1) | `totalAmount` uses `cachedTotal` |
| `ownedBy()` / `totalAmountOwnedBy()` | O(owned) | Via the owner index |
| `snapshot()` | O(1) | Shares array copy-on-write |
| First write after `release()` | O(n) | One fork, then owned again |
| iterate / `toArray()` | O(n) | n = total outputs |

## Alternatives Considered

### Full copy on every operation

Always copy the entire output set.

**Rejected**: O(n) memory per operation, O(N²) for a run of N transactions.

### Persistent data structures (HAMTs)

**Rejected**: no standard PHP implementation, extra dependency, and heavier than
needed for the mutable-ledger use case.

### Parent-chain sharing

An earlier draft of this ADR proposed a `?self $parent` chain with automatic
flattening. It was not implemented: the `$owned` flag delivers the same
"don't copy unless shared" benefit with O(1) reads and far less complexity.

## References

- [Copy-on-write](https://en.wikipedia.org/wiki/Copy-on-write)
- [ADR-002: Mutable Design](002-mutable-design.md)
- [docs/scalability.md](../scalability.md)
