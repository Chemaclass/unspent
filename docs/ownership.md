# Ownership & Authorization

Every output has a **lock** that determines who can spend it. The library provides three built-in locks and supports custom implementations.

## Built-in Locks

| Lock | Factory | Verification | Use Case |
|------|---------|--------------|----------|
| `Owner` | `Output::ownedBy()` | `signedBy` matches name | Server-side apps |
| `PublicKey` | `Output::signedBy()` | Ed25519 signature | Trustless systems |
| `NoLock` | `Output::open()` | None (anyone) | Burn addresses, public pools |

## Simple Ownership (Owner Lock)

For applications where the server controls authentication. The server verifies identity (via session, JWT, etc.) before calling spend.

```php
// Create owned outputs
$ledger = Ledger::empty()->addGenesis(
    Output::ownedBy('alice', 1000, 'alice-funds'),
    Output::ownedBy('bob', 500, 'bob-funds'),
);

// Alice spends her output
$ledger = $ledger->apply(Spend::create(
    inputIds: ['alice-funds'],
    outputs: [
        Output::ownedBy('bob', 600),
        Output::ownedBy('alice', 400),
    ],
    signedBy: 'alice',  // Must match 'alice'
));

// Wrong signer throws AuthorizationException
$ledger->apply(Spend::create(
    inputIds: ['bob-funds'],
    outputs: [Output::open(500)],
    signedBy: 'alice',  // Bob owns this!
)); // Throws: "Output owned by 'bob', but spend signed by 'alice'"
```

### When to Use

- Web apps with user sessions
- APIs with JWT/OAuth
- Any system where you trust server-side auth

## Cryptographic Ownership (PublicKey Lock)

For trustless systems where you can't trust the server. Uses Ed25519 signatures.

```php
// Generate keypair (client-side, keep private key secret)
$keypair = sodium_crypto_sign_keypair();
$publicKey = base64_encode(sodium_crypto_sign_publickey($keypair));
$privateKey = sodium_crypto_sign_secretkey($keypair);

// Lock output to public key
$ledger = Ledger::empty()->addGenesis(
    Output::signedBy($publicKey, 1000, 'secure-funds'),
);

// To spend, sign the spend ID with private key
$spendId = 'tx-001';
$signature = base64_encode(
    sodium_crypto_sign_detached($spendId, $privateKey)
);

$ledger = $ledger->apply(Spend::create(
    inputIds: ['secure-funds'],
    outputs: [Output::signedBy($publicKey, 900)],
    proofs: [$signature],  // Signature at index 0 for input 0
    id: $spendId,
));
```

### Multiple Inputs, Multiple Signatures

Each input needs its own signature at the matching index:

```php
$ledger = Ledger::empty()->addGenesis(
    Output::signedBy($alicePubKey, 500, 'alice-funds'),
    Output::signedBy($bobPubKey, 300, 'bob-funds'),
);

$spendId = 'multi-sig-tx';
$aliceSig = base64_encode(sodium_crypto_sign_detached($spendId, $alicePrivKey));
$bobSig = base64_encode(sodium_crypto_sign_detached($spendId, $bobPrivKey));

$ledger = $ledger->apply(Spend::create(
    inputIds: ['alice-funds', 'bob-funds'],
    outputs: [Output::open(800)],
    proofs: [$aliceSig, $bobSig],  // Index matches input order
    id: $spendId,
));
```

### When to Use

- Decentralized applications
- Client-controlled wallets
- Systems requiring cryptographic proof of authorization

## Open Outputs (NoLock)

For outputs that anyone can spend. Use with caution.

```php
// Anyone can claim this
Output::open(1000, 'public-pool')

// Spending requires no authorization
$ledger->apply(Spend::create(
    inputIds: ['public-pool'],
    outputs: [Output::ownedBy('finder', 1000)],
    // No signedBy needed
));
```

### When to Use

- Burn addresses (provably unspendable when combined with validation)
- Public reward pools
- Testing and development

## Custom Locks

Implement `OutputLock` for advanced scenarios:

```php
use Chemaclass\Unspent\OutputLock;
use Chemaclass\Unspent\Spend;

final readonly class TimeLock implements OutputLock
{
    public function __construct(
        public int $unlockTimestamp,
        public string $owner,
    ) {}

    public function validate(Spend $spend, int $inputIndex): void
    {
        if (time() < $this->unlockTimestamp) {
            throw new \RuntimeException('Output is time-locked');
        }
        if ($spend->signedBy !== $this->owner) {
            throw AuthorizationException::notOwner($this->owner, $spend->signedBy);
        }
    }

    public function toArray(): array
    {
        return [
            'type' => 'timelock',
            'unlockTimestamp' => $this->unlockTimestamp,
            'owner' => $this->owner,
        ];
    }
}

// Usage
Output::lockedWith(
    new TimeLock(strtotime('+1 week'), 'alice'),
    1000,
    'locked-funds'
)
```

### Custom Lock Serialization

To restore custom locks from serialized data, extend `LockFactory`:

```php
// In your application bootstrap
class CustomLockFactory extends LockFactory
{
    public static function fromArray(array $data): OutputLock
    {
        return match ($data['type']) {
            'timelock' => new TimeLock($data['unlockTimestamp'], $data['owner']),
            default => parent::fromArray($data),
        };
    }
}
```

## Ownership Through Serialization

Locks are preserved when serializing/deserializing the ledger:

```php
$ledger = Ledger::empty()->addGenesis(
    Output::ownedBy('alice', 1000, 'alice-funds'),
);

// Save
$json = $ledger->toJson();
file_put_contents('ledger.json', $json);

// Restore
$restored = Ledger::fromJson(file_get_contents('ledger.json'));

// Ownership still enforced
$restored->apply(Spend::create(
    inputIds: ['alice-funds'],
    outputs: [Output::open(1000)],
    signedBy: 'bob',  // Still throws!
));
```

## Next Steps

- [Fees & Minting](fees-and-minting.md) - Implicit fees and coinbase transactions
- [Persistence](persistence.md) - Serialization patterns
- [API Reference](api-reference.md) - Complete method reference
