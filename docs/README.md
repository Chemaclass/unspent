# Documentation

Full reference for the Unspent library. Start with **Core Concepts** if you're new, or jump straight to **API Reference** if you already understand the model.

## Getting Started

| Doc | What you'll learn |
|-|-|
| [Core Concepts](concepts.md) | Outputs, transactions, ledger — the UTXO model in plain terms |
| [Migration Guide](migration.md) | Move from balance-based systems to UTXO with minimal disruption |

## Building Things

| Doc | What you'll learn |
|-|-|
| [Ownership](ownership.md) | Locks: owner, Ed25519 pubkey, timelock, multisig, hashlock, custom |
| [Fees & Minting](fees-and-minting.md) | Implicit fees, coinbase transactions, economic models |
| [Selection Strategies](selection-strategies.md) | FIFO, largest-first, smallest-first, exact-match, random, custom |
| [History & Provenance](history.md) | Trace value through transactions, chain of custody |
| [Events](events.md) | PSR-14 event dispatching, logging, event sourcing |

## Persistence & Scale

| Doc | What you'll learn |
|-|-|
| [Persistence](persistence.md) | JSON, SQLite, custom locks |
| [Custom Persistence](custom-persistence.md) | Build your own database backend (MySQL, Redis, Mongo…) |
| [Scalability](scalability.md) | In-memory vs store-backed mode, when to switch |

## Operations

| Doc | What you'll learn |
|-|-|
| [Troubleshooting](troubleshooting.md) | Common exceptions and fixes |
| [API Reference](api-reference.md) | Every public class and method |

## Architecture Decisions

The `adr/` directory records design choices and their rationale.

| ADR | Topic |
|-|-|
| [001](adr/001-utxo-over-balance.md) | UTXO over balance-based design |
| [002](adr/002-mutable-design.md) | Mutable ledger with fluent API |
| [003](adr/003-lock-type-system.md) | Lock type system |
| [004](adr/004-copy-on-fork-optimization.md) | Copy-on-fork optimization |

## Examples

Runnable demos under [`../example/`](../example/). See [example/README.md](../example/README.md) for the catalogue.

## Contributing

See [`.github/CONTRIBUTING.md`](../.github/CONTRIBUTING.md) for setup, TDD workflow, quality gates, and commit format.
