# Ownership

Every output has a **lock** that determines who can spend it.

## Built-in Locks

| Lock | Create with | Spend with | Best for |
|-|-|-|-|
| Owner | `Output::ownedBy()` | `signedBy: 'name'` | Server-side apps |
| PublicKey | `Output::signedBy()` | Ed25519 signature | Trustless systems |
| NoLock | `Output::open()` | Anyone | Burn addresses, public pools |
| TimeLock | `Output::timelocked()` | After timestamp | Vesting, escrow |
| MultisigLock | `Output::multisig()` | M-of-N signatures | Joint accounts, governance |
| HashLock | `Output::hashlocked()` | Preimage proof | Atomic swaps, HTLCs |

## Owner Lock

For apps where you control authentication (sessions, JWT, API keys).

```php
$ledger = Ledger::withGenesis(
    Output::ownedBy('alice', 1000, 'alice-funds'),
);

// Alice spends her funds
$ledger->apply(Tx::create(
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
$ledger = Ledger::withGenesis(
    Output::signedBy($publicKey, 1000, 'secure-funds'),
);

// To spend: sign the transaction ID
$txId = 'tx-001';
$signature = base64_encode(sodium_crypto_sign_detached($txId, $privateKey));

$ledger->apply(Tx::create(
    spendIds: ['secure-funds'],
    outputs: [Output::signedBy($publicKey, 900)],
    proofs: [$signature], // Signature at matching index
    id: $txId,
));
```

**Multiple inputs** = multiple signatures (one per input, matching order):

```php
$ledger->apply(Tx::create(
    spendIds: ['alice-funds', 'bob-funds'],
    outputs: [Output::open(800)],
    proofs: [$aliceSig, $bobSig], // Index 0 for alice, index 1 for bob
    id: $txId,
));
```

### Key Derivation Options

For user-friendly key management, consider deriving keys from passwords or mnemonics:

```php
// Option 1: Derive from password using Argon2id
$salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
$seed = sodium_crypto_pwhash(
    32,
    $password,
    $salt,
    SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
    SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
    SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
);
$keypair = sodium_crypto_sign_seed_keypair($seed);

// Option 2: Use a BIP-39 mnemonic library (third-party)
// Generate deterministic keys from "word word word..." phrases

// Option 3: Store encrypted keys
$encrypted = sodium_crypto_secretbox($privateKey, $nonce, $encryptionKey);
```

**Key management best practices:**
- Never store private keys in plaintext
- Use hardware security modules (HSM) for high-value systems
- Implement key rotation strategies for long-running applications
- Consider multi-signature schemes for critical operations

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

## TimeLock

Outputs that can only be spent after a certain time. Perfect for vesting, escrow timeouts, or delayed payments.

```php
// Lock until 30 days from now
$ledger = Ledger::withGenesis(
    Output::timelocked('alice', 1000, strtotime('+30 days'), 'vesting-funds'),
);

// Before unlock time: AuthorizationException
// After unlock time: works if signed by alice
$ledger->apply(Tx::create(
    spendIds: ['vesting-funds'],
    outputs: [Output::ownedBy('alice', 1000)],
    signedBy: 'alice',
));
```

**Advanced usage with custom inner lock:**

```php
use Chemaclass\Unspent\Lock\TimeLock;
use Chemaclass\Unspent\Lock\MultisigLock;

// Time-locked multisig (escrow with timeout)
$lock = new TimeLock(
    innerLock: new MultisigLock(2, ['alice', 'bob', 'arbitrator']),
    unlockTime: strtotime('+7 days'),
);
Output::lockedWith($lock, 1000);
```

## MultisigLock

M-of-N signature schemes. Ideal for joint accounts, escrow, or governance.

```php
// 2-of-3 multisig
$ledger = Ledger::withGenesis(
    Output::multisig(2, ['alice', 'bob', 'charlie'], 1000, 'joint-funds'),
);

// Spend requires signatures from at least 2 of the 3 signers
$ledger->apply(Tx::create(
    spendIds: ['joint-funds'],
    outputs: [Output::ownedBy('recipient', 1000)],
    proofs: ['alice,bob'], // Comma-separated signers
));
```

**Common patterns:**

```php
// 1-of-1: equivalent to Owner lock
Output::multisig(1, ['alice'], 1000);

// 2-of-2: both parties must agree
Output::multisig(2, ['alice', 'bob'], 1000);

// 2-of-3: any two of three (escrow with arbitrator)
Output::multisig(2, ['buyer', 'seller', 'arbitrator'], 1000);
```

## HashLock

Spend requires revealing the preimage of a hash. Used for atomic swaps and Hash Time-Locked Contracts (HTLCs).

```php
$secret = 'my-secret-preimage';
$hash = hash('sha256', $secret);

// Create hash-locked output
$ledger = Ledger::withGenesis(
    Output::hashlocked($hash, 1000, 'sha256', 'alice', 'htlc-output'),
);

// Spend by revealing the preimage
$ledger->apply(Tx::create(
    spendIds: ['htlc-output'],
    outputs: [Output::ownedBy('bob', 1000)],
    signedBy: 'alice',
    proofs: [$secret], // The preimage
));
```

**Supported algorithms:** `sha256`, `sha512`, `ripemd160`, `sha3-256`

**HTLC pattern (atomic swap):**

```php
use Chemaclass\Unspent\Lock\HashLock;
use Chemaclass\Unspent\Lock\TimeLock;

// Alice creates HTLC: Bob can claim with secret, or Alice refunds after timeout
$htlc = new TimeLock(
    innerLock: HashLock::sha256($secret, new Owner('bob')),
    unlockTime: strtotime('+24 hours'),
);
```

## Custom Locks

Implement `OutputLock` for advanced scenarios beyond the built-in locks.

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

$ledger = Ledger::fromJson($json); // Custom locks restored
```

## Next Steps

- [Fees & Minting](fees-and-minting.md) - Implicit fees and creating value
- [Persistence](persistence.md) - Save and restore state
