# ADR-003: Lock Type System

## Status

Accepted

## Context

In a UTXO system, outputs need authorization rules to control who can spend them. We needed a flexible system that supports:

1. Simple ownership (server-side authorization)
2. Cryptographic signatures (trustless authorization)
3. Custom conditions (timelocks, multisig, smart contracts)

## Decision

We implemented a **Lock type system** with the `OutputLock` interface:

```php
interface OutputLock
{
    public function validate(Tx $tx, int $inputIndex): void;
    public function toArray(): array;
}
```

Built-in locks:
- `NoLock` - Open outputs, anyone can spend (pure bookkeeping)
- `Owner` - Server-side authorization via `signedBy` field
- `PublicKey` - Ed25519 cryptographic signatures

Custom locks can be registered via `LockFactory`:

```php
LockFactory::register('timelock', fn(array $data) => new TimeLock(
    $data['unlockTimestamp'],
    $data['owner'],
));
```

## Consequences

### Positive

- **Flexible authorization**: From simple ownership to cryptographic proofs.
- **Extensible**: Any authorization scheme can be implemented via `OutputLock`.
- **Serializable**: Locks can be persisted and restored via `toArray()`/`LockFactory`.
- **Composable**: Custom locks can combine conditions (e.g., multisig with timelock).

### Negative

- **Complexity**: Multiple lock types add mental overhead.
- **Static registry**: `LockFactory` uses static state, requiring cleanup in tests.

### Mitigations

- Convenience methods (`Output::ownedBy()`) hide lock details for common cases.
- `LockFactory::reset()` available for test cleanup.
- Documentation clearly explains when to use each lock type.

## Lock Types Explained

### NoLock (Open Outputs)

```php
$output = Output::open(100);  // Anyone can spend
```

Use for internal bookkeeping where the application controls all access.

### Owner Lock

```php
$output = Output::ownedBy('alice', 100);
// Requires tx.signedBy === 'alice'
```

Use when a central server validates identity (sessions, JWT, API keys).

### PublicKey Lock (Ed25519)

```php
$output = Output::signedBy($alicePublicKey, 100);
// Requires valid Ed25519 signature from alice's private key
```

Use for trustless verification where clients sign transactions.

### Custom Locks

```php
class TimeLock implements OutputLock
{
    public function __construct(
        private int $unlockTimestamp,
        private string $owner,
    ) {}

    public function validate(Tx $tx, int $inputIndex): void
    {
        if (time() < $this->unlockTimestamp) {
            throw AuthorizationException::timeLockNotExpired();
        }
        if ($tx->signedBy !== $this->owner) {
            throw AuthorizationException::wrongOwner();
        }
    }
}
```

## Alternatives Considered

### Single "owner" Field

Just store an owner string on each output.

**Rejected because**:
- Can't support cryptographic authorization
- Can't support complex conditions (multisig, timelocks)

### Script System (like Bitcoin)

Implement a scripting language for authorization.

**Rejected because**:
- Overkill for most use cases
- Significantly increases complexity
- Security concerns with script execution

## References

- [docs/ownership.md](../ownership.md) - Ownership documentation
- [Bitcoin Script](https://en.bitcoin.it/wiki/Script) - Inspiration for lock concepts
