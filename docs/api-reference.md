# API Reference

Complete reference for all public classes and methods.

## Output

A chunk of value with ownership.

### Factory Methods

```php
// Named owner (server-side authentication)
Output::ownedBy(
    string $owner,
    int $amount,
    ?string $id = null
): Output

// Ed25519 public key (cryptographic proof)
Output::signedBy(
    string $publicKey,    // Base64-encoded Ed25519 public key
    int $amount,
    ?string $id = null
): Output

// No lock (anyone can spend)
Output::open(
    int $amount,
    ?string $id = null
): Output

// Custom lock implementation
Output::lockedWith(
    OutputLock $lock,
    int $amount,
    ?string $id = null
): Output
```

### Properties

```php
$output->id;      // OutputId
$output->amount;  // int (positive)
$output->lock;    // OutputLock
```

## Spend

A transaction that consumes outputs and creates new ones.

### Factory Method

```php
Spend::create(
    array $inputIds,       // list<string> - IDs of outputs to consume
    array $outputs,        // list<Output> - New outputs to create
    ?string $signedBy = null,  // Authorization identity
    ?string $id = null,    // Transaction ID (auto-generated if null)
    array $proofs = [],    // list<string> - Signatures for PublicKey locks
): Spend
```

### Properties

```php
$spend->id;        // SpendId
$spend->inputs;    // list<OutputId>
$spend->outputs;   // list<Output>
$spend->signedBy;  // ?string
$spend->proofs;    // list<string>
```

### Methods

```php
$spend->totalOutputAmount(): int  // Sum of output amounts
```

## Coinbase

A minting transaction that creates new value.

### Factory Method

```php
Coinbase::create(
    array $outputs,      // list<Output>
    ?string $id = null   // Transaction ID (auto-generated if null)
): Coinbase
```

### Properties

```php
$coinbase->id;       // SpendId
$coinbase->outputs;  // list<Output>
```

### Methods

```php
$coinbase->totalOutputAmount(): int  // Sum of output amounts
```

## Ledger

Immutable state container.

### Creation

```php
Ledger::empty(): Ledger
```

### Genesis

```php
// Add initial outputs (only on empty ledger)
$ledger->addGenesis(Output ...$outputs): Ledger
```

### Transactions

```php
// Apply a spend (consumes inputs, creates outputs)
$ledger->apply(Spend $spend): Ledger

// Apply a coinbase (creates new value)
$ledger->applyCoinbase(Coinbase $coinbase): Ledger
```

### Query - Unspent

```php
$ledger->unspent(): UnspentSet           // Access unspent outputs
$ledger->totalUnspentAmount(): int       // Sum of all unspent
```

### Query - Spends

```php
$ledger->hasSpendBeenApplied(SpendId $id): bool
```

### Query - Fees

```php
$ledger->feeForSpend(SpendId $id): ?int  // Fee for specific spend
$ledger->totalFeesCollected(): int       // Sum of all fees
$ledger->allSpendFees(): array           // ['id' => fee, ...]
```

### Query - Coinbase

```php
$ledger->isCoinbase(SpendId $id): bool
$ledger->coinbaseAmount(SpendId $id): ?int
$ledger->totalMinted(): int              // Sum of all coinbases
```

### Query - History & Provenance

```php
// Which transaction created this output? ('genesis' for genesis outputs)
$ledger->outputCreatedBy(OutputId $id): ?string

// Which transaction spent this output? (null if unspent or unknown)
$ledger->outputSpentBy(OutputId $id): ?string

// Get output data even if spent (returns null if never existed)
$ledger->getOutput(OutputId $id): ?Output

// Check if output ever existed (spent or unspent)
$ledger->outputExists(OutputId $id): bool

// Get complete history of an output
$ledger->outputHistory(OutputId $id): ?array
// Returns: ['id', 'amount', 'lock', 'createdBy' (nullable), 'spentBy', 'status']
```

### Serialization

```php
$ledger->toArray(): array
$ledger->toJson(int $flags = 0): string

Ledger::fromArray(array $data): Ledger
Ledger::fromJson(string $json): Ledger
```

## UnspentSet

Collection of unspent outputs.

### Query

```php
$set->isEmpty(): bool
$set->count(): int
$set->totalAmount(): int

$set->contains(OutputId $id): bool
$set->get(OutputId $id): ?Output
$set->outputIds(): array  // list<OutputId>
```

### Iteration

```php
foreach ($set as $id => $output) {
    // $id is string, $output is Output
}
```

### Modification (returns new instance)

```php
$set->add(Output $output): UnspentSet
$set->addAll(Output ...$outputs): UnspentSet
$set->remove(OutputId $id): UnspentSet
$set->removeAll(OutputId ...$ids): UnspentSet
```

### Creation

```php
UnspentSet::empty(): UnspentSet
UnspentSet::fromOutputs(Output ...$outputs): UnspentSet
```

### Serialization

```php
$set->toArray(): array
UnspentSet::fromArray(array $data): UnspentSet
```

## ID Classes

### OutputId

```php
new OutputId(string $value)  // Non-empty string

$id->value;     // string
(string) $id;   // Stringable
$id->equals(OutputId $other): bool
```

### SpendId

```php
new SpendId(string $value)  // Non-empty string

$id->value;     // string
(string) $id;   // Stringable
$id->equals(SpendId $other): bool
```

## Lock Classes

### Owner

Simple string-based ownership.

```php
new Owner(string $name)

$lock->name;  // string
$lock->validate(Spend $spend, int $inputIndex): void
$lock->toArray(): array  // ['type' => 'owner', 'name' => '...']
```

### PublicKey

Ed25519 signature verification.

```php
new PublicKey(string $key)  // Base64-encoded public key

$lock->key;  // string (base64)
$lock->validate(Spend $spend, int $inputIndex): void
$lock->toArray(): array  // ['type' => 'pubkey', 'key' => '...']
```

### NoLock

No verification (anyone can spend).

```php
new NoLock()

$lock->validate(Spend $spend, int $inputIndex): void  // Always passes
$lock->toArray(): array  // ['type' => 'none']
```

### OutputLock Interface

```php
interface OutputLock
{
    public function validate(Spend $spend, int $inputIndex): void;
    public function toArray(): array;
}
```

## Exceptions

All extend `UnspentException` which extends `RuntimeException`.

```php
OutputAlreadySpentException::class   // Input not in unspent set
InsufficientInputsException::class   // Outputs exceed inputs
DuplicateOutputIdException::class    // Output ID already exists
DuplicateSpendException::class       // Spend ID already used
GenesisNotAllowedException::class    // Genesis on non-empty ledger
AuthorizationException::class        // Lock validation failed
```

### Catching All Domain Errors

```php
try {
    $ledger = $ledger->apply($spend);
} catch (UnspentException $e) {
    // Handle any domain error
}
```
