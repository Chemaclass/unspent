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
    array $spendIds,       // list<string> - IDs of outputs to spend
    array $outputs,        // list<Output> - New outputs to create
    ?string $signedBy = null,  // Authorization identity
    ?string $id = null,    // Transaction ID (auto-generated if null)
    array $proofs = [],    // list<string> - Signatures for PublicKey locks
): Tx
```

### Properties

```php
$tx->id;        // TxId
$tx->spends;    // list<OutputId> - IDs of outputs being spent
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

Immutable state container with two modes:

- **In-memory mode** - Simple, all-in-memory (< 100k outputs)
- **Store-backed mode** - Production-ready, bounded memory (100k+ outputs)

### In-memory Mode Creation

```php
Ledger::inMemory(): Ledger                                 // Empty ledger
Ledger::withGenesis(Output ...$outputs): Ledger            // Recommended
Ledger::fromArray(array $data): Ledger                     // From serialized data
Ledger::fromJson(string $json): Ledger                     // From JSON
```

### Store-backed Mode Creation

```php
// Create new ledger with HistoryStore
Ledger::withStore(HistoryStore $store): Ledger
Ledger::withStore($store)->addGenesis(Output ...$outputs): Ledger

// Load from existing UnspentSet (for persistence)
Ledger::fromUnspentSet(
    UnspentSet $unspentSet,
    HistoryStore $store,
    int $totalFees = 0,
    int $totalMinted = 0,
): Ledger
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

> **Note:** `allTxFees()` returns an empty array for store-backed mode since individual fees are stored in the `HistoryStore`, not in memory. Use `feeForTx()` to query specific transaction fees, or query the HistoryStore directly for batch operations.

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

// Get complete history of an output (returns DTO)
$ledger->outputHistory(OutputId $id): ?OutputHistory
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

## Data Transfer Objects (DTOs)

### OutputHistory

Represents the complete history of an output with type-safe access.

```php
final readonly class OutputHistory
{
    public OutputId $id;
    public int $amount;
    public OutputLock $lock;
    public ?string $createdBy;   // 'genesis' or transaction ID
    public ?string $spentBy;     // null if unspent
    public OutputStatus $status;
}

// Factory method
OutputHistory::fromOutput(Output $output, ?string $createdBy, ?string $spentBy): OutputHistory

// Convenience methods
$history->isSpent(): bool
$history->isUnspent(): bool
$history->isGenesis(): bool

// Serialization
$history->toArray(): array
```

### OutputStatus

Enum representing the spending status of an output.

```php
enum OutputStatus: string
{
    case UNSPENT = 'unspent';
    case SPENT = 'spent';
}

// Factory method
OutputStatus::fromSpentBy(?string $spentBy): OutputStatus

// Convenience methods
$status->isSpent(): bool
$status->isUnspent(): bool
```

### TransactionInfo

Represents transaction fee information from repository queries.

```php
final readonly class TransactionInfo
{
    public string $id;
    public int $fee;
}

// Factory method
TransactionInfo::fromRow(array $row): TransactionInfo

// Serialization
$info->toArray(): array  // ['id' => ..., 'fee' => ...]
```

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

### LockFactory

Factory for deserializing locks. Supports custom lock type registration.

```php
// Register custom lock handler (before deserialization)
LockFactory::register(
    string $type,      // Lock type name (matches 'type' in serialized data)
    callable $handler  // fn(array $data): OutputLock
): void

// Check if custom handler is registered
LockFactory::hasHandler(string $type): bool

// List registered custom types
LockFactory::registeredTypes(): array  // list<string>

// Reset custom handlers (for testing)
LockFactory::reset(): void

// Deserialize lock from array
LockFactory::fromArray(array $data): OutputLock
```

**Usage:**

```php
// Register before calling Ledger::fromJson()
LockFactory::register('timelock', fn(array $data) => new TimeLock(
    $data['unlockTimestamp'],
    $data['owner'],
));

$ledger = Ledger::fromJson($json);  // Custom locks restored transparently
```

## Persistence

### HistoryStore Interface

Used by store-backed mode (`Ledger::withStore()`) to delegate history storage to a database. Implement this interface for custom storage backends.

```php
interface HistoryStore
{
    // Query methods
    public function outputHistory(OutputId $id): ?OutputHistory;
    public function outputCreatedBy(OutputId $id): ?string;   // 'genesis' or tx ID
    public function outputSpentBy(OutputId $id): ?string;     // tx ID or null
    public function getSpentOutput(OutputId $id): ?Output;
    public function feeForTx(TxId $id): ?int;
    public function isCoinbase(TxId $id): bool;
    public function coinbaseAmount(TxId $id): ?int;

    // Recording methods (called by store-backed Ledger)
    public function recordTransaction(Tx $tx, int $fee, array $spentOutputData): void;
    public function recordCoinbase(CoinbaseTx $coinbase): void;
    public function recordGenesis(array $outputs): void;
}
```

**Built-in implementation:** `SqliteHistoryStore`

```php
use Chemaclass\Unspent\Persistence\Sqlite\SqliteHistoryStore;

$store = new SqliteHistoryStore(PDO $pdo, string $ledgerId);
```

### LedgerRepository Interface

```php
interface LedgerRepository
{
    public function save(string $id, Ledger $ledger): void;
    public function find(string $id): ?Ledger;
    public function delete(string $id): void;
    public function exists(string $id): bool;
}
```

### QueryableLedgerRepository Interface

Extends `LedgerRepository` with query capabilities:

```php
interface QueryableLedgerRepository extends LedgerRepository
{
    /** @return list<Output> */
    public function findUnspentByOwner(string $ledgerId, string $owner): array;

    /** @return list<Output> */
    public function findUnspentByAmountRange(string $ledgerId, int $min, ?int $max = null): array;

    /** @return list<Output> */
    public function findUnspentByLockType(string $ledgerId, string $lockType): array;

    /** @return list<Output> */
    public function findOutputsCreatedBy(string $ledgerId, string $txId): array;

    public function countUnspent(string $ledgerId): int;

    public function sumUnspentByOwner(string $ledgerId, string $owner): int;

    /** @return list<string> */
    public function findCoinbaseTransactions(string $ledgerId): array;

    /** @return list<TransactionInfo> */
    public function findTransactionsByFeeRange(string $ledgerId, int $min, ?int $max = null): array;
}
```

### AbstractLedgerRepository

Abstract base class for custom repository implementations:

```php
abstract class AbstractLedgerRepository implements QueryableLedgerRepository
{
    // Schema version constant
    public const SCHEMA_VERSION = 1;

    // Lock normalization (Output -> DB columns)
    protected function extractLockData(Output $output): array;
    // Returns: ['type', 'owner', 'pubkey', 'custom']

    // Lock denormalization (DB row -> lock array)
    protected function rowToLockArray(array $row): array;

    // Convert rows to Output objects
    protected function rowsToOutputs(array $rows): array;

    // Build ledger data for Ledger::fromArray()
    protected function buildLedgerDataArray(
        array $unspentRows,
        array $spentRows,
        array $transactionRows,
    ): array;
}
```

### DatabaseSchema Interface

Interface for schema management:

```php
interface DatabaseSchema
{
    public function create(): void;
    public function exists(): bool;
    public function drop(): void;
    public function getVersion(): int;
}
```

### SqliteRepositoryFactory

```php
// In-memory database (ideal for testing)
SqliteRepositoryFactory::createInMemory(): QueryableLedgerRepository

// File-based database (creates schema if needed)
SqliteRepositoryFactory::createFromFile(string $path): QueryableLedgerRepository

// From existing PDO connection
SqliteRepositoryFactory::createFromPdo(PDO $pdo): QueryableLedgerRepository
```

### SqliteSchema

```php
// Create schema tables and indexes
SqliteSchema::create(PDO $pdo): void

// Check if schema exists
SqliteSchema::exists(PDO $pdo): bool

// Drop all tables (for testing/reset)
SqliteSchema::drop(PDO $pdo): void

// Current schema version
SqliteSchema::SCHEMA_VERSION  // 1
```

### Persistence Exceptions

```php
PersistenceException::class       // Base persistence error
LedgerNotFoundException::class    // Requested ledger not found
```

## Exceptions

All extend `UnspentException` which extends `RuntimeException`.

```php
OutputAlreadySpentException::class   // Input not in unspent set
InsufficientSpendsException::class   // Outputs exceed spends
DuplicateOutputIdException::class    // Output ID already exists
DuplicateTxException::class          // Tx ID already used
GenesisNotAllowedException::class    // Genesis on non-empty ledger
AuthorizationException::class        // Lock validation failed
PersistenceException::class          // Storage layer error
LedgerNotFoundException::class       // Ledger not found in storage
```

### Catching All Domain Errors

```php
try {
    $ledger = $ledger->apply($tx);
} catch (UnspentException $e) {
    // Handle any domain error
}
```
