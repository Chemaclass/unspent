<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit;

use Chemaclass\Unspent\Exception\AuthorizationException;
use Chemaclass\Unspent\InMemoryLedger;
use Chemaclass\Unspent\Lock\LockFactory;
use Chemaclass\Unspent\Lock\Owner;
use Chemaclass\Unspent\Lock\PublicKey;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\Tx;
use Chemaclass\Unspent\UnspentSet;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class OwnershipTest extends TestCase
{
    // Simple ownership (Owner lock) tests

    public function test_owner_can_spend_their_output(): void
    {
        $ledger = InMemoryLedger::withGenesis(
            Output::ownedBy('alice', 1000, 'alice-funds'),
        );

        $ledger = $ledger->apply(Tx::create(
            spendIds: ['alice-funds'],
            outputs: [Output::open(900, 'bob-payment')],
            signedBy: 'alice',
        ));

        self::assertSame(900, $ledger->totalUnspentAmount());
    }

    public function test_non_owner_cannot_spend_others_output(): void
    {
        $ledger = InMemoryLedger::withGenesis(
            Output::ownedBy('alice', 1000, 'alice-funds'),
        );

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage("Output owned by 'alice', but spend signed by 'bob'");

        $ledger->apply(Tx::create(
            spendIds: ['alice-funds'],
            outputs: [Output::open(900, 'stolen')],
            signedBy: 'bob',
        ));
    }

    public function test_spend_without_signature_fails_for_owned_output(): void
    {
        $ledger = InMemoryLedger::withGenesis(
            Output::ownedBy('alice', 1000, 'alice-funds'),
        );

        $this->expectException(AuthorizationException::class);

        $ledger->apply(Tx::create(
            spendIds: ['alice-funds'],
            outputs: [Output::open(900, 'stolen')],
        ));
    }

    public function test_open_outputs_can_be_spent_by_anyone(): void
    {
        $ledger = InMemoryLedger::withGenesis(
            Output::open(1000, 'open-funds'),
        );

        $ledger = $ledger->apply(Tx::create(
            spendIds: ['open-funds'],
            outputs: [Output::open(900, 'taken')],
        ));

        self::assertSame(900, $ledger->totalUnspentAmount());
    }

    public function test_spend_can_combine_inputs_from_same_owner(): void
    {
        $ledger = InMemoryLedger::withGenesis(
            Output::ownedBy('alice', 500, 'alice-1'),
            Output::ownedBy('alice', 300, 'alice-2'),
        );

        $ledger = $ledger->apply(Tx::create(
            spendIds: ['alice-1', 'alice-2'],
            outputs: [Output::open(750, 'combined')],
            signedBy: 'alice',
        ));

        self::assertSame(750, $ledger->totalUnspentAmount());
    }

    public function test_cannot_combine_inputs_from_different_owners(): void
    {
        $ledger = InMemoryLedger::withGenesis(
            Output::ownedBy('alice', 500, 'alice-funds'),
            Output::ownedBy('bob', 300, 'bob-funds'),
        );

        $this->expectException(AuthorizationException::class);

        $ledger->apply(Tx::create(
            spendIds: ['alice-funds', 'bob-funds'],
            outputs: [Output::open(750, 'combined')],
            signedBy: 'alice',
        ));
    }

    public function test_ownership_transfers_with_new_lock(): void
    {
        $ledger = InMemoryLedger::withGenesis(
            Output::ownedBy('alice', 1000, 'alice-funds'),
        );

        $ledger = $ledger->apply(Tx::create(
            spendIds: ['alice-funds'],
            outputs: [Output::ownedBy('bob', 900, 'bob-funds')],
            signedBy: 'alice',
        ));

        // Alice can no longer spend
        $this->expectException(AuthorizationException::class);
        $ledger->apply(Tx::create(
            spendIds: ['bob-funds'],
            outputs: [Output::open(800, 'stolen')],
            signedBy: 'alice',
        ));
    }

    public function test_ownership_preserved_through_serialization(): void
    {
        $original = InMemoryLedger::withGenesis(
            Output::ownedBy('alice', 1000, 'alice-funds'),
        );

        $restored = InMemoryLedger::fromJson($original->toJson());

        // Owner can still spend after restore
        $restored = $restored->apply(Tx::create(
            spendIds: ['alice-funds'],
            outputs: [Output::open(900, 'spent')],
            signedBy: 'alice',
        ));

        self::assertSame(900, $restored->totalUnspentAmount());
    }

    public function test_ownership_lock_blocks_after_serialization(): void
    {
        $original = InMemoryLedger::withGenesis(
            Output::ownedBy('alice', 1000, 'alice-funds'),
        );

        $restored = InMemoryLedger::fromJson($original->toJson());

        // Non-owner still blocked after restore
        $this->expectException(AuthorizationException::class);
        $restored->apply(Tx::create(
            spendIds: ['alice-funds'],
            outputs: [Output::open(900, 'stolen')],
            signedBy: 'bob',
        ));
    }

    // Cryptographic ownership (PublicKey lock) tests

    public function test_public_key_lock_validates_signature(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $publicKey = base64_encode(sodium_crypto_sign_publickey($keypair));
        $privateKey = sodium_crypto_sign_secretkey($keypair);

        $ledger = InMemoryLedger::withGenesis(
            Output::signedBy($publicKey, 1000, 'secure-funds'),
        );

        $spendId = 'tx-001';
        $signature = base64_encode(
            sodium_crypto_sign_detached($spendId, $privateKey),
        );

        $ledger = $ledger->apply(Tx::create(
            spendIds: ['secure-funds'],
            outputs: [Output::open(900)],
            id: $spendId,
            proofs: [$signature],
        ));

        self::assertSame(900, $ledger->totalUnspentAmount());
    }

    public function test_public_key_lock_rejects_wrong_signature(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $publicKey = base64_encode(sodium_crypto_sign_publickey($keypair));

        // Wrong keypair
        $wrongKeypair = sodium_crypto_sign_keypair();
        $wrongPrivateKey = sodium_crypto_sign_secretkey($wrongKeypair);

        $ledger = InMemoryLedger::withGenesis(
            Output::signedBy($publicKey, 1000, 'secure-funds'),
        );

        $spendId = 'tx-001';
        $wrongSignature = base64_encode(
            sodium_crypto_sign_detached($spendId, $wrongPrivateKey),
        );

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Invalid signature for spend 0');

        $ledger->apply(Tx::create(
            spendIds: ['secure-funds'],
            outputs: [Output::open(900)],
            id: $spendId,
            proofs: [$wrongSignature],
        ));
    }

    public function test_public_key_lock_requires_proof(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $publicKey = base64_encode(sodium_crypto_sign_publickey($keypair));

        $ledger = InMemoryLedger::withGenesis(
            Output::signedBy($publicKey, 1000, 'secure-funds'),
        );

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Missing authorization proof for spend 0');

        $ledger->apply(Tx::create(
            spendIds: ['secure-funds'],
            outputs: [Output::open(900)],
            id: 'tx-001',
        ));
    }

    public function test_public_key_lock_preserved_through_serialization(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $publicKey = base64_encode(sodium_crypto_sign_publickey($keypair));
        $privateKey = sodium_crypto_sign_secretkey($keypair);

        $original = InMemoryLedger::withGenesis(
            Output::signedBy($publicKey, 1000, 'secure-funds'),
        );

        $restored = InMemoryLedger::fromJson($original->toJson());

        $spendId = 'tx-001';
        $signature = base64_encode(
            sodium_crypto_sign_detached($spendId, $privateKey),
        );

        $restored = $restored->apply(Tx::create(
            spendIds: ['secure-funds'],
            outputs: [Output::open(900)],
            id: $spendId,
            proofs: [$signature],
        ));

        self::assertSame(900, $restored->totalUnspentAmount());
    }

    public function test_multiple_inputs_require_multiple_proofs(): void
    {
        $keypair1 = sodium_crypto_sign_keypair();
        $publicKey1 = base64_encode(sodium_crypto_sign_publickey($keypair1));
        $privateKey1 = sodium_crypto_sign_secretkey($keypair1);

        $keypair2 = sodium_crypto_sign_keypair();
        $publicKey2 = base64_encode(sodium_crypto_sign_publickey($keypair2));
        $privateKey2 = sodium_crypto_sign_secretkey($keypair2);

        $ledger = InMemoryLedger::withGenesis(
            Output::signedBy($publicKey1, 500, 'funds-1'),
            Output::signedBy($publicKey2, 300, 'funds-2'),
        );

        $spendId = 'multi-tx';
        $sig1 = base64_encode(sodium_crypto_sign_detached($spendId, $privateKey1));
        $sig2 = base64_encode(sodium_crypto_sign_detached($spendId, $privateKey2));

        $ledger = $ledger->apply(Tx::create(
            spendIds: ['funds-1', 'funds-2'],
            outputs: [Output::open(750)],
            id: $spendId,
            proofs: [$sig1, $sig2],
        ));

        self::assertSame(750, $ledger->totalUnspentAmount());
    }

    // Security hardening tests (TIER 1)

    public function test_public_key_rejects_invalid_base64(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Ed25519 public key');

        new PublicKey('not-valid-base64!!!');
    }

    public function test_public_key_rejects_wrong_length_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Ed25519 public key');

        new PublicKey(base64_encode('too-short')); // Not 32 bytes
    }

    public function test_public_key_accepts_valid_key(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $publicKey = base64_encode(sodium_crypto_sign_publickey($keypair));

        $lock = new PublicKey($publicKey);

        self::assertSame($publicKey, $lock->key);
    }

    public function test_signature_wrong_length_rejected(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $publicKey = base64_encode(sodium_crypto_sign_publickey($keypair));

        $ledger = InMemoryLedger::withGenesis(
            Output::signedBy($publicKey, 1000, 'secure-funds'),
        );

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Invalid signature for spend 0');

        // Provide a signature that's not 64 bytes
        $wrongLengthSig = base64_encode('short-signature');

        $ledger->apply(Tx::create(
            spendIds: ['secure-funds'],
            outputs: [Output::open(900)],
            id: 'tx-001',
            proofs: [$wrongLengthSig],
        ));
    }

    public function test_owner_rejects_empty_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Owner name cannot be empty or whitespace');

        new Owner('');
    }

    public function test_owner_rejects_whitespace_only_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Owner name cannot be empty or whitespace');

        new Owner('   ');
    }

    public function test_lock_factory_rejects_missing_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Lock type is required');

        LockFactory::fromArray([]);
    }

    public function test_lock_factory_rejects_missing_owner_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Name is required for owner lock');

        LockFactory::fromArray(['type' => 'owner']);
    }

    public function test_lock_factory_rejects_missing_pubkey_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Key is required for pubkey lock');

        LockFactory::fromArray(['type' => 'pubkey']);
    }

    public function test_unspent_set_throws_on_missing_lock_data(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Output 'test-id' missing lock data");

        UnspentSet::fromArray([
            'test-id' => ['amount' => 100],
        ]);
    }
}
