# ADR-004: Copy-on-Fork Optimization

## Status

Accepted

## Context

The immutable design (ADR-002) means every operation creates new instances. For `UnspentSet`, this could mean copying thousands of outputs on every transaction.

We needed an optimization strategy that:

1. Maintains immutability semantics
2. Minimizes memory allocation
3. Supports efficient branching (multiple forks from same base)

## Decision

We implemented **copy-on-fork** semantics in `UnspentSet`:

```php
final readonly class UnspentSet
{
    private function __construct(
        private array $outputs,        // Current unspent outputs
        private ?self $parent = null,  // Optional parent for shared state
    ) {}
}
```

When an operation occurs:
- Small changes: Create new instance referencing parent
- Large changes or deep nesting: Flatten to new independent instance

This is similar to copy-on-write but optimized for the branching use case.

## Consequences

### Positive

- **Memory efficient branching**: Creating multiple "what-if" scenarios shares base state.
- **Fast operations**: Adding/removing outputs doesn't copy the entire set.
- **Transparent immutability**: Users get immutable semantics without performance penalty.

### Negative

- **Implementation complexity**: The parent chain must be managed carefully.
- **Read overhead**: Lookups may traverse parent chain (mitigated by flattening).

### Mitigations

- Automatic flattening when parent chain exceeds threshold.
- `release()` method for explicit flattening when needed.
- Comprehensive test coverage for edge cases.

## How It Works

### Sequential Operations

```php
$set1 = UnspentSet::fromOutputs($a, $b, $c);  // Independent
$set2 = $set1->add($d);                        // References $set1
$set3 = $set2->remove($a->id);                 // References $set2

// Memory: Only deltas stored, base outputs shared
```

### Branching Scenarios

```php
$base = UnspentSet::fromOutputs($a, $b, $c);

$branch1 = $base->add($d);   // Both branches
$branch2 = $base->add($e);   // share $base

// $branch1 and $branch2 both reference same $base
```

### Flattening

```php
$set = $ledger->unspent();           // May have parent chain
$flat = $set->release();             // Independent, no parents

// Or automatic via threshold
// After N parent levels, new instance is flattened
```

## Performance Characteristics

| Operation | Complexity | Notes |
|-----------|------------|-------|
| add() | O(1) | Creates new node |
| remove() | O(1) | Creates new node |
| get() | O(k) | k = parent depth, amortized O(1) |
| count() | O(1) | Cached |
| iterate | O(n) | n = total outputs |

## Alternatives Considered

### Full Copy on Every Operation

Always copy the entire output set.

**Rejected because**:
- O(n) memory per operation
- Prohibitive for large output sets

### Persistent Data Structures

Use HAMTs or similar functional data structures.

**Rejected because**:
- No standard PHP implementation
- Additional dependency
- May be over-engineered for typical use cases

### Mutable Internal State

Use mutable arrays internally, copy only on external access.

**Rejected because**:
- Complicates invariant maintenance
- Still requires full copy on branching

## Benchmarks

Typical performance characteristics (varies by hardware):

```
Operation on 10,000 outputs:
- add():     ~0.001ms (parent reference)
- remove():  ~0.001ms (parent reference)
- release(): ~1.0ms   (flatten to independent)
- iterate:   ~0.5ms   (traverse all)
```

## References

- [Persistent Data Structures](https://en.wikipedia.org/wiki/Persistent_data_structure)
- [Copy-on-write](https://en.wikipedia.org/wiki/Copy-on-write)
- [docs/scalability.md](../scalability.md) - Scalability documentation
