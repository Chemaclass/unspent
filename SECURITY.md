# Security Policy

## Security Model

Unspent implements a UTXO-based authorization model where outputs are protected by **locks**. A transaction can only spend an output if it satisfies the lock's validation requirements.

### Built-in Lock Types

| Lock Type | Security Level | Use Case |
|-----------|---------------|----------|
| `NoLock` | None | Anyone can spend. Use only for testing or intentionally open outputs. |
| `Owner` | Application-level | Server-side authentication. The `signedBy` field must match the owner name. |
| `PublicKey` | Cryptographic | Ed25519 signature verification. Requires valid signature in `proofs` array. |

### Cryptographic Assumptions

The `PublicKey` lock uses **Ed25519** signatures via PHP's `sodium` extension:

- **Key size**: 32 bytes (256 bits)
- **Signature size**: 64 bytes (512 bits)
- **Algorithm**: EdDSA over Curve25519
- **Security level**: ~128-bit equivalent

Ed25519 is considered secure against known attacks. The implementation uses PHP's built-in `sodium_crypto_sign_verify_detached()` which is a binding to libsodium.

### Custom Lock Implementations

When implementing custom locks via `OutputLock`:

1. **Validate thoroughly**: Your `validate()` method should throw `AuthorizationException` on any failure
2. **Fail secure**: When in doubt, reject the transaction
3. **Avoid timing attacks**: Use constant-time comparison for secrets
4. **Document assumptions**: Clearly state what your lock guarantees

```php
class MyCustomLock implements OutputLock
{
    public function validate(Tx $tx, int $inputIndex): void
    {
        // Always validate - never skip checks
        if (!$this->isAuthorized($tx)) {
            throw AuthorizationException::custom('Reason for rejection');
        }
    }
}
```

## Known Limitations

### Not Designed For

- **Distributed consensus**: This library provides local UTXO accounting, not distributed ledger consensus
- **Network security**: No protection against network-level attacks; that's your application's responsibility
- **Key management**: Does not handle private key storage or generation

### Concurrency

- `Ledger` is immutable and thread-safe for reads
- Write operations (apply, applyCoinbase) return new instances
- For concurrent writes, implement external locking or use the SQLite repository with proper transaction isolation

### Input Validation

The library validates:
- Output IDs (alphanumeric, max 64 chars)
- Transaction IDs (alphanumeric, max 64 chars)
- Amounts (positive integers, max `PHP_INT_MAX`)
- Lock data structure

Your application should additionally validate:
- User input before creating transactions
- Business logic constraints

## Reporting Security Issues

If you discover a security vulnerability, please report it responsibly:

1. **Do not** open a public GitHub issue
2. Email the maintainer directly at: `hi@chemaclass.es`
3. Include:
   - Description of the vulnerability
   - Steps to reproduce
   - Potential impact
   - Suggested fix (if any)

We aim to respond within 48 hours and will work with you to understand and address the issue.

## Security Updates

Security fixes will be:
- Released as patch versions (e.g., 1.0.1)
- Documented in CHANGELOG.md
- Announced via GitHub releases

## Best Practices

### For Application Developers

1. **Use `PublicKey` locks** for high-value outputs requiring cryptographic proof
2. **Use `Owner` locks** when you have server-side authentication
3. **Never use `NoLock`** in production for valuable outputs
4. **Validate transactions** before applying with `canApply()`
5. **Store private keys securely** - this library doesn't handle key management
6. **Use SQLite repository** for audit trails and recovery

### For Lock Implementers

1. **Throw exceptions** on validation failure, never return false
2. **Use `AuthorizationException`** for authorization failures
3. **Test edge cases**: empty inputs, malformed data, boundary values
4. **Document security properties** of your custom lock
