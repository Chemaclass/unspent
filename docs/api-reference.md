# API Reference

Complete reference for all public classes and methods.

## Output

A chunk of value with ownership.

### Constants

```php
Output::MAX_AMOUNT  // PHP_INT_MAX (~9.2e18 on 64-bit systems)
```

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

## Tx

A transaction that consumes outputs and creates new ones.

### Factory Method

```php
Tx::create(
    array $inputIds,       // list<string> - IDs of outputs to consume
    array $outputs,        // list<Output> - New outputs to create
    ?string $signedBy = null,  // Authorization identity
    ?string $id = null,    // Transaction ID (auto-generated if null)
    array $proofs = [],    // list<string> - Signatures for PublicKey locks
): Tx
```

### Properties

```php
$tx->id;        // TxId
$tx->inputs;    // list<OutputId>
$tx->outputs;   // list<Output>
$tx->signedBy;  // ?string
$tx->proofs;    // list<string>
```

### Methods

```php
$tx->totalOutputAmount(): int  // Sum of output amounts
```

## CoinbaseTx

A minting transaction that creates new value.

### Factory Method

```php
CoinbaseTx::create(
    array $outputs,      // list<Output>
    ?string $id = null   // Transaction ID (auto-generated if null)
): CoinbaseTx
```

### Properties

```php
$coinbase->id;       // TxId
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
Ledger::withGenesis(Output ...$outputs): Ledger  // Recommended
```

### Genesis

```php
// Add initial outputs (only on empty ledger)
$ledger->addGenesis(Output ...$outputs): Ledger
```

### Transactions

```php
// Apply a transaction (consumes inputs, creates outputs)
$ledger->apply(Tx $tx): Ledger

// Apply a coinbase (creates new value)
$ledger->applyCoinbase(CoinbaseTx $coinbase): Ledger
```

### Query - Unspent

```php
$ledger->unspent(): UnspentSet           // Access unspent outputs
$ledger->totalUnspentAmount(): int       // Sum of all unspent
```

### Query - Transactions

```php
$ledger->isTxApplied(TxId $id): bool
```

### Query - Fees

```php
$ledger->feeForTx(TxId $id): ?int        // Fee for specific tx
$ledger->totalFeesCollected(): int       // Sum of all fees
$ledger->allTxFees(): array              // ['id' => fee, ...]
```

### Query - Coinbase

```php
$ledger->isCoinbase(TxId $id): bool
$ledger->coinbaseAmount(TxId $id): ?int
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
new OutputId(string $value)

$id->value;     // string
(string) $id;   // Stringable
$id->equals(OutputId $other): bool
```

**Validation:**
- Cannot be empty or whitespace-only
- Maximum 64 characters
- Only alphanumeric characters, dashes (`-`), and underscores (`_`) allowed

### TxId

```php
new TxId(string $value)

$id->value;     // string
(string) $id;   // Stringable
$id->equals(TxId $other): bool
```

**Validation:**
- Cannot be empty or whitespace-only
- Maximum 64 characters
- Only alphanumeric characters, dashes (`-`), and underscores (`_`) allowed

## Lock Classes

### Owner

Simple string-based ownership.

```php
new Owner(string $name)

$lock->name;  // string
$lock->validate(Tx $tx, int $inputIndex): void
$lock->toArray(): array  // ['type' => 'owner', 'name' => '...']
```

**Validation:** Name cannot be empty or whitespace-only.

### PublicKey

Ed25519 signature verification.

```php
new PublicKey(string $key)  // Base64-encoded Ed25519 public key (32 bytes)

$lock->key;  // string (base64)
$lock->validate(Tx $tx, int $inputIndex): void
$lock->toArray(): array  // ['type' => 'pubkey', 'key' => '...']
```

**Validation:**
- Key must be valid base64
- Decoded key must be exactly 32 bytes (Ed25519 public key size)
- Signatures verified must be exactly 64 bytes

### NoLock

No verification (anyone can spend).

```php
new NoLock()

$lock->validate(Tx $tx, int $inputIndex): void  // Always passes
$lock->toArray(): array  // ['type' => 'none']
```

### OutputLock Interface

```php
interface OutputLock
{
    public function validate(Tx $tx, int $inputIndex): void;
    public function toArray(): array;
}
```

## Exceptions

All extend `UnspentException` which extends `RuntimeException`.

```php
OutputAlreadySpentException::class   // Input not in unspent set
InsufficientInputsException::class   // Outputs exceed inputs
DuplicateOutputIdException::class    // Output ID already exists
DuplicateTxException::class          // Tx ID already used
GenesisNotAllowedException::class    // Genesis on non-empty ledger
AuthorizationException::class        // Lock validation failed
```

### Catching All Domain Errors

```php
try {
    $ledger = $ledger->apply($tx);
} catch (UnspentException $e) {
    // Handle any domain error
}
```
