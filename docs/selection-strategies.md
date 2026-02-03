# Selection Strategies

When transferring or debiting value, the ledger must choose which outputs to spend. Selection strategies control this choice, optimizing for different goals.

## Built-in Strategies

### FifoStrategy (Default)

Spends outputs in the order they were created - oldest first.

```php
use Chemaclass\Unspent\Selection\FifoStrategy;

$ledger = Ledger::inMemory(strategy: new FifoStrategy());
```

**Best for:**
- Fairness - older outputs are spent first
- Predictable behavior
- General use cases

### LargestFirstStrategy

Spends the largest outputs first to minimize the number of inputs.

```php
use Chemaclass\Unspent\Selection\LargestFirstStrategy;

$ledger = Ledger::inMemory(strategy: new LargestFirstStrategy());
```

**Best for:**
- Minimizing transaction complexity
- Reducing fragmentation
- When you have many small outputs but want to keep them

### SmallestFirstStrategy

Spends the smallest outputs first to consolidate "dust" (tiny outputs).

```php
use Chemaclass\Unspent\Selection\SmallestFirstStrategy;

$ledger = Ledger::inMemory(strategy: new SmallestFirstStrategy());
```

**Best for:**
- Cleaning up many small outputs
- Consolidating dust into larger outputs
- Reducing total output count over time

### ExactMatchStrategy

Attempts to find outputs that exactly match the target amount, eliminating change.

```php
use Chemaclass\Unspent\Selection\ExactMatchStrategy;

$ledger = Ledger::inMemory(strategy: new ExactMatchStrategy());
```

**Best for:**
- Avoiding change outputs entirely
- Keeping output count minimal
- When exact amounts matter

**Notes:**
- Uses branch-and-bound algorithm (limited to 10,000 iterations)
- Considers up to 100 outputs for performance
- Falls back to `LargestFirstStrategy` if no exact match found

## Comparison

| Strategy | Priority | Inputs | Change | Use Case |
|----------|----------|--------|--------|----------|
| FIFO | Oldest first | Variable | Likely | General purpose |
| Largest First | Fewest inputs | Minimal | Likely | Reduce complexity |
| Smallest First | Most inputs | Maximal | Likely | Consolidate dust |
| Exact Match | No change | Variable | None* | Precise amounts |

*Falls back to Largest First if no exact match found.

## Example: Strategy Impact

```php
// Setup: 5 outputs of varying sizes
$ledger = Ledger::withGenesis(
    Output::ownedBy('alice', 10, 'out-1'),   // oldest
    Output::ownedBy('alice', 50, 'out-2'),
    Output::ownedBy('alice', 25, 'out-3'),
    Output::ownedBy('alice', 100, 'out-4'),
    Output::ownedBy('alice', 15, 'out-5'),   // newest
);

// Transfer 60 to bob
$ledger->transfer('alice', 'bob', 60);
```

**Which outputs are spent?**

| Strategy | Outputs Spent | Total | Change |
|----------|--------------|-------|--------|
| FIFO | out-1 (10), out-2 (50), out-3 (25) | 85 | 25 |
| Largest First | out-4 (100) | 100 | 40 |
| Smallest First | out-1 (10), out-5 (15), out-3 (25), out-2 (50) | 100 | 40 |
| Exact Match | out-2 (50), out-1 (10) | 60 | 0 |

## Custom Strategies

Implement `SelectionStrategy` to create your own:

```php
use Chemaclass\Unspent\Selection\SelectionStrategy;
use Chemaclass\Unspent\UnspentSet;
use Chemaclass\Unspent\Output;

final readonly class RandomStrategy implements SelectionStrategy
{
    public function select(UnspentSet $available, int $target): array
    {
        $outputs = iterator_to_array($available);
        shuffle($outputs);

        $selected = [];
        $sum = 0;

        foreach ($outputs as $output) {
            $selected[] = $output;
            $sum += $output->amount;

            if ($sum >= $target) {
                break;
            }
        }

        return $selected;
    }

    public function name(): string
    {
        return 'random';
    }
}

// Use it
$ledger = Ledger::inMemory(strategy: new RandomStrategy());
```

## Using with Coin Control

Selection strategies only apply to the Simple API (`transfer()`, `debit()`). When using the Coin Control API (`apply()` with `Tx::create()`), you explicitly specify which outputs to spend:

```php
// Simple API - strategy chooses outputs
$ledger->transfer('alice', 'bob', 100); // Uses configured strategy

// Coin Control API - you choose outputs
$ledger->apply(Tx::create(
    spendIds: ['specific-output-id'],  // Explicit selection
    outputs: [Output::ownedBy('bob', 100)],
    signedBy: 'alice',
));
```

## Next Steps

- [Core Concepts](concepts.md) - Understand outputs and transactions
- [API Reference](api-reference.md) - Complete method reference
