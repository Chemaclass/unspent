# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `UnspentSet::snapshot()` — returns an isolated copy-on-write view of the outputs; used by `Ledger::unspent()`
- `UtxoAnalytics::summarize(UnspentSet $unspent, int $dustThreshold = 10)` — computes every owner metric (count, total, average, min, max, dust, largest, smallest, oldest) in a single pass over a pre-fetched set, so callers needing several metrics fetch the owner's outputs only once

### Changed

- `Ledger` now writes history through the mutating `HistoryRepository::saveTransaction()` / `saveCoinbase()` / `saveGenesis()` methods instead of allocating a new repository instance per operation
- `IdGenerator::forOutput()` no longer takes an `$amount` argument — output ids are random and never derived from the amount

### Removed

- **BREAKING**: `HistoryRepository::withTransaction()`, `withCoinbase()`, and `withGenesis()` — the immutable copy-on-write variants that merely duplicated the `save*` methods. Custom `HistoryRepository` implementations now only need the `save*` methods.

### Performance

- In-memory history no longer copies its internal arrays on every `apply()` / `applyCoinbase()`; sequential application is now linear instead of O(n²) in the number of transactions
- `Ledger::unspent()` now returns a copy-on-write snapshot instead of marking the internal set as shared, so reading no longer forces a full copy on the next write; interleaved read/write and event-dispatching `apply()` are now linear
- `UnspentSet` maintains an incremental owner index, so owner-scoped lookups (`ownedBy()`, `totalAmountOwnedBy()`, and therefore `Ledger::unspentByOwner()` / `totalUnspentByOwner()` / `transfer()` / `debit()` / `consolidate()` / `batchTransfer()` and `UtxoAnalytics`) cost O(outputs-owned-by-owner) instead of O(total outputs); `totalAmountOwnedBy()` no longer allocates an intermediate set and `filter()` computes its total in a single pass
- `SqliteLedgerRepository::save()` serializes the ledger once and writes outputs/transactions with chunked multi-row `INSERT`s instead of one round trip per row; transaction coinbase/fee metadata is read from the serialized array instead of per-transaction method calls
- `Ledger::apply()` captures spent-output history data during spend validation, looking each spent output up once instead of twice
- `IdGenerator::forOutput()` returns 128 random bits directly instead of hashing them, dropping a SHA-256 call per output created
- SQLite `idx_outputs_owner` widened to `(ledger_id, lock_owner, is_spent)` as a partial index (`WHERE lock_owner IS NOT NULL`), so unspent-by-owner queries are served entirely from the index and non-owner rows are skipped (applies to newly created schemas)

## [1.1.0] - 2026-07-22

### Added

- `RandomStrategy` coin selection — shuffles outputs randomly before selecting for improved privacy
- `SelectionStrategy::name()` now returns `'random'` for the new strategy

### Changed

- Removed deprecated static methods from `SqliteSchema` (`createSchema()`, `schemaExists()`, `dropSchema()`) — use instance methods instead
- Fixed PHPUnit notices in `LoggingLedgerTest` by using `createStub()` for mocks without expectations
- Corrected CLAUDE.md to reflect actual PHPStan level 8

### Internal

- Deduplicated shared logic into traits: `IdValue` (`OutputId`/`TxId`), `AccumulatesOutputs` (selection strategies), and `PdoStatementCache`/`PdoQueryWrapper`/`PdoTransactionalWrite` (SQLite repositories)
- Strengthened weak types: `mixed`/`callable` narrowed to `Closure`, precise `array{}` shapes for lock (de)serialization, `@phpstan-type TOutputRow`/`TTransactionRow` for database rows
- Consolidated repeated ledger array-shape docblocks behind PHPStan `typeAliases` (`TOutputData`, `TLedgerArray`, …)
- Removed restating comments and redundant `@param` docblocks

---

## [1.0.0] - 2026-01-14

### Added

- UTXO-based ledger with pluggable history storage and fluent API
- Output types: `Output::open()`, `Output::ownedBy()`, `Output::signedBy()`, `Output::lockedWith()`
- Lock types: `NoLock`, `Owner`, `PublicKey`, custom locks via `LockFactory`
- Transaction types: `Tx` (spending transactions), `CoinbaseTx` (minting)
- Persistence: `InMemoryHistoryRepository`, `SqliteHistoryRepository`
- Query support: `QueryableLedgerRepository` with owner/amount/type filters
- Complete output history tracking and audit trails
- JSON serialization support
- `OutputLock::type()` method for lock type introspection
- `UnspentSet::filter()` for filtering outputs by predicate
- `UnspentSet::ownedBy()` for getting outputs by owner
- `UnspentSet::totalAmountOwnedBy()` for summing amounts by owner
- `LedgerInterface::unspentByOwner()` convenience method
- `LedgerInterface::totalUnspentByOwner()` convenience method
- `LedgerInterface::canApply()` for validating transactions without applying
- `HistoryRepository::withTransaction()`, `withCoinbase()`, `withGenesis()` methods for history tracking
- `@throws` annotations on all public methods in `Ledger`, `Tx`, `Output`

### Performance

- Repository operations return new instances instead of cloning (O(1) vs O(n))
- `SqliteLedgerRepository::insertOutputs()` pre-fetches metadata to avoid N+1 queries
- `UnspentSet::outputIds()` optimized to avoid unnecessary array operations

[Unreleased]: https://github.com/Chemaclass/unspent/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/Chemaclass/unspent/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/Chemaclass/unspent/releases/tag/v1.0.0
