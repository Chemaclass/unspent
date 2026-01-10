# Ownership

Every output has a **lock** that determines who can spend it.

## Built-in Locks

| Lock | Create with | Spend with | Best for |
|-|-|-|-|
| Owner | `Output::ownedBy()` | `signedBy: 'name'` | Server-side apps |
| PublicKey | `Output::signedBy()` | Ed25519 signature | Trustless systems |
| NoLock | `Output::open()` | Anyone | Burn addresses, public pools |

## Owner Lock

For apps where you control authentication (sessions, JWT, API keys).

```php
$ledger = InMemoryLedger::withGenesis(
    Output::ownedBy('alice', 1000, 'alice-funds'),
);

// Alice spends her funds
$ledger = $ledger->apply(Tx::create(
    spendIds: ['alice-funds'],
    outputs: [Output::ownedBy('bob', 1000)],
    signedBy: 'alice', // Must match
));

// Wrong signer = error
$ledger->apply(Tx::create(
    spendIds: ['alice-funds'],
    outputs: [Output::open(1000)],
    signedBy: 'mallory', // AuthorizationException
));
```

## PublicKey Lock

For trustless systems. Uses Ed25519 cryptography.

```php
// Generate keys (keep private key secret)
$keypair = sodium_crypto_sign_keypair();
$publicKey = base64_encode(sodium_crypto_sign_publickey($keypair));
$privateKey = sodium_crypto_sign_secretkey($keypair);

// Lock to public key
$ledger = InMemoryLedger::withGenesis(
    Output::signedBy($publicKey, 1000, 'secure-funds'),
);

// To spend: sign the transaction ID
$txId = 'tx-001';
$signature = base64_encode(sodium_crypto_sign_detached($txId, $privateKey));

$ledger = $ledger->apply(Tx::create(
    spendIds: ['secure-funds'],
    outputs: [Output::signedBy($publicKey, 900)],
    proofs: [$signature], // Signature at matching index
    id: $txId,
));
```

**Multiple inputs** = multiple signatures (one per input, matching order):

```php
$ledger = $ledger->apply(Tx::create(
    spendIds: ['alice-funds', 'bob-funds'],
    outputs: [Output::open(800)],
    proofs: [$aliceSig, $bobSig], // Index 0 for alice, index 1 for bob
    id: $txId,
));
```

## Open Outputs

Anyone can spend. Use for burn addresses or public pools.

```php
Output::open(1000, 'public-pool')

// No authorization needed
$ledger->apply(Tx::create(
    spendIds: ['public-pool'],
    outputs: [Output::ownedBy('finder', 1000)],
));
```

## Custom Locks

Implement `OutputLock` for advanced scenarios (time locks, multi-sig, etc.).

```php
final readonly class TimeLock implements OutputLock
{
    public function __construct(
        public int $unlockTime,
        public string $owner,
    ) {}

    public function validate(Tx $tx, int $inputIndex): void
    {
        if (time() < $this->unlockTime) {
            throw new RuntimeException('Still locked');
        }
        if ($tx->signedBy !== $this->owner) {
            throw AuthorizationException::notOwner($this->owner, $tx->signedBy);
        }
    }

    public function toArray(): array
    {
        return [
            'type' => 'timelock',
            'unlockTime' => $this->unlockTime,
            'owner' => $this->owner,
        ];
    }
}

// Usage
Output::lockedWith(new TimeLock(strtotime('+1 week'), 'alice'), 1000)
```

### Serializing Custom Locks

Register your lock type before deserializing:

```php
LockFactory::register('timelock', fn(array $data) => new TimeLock(
    $data['unlockTime'],
    $data['owner'],
));

$ledger = InMemoryLedger::fromJson($json); // Custom locks restored
```

## Next Steps

- [Fees & Minting](fees-and-minting.md) - Implicit fees and creating value
- [Persistence](persistence.md) - Save and restore state
