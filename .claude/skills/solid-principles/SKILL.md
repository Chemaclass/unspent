# SOLID Principles

Quick reference for SOLID as applied in the Unspent UTXO library.

## Overview

| Principle | Application in Unspent |
|-----------|----------------------|
| **SRP** | `Ledger` = state, `Tx` = transaction, `Output` = value chunk |
| **OCP** | New locks via `OutputLock`, new strategies via `SelectionStrategy` |
| **LSP** | All `OutputLock` / `SelectionStrategy` implementations are interchangeable |
| **ISP** | Separate interfaces: `LedgerRepository`, `HistoryRepository`, `SelectionStrategy` |
| **DIP** | Domain depends on interfaces, never on SQLite/concrete adapters |

## Code Smell → Violation

| Smell | Violation | Fix |
|-------|-----------|-----|
| Class does too much | SRP | Extract class |
| Switch on type | OCP | Strategy pattern |
| `instanceof` checks | LSP | Use interface polymorphism |
| Empty method implementations | ISP | Split interface |
| `new ConcreteClass` in domain | DIP | Dependency injection |
| Hard to test in isolation | DIP | Depend on abstraction |
| Changes ripple everywhere | SRP + OCP | Extract + extend via interface |

## Refactoring Patterns

| Problem | Pattern |
|---------|---------|
| Multiple responsibilities | Extract Class |
| Switch on type | Strategy (like `SelectionStrategy`) |
| Fat interface | Interface Segregation |
| Concrete dependencies | Dependency Injection |
| Complex conditionals | Replace with Polymorphism |
