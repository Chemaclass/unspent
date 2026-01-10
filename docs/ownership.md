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

**Validation:** Owner names cannot be empty or whitespace-only. Invalid names throw `InvalidArgumentException`.

```php
// Create owned outputs
$ledger = Ledger::withGenesis(
    Output::ownedBy('alice', 1000, 'alice-funds'),
    Output::ownedBy('bob', 500, 'bob-funds'),
);

// Alice spends her output
$ledger = $ledger->apply(Tx::create(
    inputIds: ['alice-funds'],
    outputs: [
        Output::ownedBy('bob', 600),
        Output::ownedBy('alice', 400),
    ],
    signedBy: 'alice',  // Must match 'alice'
));

// Wrong signer throws AuthorizationException
$ledger->apply(Tx::create(
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

**Validation:** The public key must be a valid base64-encoded 32-byte Ed25519 key. Invalid keys throw `InvalidArgumentException` at construction time.

```php
// Generate keypair (client-side, keep private key secret)
$keypair = sodium_crypto_sign_keypair();
$publicKey = base64_encode(sodium_crypto_sign_publickey($keypair));
$privateKey = sodium_crypto_sign_secretkey($keypair);

// Lock output to public key
$ledger = Ledger::withGenesis(
    Output::signedBy($publicKey, 1000, 'secure-funds'),
);

// To spend, sign the spend ID with private key
$spendId = 'tx-001';
$signature = base64_encode(
    sodium_crypto_sign_detached($spendId, $privateKey)
);

$ledger = $ledger->apply(Tx::create(
    inputIds: ['secure-funds'],
    outputs: [Output::signedBy($publicKey, 900)],
    proofs: [$signature],  // Signature at index 0 for input 0
    id: $spendId,
));
```

### Multiple Inputs, Multiple Signatures

Each input needs its own signature at the matching index:

```php
$ledger = Ledger::withGenesis(
    Output::signedBy($alicePubKey, 500, 'alice-funds'),
    Output::signedBy($bobPubKey, 300, 'bob-funds'),
);

$spendId = 'multi-sig-tx';
$aliceSig = base64_encode(sodium_crypto_sign_detached($spendId, $alicePrivKey));
$bobSig = base64_encode(sodium_crypto_sign_detached($spendId, $bobPrivKey));

$ledger = $ledger->apply(Tx::create(
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
$ledger->apply(Tx::create(
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
use Chemaclass\Unspent\Tx;

final readonly class TimeLock implements OutputLock
{
    public function __construct(
        public int $unlockTimestamp,
        public string $owner,
    ) {}

    public function validate(Tx $tx, int $inputIndex): void
    {
        if (time() < $this->unlockTimestamp) {
            throw new \RuntimeException('Output is time-locked');
        }
        if ($tx->signedBy !== $this->owner) {
            throw AuthorizationException::notOwner($this->owner, $tx->signedBy);
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

Register your custom lock handler with `LockFactory` before deserializing:

```php
use Chemaclass\Unspent\Lock\LockFactory;

// Register BEFORE calling Ledger::fromJson()
LockFactory::register('timelock', fn(array $data) => new TimeLock(
    $data['unlockTimestamp'],
    $data['owner'],
));

// Now deserialization works transparently
$ledger = Ledger::fromJson($json);

// Custom locks are fully restored
$output = $ledger->unspent()->get(new OutputId('locked-funds'));
assert($output->lock instanceof TimeLock);
```

**Important**: Register handlers at application bootstrap, before any deserialization.

#### Available Helper Methods

```php
// Check if a handler is registered
LockFactory::hasHandler('timelock');  // true

// List all registered custom types
LockFactory::registeredTypes();  // ['timelock']

// Reset handlers (useful in tests)
LockFactory::reset();
```

#### Handler Signature

Handlers receive the full serialized lock data:

```php
LockFactory::register('mylock', function (array $data): OutputLock {
    // $data contains: ['type' => 'mylock', 'field1' => ..., 'field2' => ...]
    return new MyLock($data['field1'], $data['field2']);
});
```

Custom handlers take precedence over built-in types, allowing you to override `none`, `owner`, or `pubkey` if needed.

## Ownership Through Serialization

Locks are preserved when serializing/deserializing the ledger:

```php
$ledger = Ledger::withGenesis(
    Output::ownedBy('alice', 1000, 'alice-funds'),
);

// Save
$json = $ledger->toJson();
file_put_contents('ledger.json', $json);

// Restore
$restored = Ledger::fromJson(file_get_contents('ledger.json'));

// Ownership still enforced
$restored->apply(Tx::create(
    inputIds: ['alice-funds'],
    outputs: [Output::open(1000)],
    signedBy: 'bob',  // Still throws!
));
```

## Next Steps

- [Fees & Minting](fees-and-minting.md) - Implicit fees and coinbase transactions
- [Persistence](persistence.md) - Serialization patterns
- [API Reference](api-reference.md) - Complete method reference
