# ADR-001: UTXO Model Over Balance Model

## Status

Accepted

## Context

Traditional accounting systems use a balance model where each account has a single number representing the current balance. Operations like `balance += amount` or `balance -= amount` mutate this value directly.

We needed to choose an accounting model for tracking value in PHP applications that supports:

1. Complete audit trails
2. Double-spend prevention
3. Concurrent access safety
4. Atomic multi-party transfers

## Decision

We chose the **UTXO (Unspent Transaction Output)** model, inspired by Bitcoin.

In the UTXO model:
- Value exists as discrete, immutable "outputs" (like physical bills)
- Spending consumes existing outputs and creates new ones
- Every unit of value has a traceable origin
- Double-spending is structurally impossible (an output is either unspent or spent)

## Consequences

### Positive

- **Audit trail is built-in**: Every output knows which transaction created it and (if spent) which transaction consumed it.
- **Double-spend prevention**: The ledger enforces that each output can only be spent once. No race conditions.
- **Atomic transfers**: A transaction either succeeds completely or fails completely - no partial state.
- **Natural authorization**: Outputs can have locks (ownership requirements) that must be satisfied to spend them.
- **Parallelization**: Independent transactions (spending different outputs) can be processed in parallel.

### Negative

- **Higher complexity**: Developers must understand the UTXO model, which is less intuitive than balance accounting.
- **More storage**: Each output is stored separately vs. a single balance number.
- **Output selection**: Transfers require choosing which outputs to spend (coin selection).

### Mitigations

- Simple API (`credit`, `debit`, `transfer`) hides UTXO complexity for basic use cases.
- Coin control API available for advanced users who need explicit output selection.
- Comprehensive documentation explains the model.

## Alternatives Considered

### Balance Model with Event Sourcing

Store all events and derive balance from them.

**Rejected because**: Still requires custom logic for double-spend prevention. Events can conflict without explicit locking.

### Account-Based Model (like Ethereum)

Each account has a nonce and balance, with optimistic concurrency.

**Rejected because**: Requires complex nonce management and doesn't provide the same level of traceability.

## References

- [Bitcoin UTXO Model](https://bitcoin.org/en/glossary/unspent-transaction-output)
- [docs/concepts.md](../concepts.md) - Core concepts documentation
