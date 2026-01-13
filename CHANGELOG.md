# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `OutputLock::type()` method for lock type introspection
- `UnspentSet::filter()` for filtering outputs by predicate
- `UnspentSet::ownedBy()` for getting outputs by owner
- `UnspentSet::totalAmountOwnedBy()` for summing amounts by owner
- `LedgerInterface::unspentByOwner()` convenience method
- `LedgerInterface::totalUnspentByOwner()` convenience method
- `LedgerInterface::canApply()` for validating transactions without applying
- `HistoryRepository::withTransaction()`, `withCoinbase()`, `withGenesis()` immutable methods
- `@throws` annotations on all public methods in `Ledger`, `Tx`, `Output`

### Changed

- Performance: Repository operations now return new instances instead of cloning (O(1) vs O(n))
- Performance: `SqliteLedgerRepository::insertOutputs()` now pre-fetches metadata to avoid N+1 queries
- Performance: `UnspentSet::outputIds()` optimized to avoid unnecessary array operations

### Breaking Changes

- `OutputLock` interface now requires `type(): string` method
  - Custom lock implementations must add this method
  - Return your lock's type identifier (e.g., `'timelock'`)

## [1.0.0] - Initial Release

### Added

- UTXO-based immutable ledger with pluggable history storage
- Output types: `Output::open()`, `Output::ownedBy()`, `Output::signedBy()`, `Output::lockedWith()`
- Lock types: `NoLock`, `Owner`, `PublicKey`, custom locks via `LockFactory`
- Transaction types: `Tx` (spending transactions), `CoinbaseTx` (minting)
- Persistence: `InMemoryHistoryRepository`, `SqliteHistoryRepository`
- Query support: `QueryableLedgerRepository` with owner/amount/type filters
- Complete output history tracking and audit trails
- JSON serialization support
