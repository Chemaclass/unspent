# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
