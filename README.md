# Unspent

UTXO-style bookkeeping for PHP. Think Bitcoin's transaction model, but for your app.

## The Problem

You're tracking balances with a single number: `alice_balance = 500`. Cool, but:

- Where did those 500 come from?
- Were any spent twice? (oops)
- Can you prove the history to an auditor?

Nope. Traditional balance tracking is a black box.

## The Solution

Track value like Bitcoin tracks coins. Every unit has an origin, can only be spent once, and leaves a trail. No mutations, no surprises.

```php
$ledger = Ledger::empty()->addGenesis(
    Output::ownedBy('alice', 1000, 'genesis'),
);

// Alice spends 600 to Bob, keeps 390, burns 10 as fee
$ledger = $ledger->apply(Spend::create(
    inputIds: ['genesis'],
    outputs: [
        Output::ownedBy('bob', 600),
        Output::ownedBy('alice', 390, 'alice-change'),
    ],
    signedBy: 'alice',
    id: 'tx-001',
));

$ledger->totalUnspentAmount();  // 990 (10 went to fees)
```

That's it. Full audit trail. Double-spend protection. Ownership verification.

## When to Use This

- **Virtual currencies** - in-game gold, tokens, credits
- **Loyalty points** - every point traceable from earn to burn
- **Internal accounting** - audit-ready, no hidden mutations
- **Event sourcing** - fits naturally with event-sourced domains

If you need to answer "where did this value come from?" - this is your library.

## Install

```bash
composer require chemaclass/unspent
```

PHP 8.4+

## How It Works

### Outputs

A chunk of value with ownership. Spend it, and it's gone - replaced by new outputs.

```php
Output::ownedBy('alice', 1000)           // Alice owns 1000 units
Output::ownedBy('alice', 1000, 'tx-out') // With explicit ID
Output::signedBy($publicKey, 1000)       // Crypto-locked (Ed25519)
Output::open(1000)                        // Anyone can spend (explicit)
```

### Spends

Consume outputs, create new ones. The transaction.

```php
Spend::create(
    inputIds: ['alice-funds'],
    outputs: [Output::ownedBy('bob', 100)],
    signedBy: 'alice',  // Must match output owner
    id: 'tx-001',       // Optional - auto-generated if omitted
);
```

Inputs must exist. Outputs can't exceed inputs (the gap is the fee).

### Ledger

Immutable state. Every operation returns a new ledger - you can't mess with history.

```php
$v1 = Ledger::empty()->addGenesis(Output::ownedBy('alice', 100));
$v2 = $v1->apply($someSpend);
// $v1 unchanged, $v2 has the new state
```

## Ownership

Every output has an owner. Only the owner can spend it.

### Simple Ownership (Server-Side)

For apps where the server controls authentication:

```php
// Create owned outputs
$ledger = Ledger::empty()->addGenesis(
    Output::ownedBy('alice', 1000, 'alice-funds'),
    Output::ownedBy('bob', 500, 'bob-funds'),
);

// Alice spends - must sign
$ledger = $ledger->apply(Spend::create(
    inputIds: ['alice-funds'],
    outputs: [Output::ownedBy('bob', 900)],
    signedBy: 'alice',
));

// Wrong signer = AuthorizationException
$ledger->apply(Spend::create(
    inputIds: ['bob-funds'],
    outputs: [...],
    signedBy: 'alice',  // Bob owns this!
)); // Throws AuthorizationException
```

### Cryptographic Ownership (Trustless)

For decentralized systems using Ed25519 signatures:

```php
// Generate keypair (client-side)
$keypair = sodium_crypto_sign_keypair();
$publicKey = base64_encode(sodium_crypto_sign_publickey($keypair));
$privateKey = sodium_crypto_sign_secretkey($keypair);

// Lock output to public key
$ledger = Ledger::empty()->addGenesis(
    Output::signedBy($publicKey, 1000, 'secure-funds'),
);

// Spend requires valid signature
$spendId = 'tx-001';
$signature = base64_encode(
    sodium_crypto_sign_detached($spendId, $privateKey)
);

$ledger = $ledger->apply(Spend::create(
    inputIds: ['secure-funds'],
    outputs: [Output::signedBy($publicKey, 900)],
    proofs: [$signature],
    id: $spendId,
));
```

### Open Outputs

For intentionally public outputs (burn addresses, etc.):

```php
Output::open(1000, 'burn-address')  // Anyone can spend
```

### Built-in Locks

| Lock        | Factory Method       | Verification                       |
|-------------|----------------------|------------------------------------|
| `Owner`     | `Output::ownedBy()`  | `signedBy` matches owner name      |
| `PublicKey` | `Output::signedBy()` | Ed25519 signature in `proofs`      |
| `NoLock`    | `Output::open()`     | No verification (anyone can spend) |

Custom locks? Implement `OutputLock` interface.

## Fees

Outputs don't match inputs? The difference is the fee. Just like Bitcoin.

```php
// 1000 in, 990 out = 10 fee
$ledger->feeForSpend(new SpendId('tx-001')); // 10
$ledger->totalFeesCollected();               // all fees combined
$ledger->allSpendFees();                     // ['tx-001' => 10, ...]
```

Zero fees work too - just make inputs = outputs.

## Coinbase (Minting)

Need to create new value? Like miners getting block rewards? Use coinbase transactions:

```php
// Mint new coins (no inputs required)
$ledger = Ledger::empty()
    ->applyCoinbase(Coinbase::create([
        Output::ownedBy('miner', 50, 'block-reward'),
    ], 'block-1'));

$ledger->totalMinted();  // 50
$ledger->isCoinbase(new SpendId('block-1'));  // true

// Spend minted coins like any other output
$ledger = $ledger->apply(Spend::create(
    inputIds: ['block-reward'],
    outputs: [Output::ownedBy('alice', 45)],
    signedBy: 'miner',
));
// 5 goes to fees
```

Regular spends need inputs. Coinbase transactions create value out of thin air.

## Validation

The library won't let you do dumb things:

| Mistake                         | Exception                      |
|---------------------------------|--------------------------------|
| Spend something twice           | `OutputAlreadySpentException`  |
| Spend more than you have        | `InsufficientInputsException`  |
| Duplicate output IDs            | `DuplicateOutputIdException`   |
| Reuse a spend ID                | `DuplicateSpendException`      |
| Add genesis to non-empty ledger | `GenesisNotAllowedException`   |
| Spend without authorization     | `AuthorizationException`       |

Catch everything with `UnspentException`:

```php
try {
    $ledger->apply($spend);
} catch (UnspentException $e) {
    // nope
}
```

## Persistence

Save and restore ledger state. Perfect for databases, caching, or cross-machine sync.

```php
// Save to file/database
$json = $ledger->toJson();
file_put_contents('ledger.json', $json);

// Restore later
$ledger = Ledger::fromJson(file_get_contents('ledger.json'));

// Continue where you left off - ownership is preserved
$ledger = $ledger->apply(Spend::create(...));
```

Also works with arrays for custom serialization:

```php
$array = $ledger->toArray();   // For database storage
$ledger = Ledger::fromArray($array);
```

## API

```php
// Create outputs
Output::ownedBy(string $owner, int $amount, ?string $id = null): Output
Output::signedBy(string $publicKey, int $amount, ?string $id = null): Output
Output::open(int $amount, ?string $id = null): Output
Output::lockedWith(OutputLock $lock, int $amount, ?string $id = null): Output

// Create spends
Spend::create(
    array $inputIds,
    array $outputs,
    ?string $signedBy = null,
    ?string $id = null,
    array $proofs = [],
): Spend

// Create coinbase
Coinbase::create(array $outputs, ?string $id = null): Coinbase

// Ledger operations
Ledger::empty(): Ledger
$ledger->addGenesis(Output ...$outputs): Ledger
$ledger->apply(Spend $spend): Ledger
$ledger->applyCoinbase(Coinbase $coinbase): Ledger

// Query
$ledger->totalUnspentAmount(): int
$ledger->totalFeesCollected(): int
$ledger->feeForSpend(SpendId $id): ?int
$ledger->allSpendFees(): array
$ledger->hasSpendBeenApplied(SpendId $id): bool
$ledger->unspent(): UnspentSet
$ledger->totalMinted(): int
$ledger->isCoinbase(SpendId $id): bool
$ledger->coinbaseAmount(SpendId $id): ?int

// Persistence
$ledger->toArray(): array
$ledger->toJson(int $flags = 0): string
Ledger::fromArray(array $data): Ledger
Ledger::fromJson(string $json): Ledger

// Locks
new Owner(string $name)
new PublicKey(string $base64Key)
new NoLock()
```

## Contributing

See [CONTRIBUTING.md](.github/CONTRIBUTING.md).

## License

MIT
