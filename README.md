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

## Exceptions

All invariant violations throw specific domain exceptions:

- `OutputAlreadySpentException`: When trying to spend an output not in the unspent set
- `UnbalancedSpendException`: When input and output amounts don't match
- `DuplicateOutputIdException`: When creating outputs with duplicate IDs
- `DuplicateSpendException`: When applying the same spend twice
- `GenesisNotAllowedException`: When adding genesis outputs to a non-empty ledger

## API

### Value Objects

- `OutputId`: Identifies an output (string wrapper)
- `SpendId`: Identifies a spend/transaction (string wrapper)
- `Output`: A discrete piece of value with an ID and positive integer amount

### Collections

- `UnspentSet`: Immutable collection of unspent outputs keyed by OutputId

### Core

- `Spend`: Represents a transaction that consumes inputs and produces outputs
- `Ledger`: The main entry point; tracks unspent outputs and enforces invariants

### Ledger Methods

```php
Ledger::empty(): Ledger                      // Create an empty ledger
$ledger->addGenesis(Output ...$outputs): Ledger  // Add initial outputs (only when empty)
$ledger->apply(Spend $spend): Ledger         // Apply a spend, returns new ledger
$ledger->unspent(): UnspentSet               // Get current unspent outputs
$ledger->totalUnspentAmount(): int           // Sum of all unspent amounts
```

## Development

```bash
# Install dependencies
composer install

# Run tests
vendor/bin/phpunit
```

## License

MIT
