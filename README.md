# Unspent

A PHP library for UTXO-like bookkeeping using unspent entries.

## Requirements

- PHP 8.4+

## Installation

```bash
composer require chemaclass/unspent
```

## Usage

```php
use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\Spend;
use Chemaclass\Unspent\SpendId;

// Create a ledger with genesis outputs
$ledger = Ledger::empty()
    ->addGenesis(
        new Output(new OutputId('genesis-1'), 1000),
        new Output(new OutputId('genesis-2'), 500),
    );

echo $ledger->totalUnspentAmount(); // 1500

// Apply a spend: consume genesis-1 and create two new outputs
$ledger = $ledger->apply(new Spend(
    id: new SpendId('tx-001'),
    inputs: [new OutputId('genesis-1')],
    outputs: [
        new Output(new OutputId('alice'), 600),
        new Output(new OutputId('bob'), 400),
    ],
));

echo $ledger->totalUnspentAmount(); // 1500 (conservation)

// Check unspent outputs
$unspent = $ledger->unspent();
$unspent->contains(new OutputId('genesis-1')); // false (spent)
$unspent->contains(new OutputId('genesis-2')); // true
$unspent->contains(new OutputId('alice'));     // true
$unspent->contains(new OutputId('bob'));       // true

// Get a specific output
$aliceOutput = $unspent->get(new OutputId('alice'));
echo $aliceOutput->amount; // 600

// Check if a spend has been applied
$ledger->hasSpendBeenApplied(new SpendId('tx-001')); // true
$ledger->hasSpendBeenApplied(new SpendId('tx-999')); // false

// Iterate over unspent outputs
foreach ($unspent as $id => $output) {
    echo "{$id}: {$output->amount}\n";
}
```

## Invariants

The library enforces the following invariants:

1. **Single spend**: An output can only be spent once. Once spent, it is removed from the unspent set.

2. **Valid inputs**: A spend must reference only outputs that exist in the current unspent set.

3. **Conservation**: Total input amount must equal total output amount (no creation or destruction of value).

4. **Unique output IDs**: All output IDs must be unique across the entire ledger.

5. **Idempotent spends**: The same spend (by ID) cannot be applied twice.

6. **Non-empty IDs**: Output and Spend IDs cannot be empty or whitespace-only strings.

7. **Valid spend structure**: A spend must have at least one input and one output, with no duplicate input or output IDs within the same spend.

## Exceptions

All domain exceptions extend `UnspentException`, allowing you to catch all library errors with a single type:

```php
use Chemaclass\Unspent\Exception\UnspentException;

try {
    $ledger->apply($spend);
} catch (UnspentException $e) {
    // Handle any domain error
}
```

Specific exception types:

- `OutputAlreadySpentException`: When trying to spend an output not in the unspent set
- `UnbalancedSpendException`: When input and output amounts don't match
- `DuplicateOutputIdException`: When creating outputs with duplicate IDs
- `DuplicateSpendException`: When applying the same spend twice
- `GenesisNotAllowedException`: When adding genesis outputs to a non-empty ledger
- `InvalidArgumentException`: When IDs are empty or spend structure is invalid

## API

### Interfaces

- `Id`: Common interface for all identifier value objects (extends `Stringable`)

### Value Objects

- `OutputId`: Identifies an output (implements `Id`)
- `SpendId`: Identifies a spend/transaction (implements `Id`)
- `Output`: A discrete piece of value with an ID and positive integer amount

### Collections

- `UnspentSet`: Immutable collection of unspent outputs keyed by OutputId

### Core

- `Spend`: Represents a transaction that consumes inputs and produces outputs
- `Ledger`: The main entry point; tracks unspent outputs and enforces invariants

### Ledger Methods

```php
Ledger::empty(): Ledger                           // Create an empty ledger
$ledger->addGenesis(Output ...$outputs): Ledger   // Add initial outputs (only when empty)
$ledger->apply(Spend $spend): Ledger              // Apply a spend, returns new ledger
$ledger->unspent(): UnspentSet                    // Get current unspent outputs
$ledger->totalUnspentAmount(): int                // Sum of all unspent amounts (O(1))
$ledger->hasSpendBeenApplied(SpendId $id): bool   // Check if spend was applied
```

### UnspentSet Methods

```php
UnspentSet::empty(): UnspentSet                   // Create empty set
UnspentSet::fromOutputs(Output ...$o): UnspentSet // Create from outputs
$set->add(Output $output): UnspentSet             // Add single output
$set->addAll(Output ...$outputs): UnspentSet      // Add multiple outputs
$set->remove(OutputId $id): UnspentSet            // Remove single output
$set->removeAll(OutputId ...$ids): UnspentSet     // Remove multiple outputs
$set->contains(OutputId $id): bool                // Check if contains ID
$set->get(OutputId $id): ?Output                  // Get output by ID
$set->totalAmount(): int                          // Sum of amounts (O(1) cached)
$set->count(): int                                // Number of outputs
$set->isEmpty(): bool                             // Check if empty
$set->outputIds(): array                          // Get all output IDs
```

## Architecture

The library follows these design principles:

- **Immutability**: All operations return new instances rather than mutating state
- **Value Objects**: IDs and outputs are immutable value objects with validation
- **Interface Segregation**: `Id` interface enables generic handling of identifiers
- **Exception Hierarchy**: All domain exceptions extend `UnspentException` for unified error handling
- **Performance**: O(1) cached totals, O(n+m) conflict checking, batch operations

### File Structure

```
src/
├── Exception/
│   ├── UnspentException.php          # Base exception class
│   ├── DuplicateOutputIdException.php
│   ├── DuplicateSpendException.php
│   ├── GenesisNotAllowedException.php
│   ├── OutputAlreadySpentException.php
│   └── UnbalancedSpendException.php
├── Id.php                            # Interface for identifiers
├── Ledger.php                        # Main entry point
├── Output.php                        # Output value object
├── OutputId.php                      # Output identifier
├── Spend.php                         # Spend/transaction
├── SpendId.php                       # Spend identifier
└── UnspentSet.php                    # Collection of outputs
```

## Development

```bash
# Install dependencies
composer install

# Run tests
vendor/bin/phpunit

# Run the demo
php example/demo.php
```

## License

MIT
