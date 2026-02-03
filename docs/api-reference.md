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

// Time-locked output (spendable after timestamp)
Output::timelocked(
    string $owner,
    int $amount,
    int $unlockTime,    // Unix timestamp
    ?string $id = null
): Output

// Multisig output (M-of-N signatures required)
Output::multisig(
    int $threshold,      // Minimum signatures required (M)
    array $signers,      // list<string> - Authorized signers (N)
    int $amount,
    ?string $id = null
): Output

// Hash-locked output (preimage required)
Output::hashlocked(
    string $hash,        // Hash that preimage must match
    int $amount,
    string $algorithm = 'sha256',  // sha256, sha512, ripemd160, sha3-256
    ?string $owner = null,         // Optional inner owner lock
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

Mutable state container with two modes:

- **In-memory mode** - Simple, all-in-memory (< 100k outputs)
- **Store-backed mode** - Production-ready, bounded memory (100k+ outputs, uses HistoryRepository)

### In-memory Mode Creation

```php
Ledger::inMemory(): Ledger                                 // Empty ledger
Ledger::withGenesis(Output ...$outputs): Ledger            // Recommended
Ledger::fromArray(array $data): Ledger                     // From serialized data
Ledger::fromJson(string $json): Ledger                     // From JSON
```

### Store-backed Mode Creation

```php
// Create new ledger with HistoryRepository
Ledger::withRepository(HistoryRepository $repository): Ledger
Ledger::withRepository($repository)->addGenesis(Output ...$outputs): Ledger

// Load from existing UnspentSet (for persistence)
Ledger::fromUnspentSet(
    UnspentSet $unspentSet,
    HistoryRepository $repository,
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

### Simple API

Convenience methods for common operations:

```php
// Transfer from one owner to another
$ledger->transfer(
    string $from,
    string $to,
    int $amount,
    int $fee = 0,
    ?string $txId = null
): Ledger

// Debit (burn) value from an owner
$ledger->debit(
    string $owner,
    int $amount,
    int $fee = 0,
    ?string $txId = null
): Ledger

// Credit (mint) value to an owner
$ledger->credit(
    string $owner,
    int $amount,
    ?string $txId = null
): Ledger
```

### Batch Operations

```php
// Consolidate all outputs for an owner into one
$ledger->consolidate(
    string $owner,
    int $fee = 0,
    ?string $txId = null
): Ledger

// Transfer to multiple recipients in one transaction
$ledger->batchTransfer(
    string $from,
    array $recipients,  // ['recipient' => amount, ...]
    int $fee = 0,
    ?string $txId = null
): Ledger
```

### Query - Unspent

```php
$ledger->unspent(): UnspentSet           // Access unspent outputs
$ledger->totalUnspentAmount(): int       // Sum of all unspent
```

### Query - By Owner

```php
// Get all unspent outputs owned by a specific owner
$ledger->unspentByOwner(string $owner): UnspentSet

// Get total unspent amount for a specific owner
$ledger->totalUnspentByOwner(string $owner): int
```

> **Note:** For large datasets with SQLite, prefer `QueryableLedgerRepository::findUnspentByOwner()` and `sumUnspentByOwner()` for O(1) memory usage.

### Validation

```php
// Check if a transaction can be applied without actually applying it
// Returns null if valid, or the exception that would be thrown
$ledger->canApply(Tx $tx): ?UnspentException
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

> **Note:** In store-backed mode, `allTxFees()` queries the `HistoryRepository` for all fees. Use `feeForTx()` to query specific transaction fees.

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

### TimeLock

Time-based restriction with inner lock.

```php
new TimeLock(OutputLock $innerLock, int $unlockTime)

$lock->innerLock;    // OutputLock
$lock->unlockTime;   // int (Unix timestamp)
$lock->isLocked(): bool
$lock->remainingTime(): int  // Seconds until unlock (0 if unlocked)
$lock->validate(Tx $tx, int $inputIndex): void
$lock->toArray(): array  // ['type' => 'timelock', 'unlockTime' => ..., 'innerLock' => [...]]

// Create already-unlocked lock (for testing/persistence)
TimeLock::alreadyUnlocked(OutputLock $innerLock, int $unlockTime): TimeLock

// Deserialize from array
TimeLock::fromArray(array $data): TimeLock
```

### MultisigLock

M-of-N signature scheme.

```php
new MultisigLock(int $threshold, array $signers)  // $signers is list<string>

$lock->threshold;   // int - Minimum signatures required
$lock->signers;     // list<string> - Authorized signer names
$lock->description(): string  // "2-of-3 multisig"
$lock->validate(Tx $tx, int $inputIndex): void
$lock->toArray(): array  // ['type' => 'multisig', 'threshold' => ..., 'signers' => [...]]

// Deserialize from array
MultisigLock::fromArray(array $data): MultisigLock
```

**Proof format:** Comma-separated signer names (e.g., `'alice,bob'`)

### HashLock

Hash preimage verification with optional inner lock.

```php
new HashLock(string $hash, string $algorithm, ?OutputLock $innerLock = null)

$lock->hash;       // string - Expected hash
$lock->algorithm;  // string - Hash algorithm
$lock->innerLock;  // ?OutputLock
$lock->verifyPreimage(string $preimage): bool
$lock->validate(Tx $tx, int $inputIndex): void
$lock->toArray(): array

// Create from secret (hashes automatically)
HashLock::sha256(string $secret, ?OutputLock $innerLock = null): HashLock

// Create from existing hash
HashLock::fromHash(string $hash, string $algorithm, ?OutputLock $innerLock = null): HashLock

// Deserialize from array
HashLock::fromArray(array $data): HashLock
```

**Supported algorithms:** `sha256`, `sha512`, `ripemd160`, `sha3-256`

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

### HistoryRepository Interface

Used by store-backed mode (`Ledger::withRepository()`) to delegate history storage to a database. Implement this interface for custom storage backends.

```php
interface HistoryRepository
{
    // Write operations
    public function saveTransaction(Tx $tx, int $fee, array $spentOutputData): void;
    public function saveCoinbase(CoinbaseTx $coinbase): void;
    public function saveGenesis(array $outputs): void;

    // Read operations - Outputs
    public function findSpentOutput(OutputId $id): ?Output;
    public function findOutputHistory(OutputId $id): ?OutputHistory;
    public function findOutputCreatedBy(OutputId $id): ?string;   // 'genesis' or tx ID
    public function findOutputSpentBy(OutputId $id): ?string;     // tx ID or null

    // Read operations - Transactions
    public function findFeeForTx(TxId $id): ?int;
    public function findAllTxFees(): array;
    public function isCoinbase(TxId $id): bool;
    public function findCoinbaseAmount(TxId $id): ?int;
}
```

**Built-in implementations:**

- `InMemoryHistoryRepository` - Stores all history in memory (used by `Ledger::inMemory()`)
- `SqliteHistoryRepository` - Stores history in SQLite database

```php
use Chemaclass\Unspent\Persistence\InMemoryHistoryRepository;
use Chemaclass\Unspent\Persistence\Sqlite\SqliteHistoryRepository;

// In-memory (for development/testing, < 100k outputs)
$repository = new InMemoryHistoryRepository();

// SQLite (for production, 100k+ outputs)
$repository = new SqliteHistoryRepository(PDO $pdo, string $ledgerId);
```

### InMemoryHistoryRepository

Stores all transaction history in memory arrays. Ideal for development, testing, and small applications.

```php
// Create empty repository
$repository = new InMemoryHistoryRepository();

// Create with pre-populated data
$repository = new InMemoryHistoryRepository(
    txFees: ['tx-1' => 10],
    coinbaseAmounts: ['cb-1' => 100],
    outputCreatedBy: ['o-1' => 'genesis'],
    outputSpentBy: ['o-1' => 'tx-2'],
    spentOutputs: ['o-1' => ['amount' => 50, 'lock' => ['type' => 'none']]],
);

// Serialization (for JSON persistence)
$data = $repository->toArray();
$restored = InMemoryHistoryRepository::fromArray($data);
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

## UtxoAnalytics

Static utility class for analyzing UTXO sets.

```php
use Chemaclass\Unspent\UtxoAnalytics;

// Find outputs below a threshold ("dust")
UtxoAnalytics::findDust(
    LedgerInterface $ledger,
    string $owner,
    int $threshold
): array  // list<Output>

// Get oldest unspent output (first in iteration order)
UtxoAnalytics::oldestUnspent(
    LedgerInterface $ledger,
    string $owner
): ?Output

// Get largest unspent output by amount
UtxoAnalytics::largestUnspent(
    LedgerInterface $ledger,
    string $owner
): ?Output

// Get smallest unspent output by amount
UtxoAnalytics::smallestUnspent(
    LedgerInterface $ledger,
    string $owner
): ?Output

// Get comprehensive statistics
UtxoAnalytics::stats(
    LedgerInterface $ledger,
    string $owner,
    int $dustThreshold = 10
): array
// Returns: [
//     'count' => int,
//     'total' => int,
//     'average' => int,
//     'min' => int,
//     'max' => int,
//     'dustCount' => int,
//     'dustTotal' => int,
// ]

// Get number of outputs for an owner
UtxoAnalytics::outputCountByOwner(
    LedgerInterface $ledger,
    string $owner
): int

// Check if consolidation is recommended
UtxoAnalytics::shouldConsolidate(
    LedgerInterface $ledger,
    string $owner,
    int $threshold = 10  // Output count threshold
): bool
```

## Mempool

Transaction staging area for validation before commit.

```php
use Chemaclass\Unspent\Mempool;

$mempool = new Mempool(LedgerInterface $ledger);

// Add transaction (validates against ledger + checks for double-spend)
$mempool->add(Tx $tx): string  // Returns transaction ID

// Remove transaction from mempool
$mempool->remove(string $txId): void

// Replace transaction (RBF - Replace-By-Fee)
$mempool->replace(string $oldTxId, Tx $newTx): void

// Apply all pending transactions to ledger
$mempool->commit(): int  // Returns number committed

// Apply single transaction
$mempool->commitOne(string $txId): void

// Clear all pending transactions (discard)
$mempool->clear(): void

// Query methods
$mempool->has(string $txId): bool
$mempool->get(string $txId): ?Tx
$mempool->all(): array  // ['txId' => Tx, ...]
$mempool->count(): int

// Fee methods
$mempool->totalPendingFees(): int
$mempool->feeFor(string $txId): ?int
```

**Double-spend detection:**

The mempool tracks which outputs are being spent by pending transactions. If you try to add a transaction that spends an output already claimed by another pending transaction, it throws `OutputAlreadySpentException` with details about the conflicting transaction.

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
    $ledger->apply($tx);
} catch (UnspentException $e) {
    // Handle any domain error
}
```
