<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit;

use Chemaclass\Unspent\Exception\AuthorizationException;
use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Lock\Owner;
use Chemaclass\Unspent\Lock\PublicKey;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\Tx;
use PHPUnit\Framework\TestCase;

final class OwnershipTest extends TestCase
{
    // Simple ownership (Owner lock) tests

    public function test_owner_can_spend_their_output(): void
    {
        $ledger = Ledger::empty()->addGenesis(
            Output::ownedBy('alice', 1000, 'alice-funds'),
        );

        $ledger = $ledger->apply(Tx::create(
            inputIds: ['alice-funds'],
            outputs: [Output::open(900, 'bob-payment')],
            signedBy: 'alice',
        ));

        self::assertSame(900, $ledger->totalUnspentAmount());
    }

    public function test_non_owner_cannot_spend_others_output(): void
    {
        $ledger = Ledger::empty()->addGenesis(
            Output::ownedBy('alice', 1000, 'alice-funds'),
        );

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage("Output owned by 'alice', but spend signed by 'bob'");

        $ledger->apply(Tx::create(
            inputIds: ['alice-funds'],
            outputs: [Output::open(900, 'stolen')],
            signedBy: 'bob',
        ));
    }

    public function test_spend_without_signature_fails_for_owned_output(): void
    {
        $ledger = Ledger::empty()->addGenesis(
            Output::ownedBy('alice', 1000, 'alice-funds'),
        );

        $this->expectException(AuthorizationException::class);

        $ledger->apply(Tx::create(
            inputIds: ['alice-funds'],
            outputs: [Output::open(900, 'stolen')],
        ));
    }

    public function test_open_outputs_can_be_spent_by_anyone(): void
    {
        $ledger = Ledger::empty()->addGenesis(
            Output::open(1000, 'open-funds'),
        );

        $ledger = $ledger->apply(Tx::create(
            inputIds: ['open-funds'],
            outputs: [Output::open(900, 'taken')],
        ));

        self::assertSame(900, $ledger->totalUnspentAmount());
    }

    public function test_spend_can_combine_inputs_from_same_owner(): void
    {
        $ledger = Ledger::empty()->addGenesis(
            Output::ownedBy('alice', 500, 'alice-1'),
            Output::ownedBy('alice', 300, 'alice-2'),
        );

        $ledger = $ledger->apply(Tx::create(
            inputIds: ['alice-1', 'alice-2'],
            outputs: [Output::open(750, 'combined')],
            signedBy: 'alice',
        ));

        self::assertSame(750, $ledger->totalUnspentAmount());
    }

    public function test_cannot_combine_inputs_from_different_owners(): void
    {
        $ledger = Ledger::empty()->addGenesis(
            Output::ownedBy('alice', 500, 'alice-funds'),
            Output::ownedBy('bob', 300, 'bob-funds'),
        );

        $this->expectException(AuthorizationException::class);

        $ledger->apply(Tx::create(
            inputIds: ['alice-funds', 'bob-funds'],
            outputs: [Output::open(750, 'combined')],
            signedBy: 'alice',
        ));
    }

    public function test_ownership_transfers_with_new_lock(): void
    {
        $ledger = Ledger::empty()->addGenesis(
            Output::ownedBy('alice', 1000, 'alice-funds'),
        );

        $ledger = $ledger->apply(Tx::create(
            inputIds: ['alice-funds'],
            outputs: [Output::ownedBy('bob', 900, 'bob-funds')],
            signedBy: 'alice',
        ));

        // Alice can no longer spend
        $this->expectException(AuthorizationException::class);
        $ledger->apply(Tx::create(
            inputIds: ['bob-funds'],
            outputs: [Output::open(800, 'stolen')],
            signedBy: 'alice',
        ));
    }

    public function test_ownership_preserved_through_serialization(): void
    {
        $original = Ledger::empty()->addGenesis(
            Output::ownedBy('alice', 1000, 'alice-funds'),
        );

        $restored = Ledger::fromJson($original->toJson());

        // Owner can still spend after restore
        $restored = $restored->apply(Tx::create(
            inputIds: ['alice-funds'],
            outputs: [Output::open(900, 'spent')],
            signedBy: 'alice',
        ));

        self::assertSame(900, $restored->totalUnspentAmount());
    }

    public function test_ownership_lock_blocks_after_serialization(): void
    {
        $original = Ledger::empty()->addGenesis(
            Output::ownedBy('alice', 1000, 'alice-funds'),
        );

        $restored = Ledger::fromJson($original->toJson());

        // Non-owner still blocked after restore
        $this->expectException(AuthorizationException::class);
        $restored->apply(Tx::create(
            inputIds: ['alice-funds'],
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

        $ledger = Ledger::empty()->addGenesis(
            Output::signedBy($publicKey, 1000, 'secure-funds'),
        );

        $spendId = 'tx-001';
        $signature = base64_encode(
            sodium_crypto_sign_detached($spendId, $privateKey),
        );

        $ledger = $ledger->apply(Tx::create(
            inputIds: ['secure-funds'],
            outputs: [Output::open(900)],
            proofs: [$signature],
            id: $spendId,
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

        $ledger = Ledger::empty()->addGenesis(
            Output::signedBy($publicKey, 1000, 'secure-funds'),
        );

        $spendId = 'tx-001';
        $wrongSignature = base64_encode(
            sodium_crypto_sign_detached($spendId, $wrongPrivateKey),
        );

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Invalid signature for input 0');

        $ledger->apply(Tx::create(
            inputIds: ['secure-funds'],
            outputs: [Output::open(900)],
            proofs: [$wrongSignature],
            id: $spendId,
        ));
    }

    public function test_public_key_lock_requires_proof(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $publicKey = base64_encode(sodium_crypto_sign_publickey($keypair));

        $ledger = Ledger::empty()->addGenesis(
            Output::signedBy($publicKey, 1000, 'secure-funds'),
        );

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Missing authorization proof for input 0');

        $ledger->apply(Tx::create(
            inputIds: ['secure-funds'],
            outputs: [Output::open(900)],
            id: 'tx-001',
        ));
    }

    public function test_public_key_lock_preserved_through_serialization(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $publicKey = base64_encode(sodium_crypto_sign_publickey($keypair));
        $privateKey = sodium_crypto_sign_secretkey($keypair);

        $original = Ledger::empty()->addGenesis(
            Output::signedBy($publicKey, 1000, 'secure-funds'),
        );

        $restored = Ledger::fromJson($original->toJson());

        $spendId = 'tx-001';
        $signature = base64_encode(
            sodium_crypto_sign_detached($spendId, $privateKey),
        );

        $restored = $restored->apply(Tx::create(
            inputIds: ['secure-funds'],
            outputs: [Output::open(900)],
            proofs: [$signature],
            id: $spendId,
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

        $ledger = Ledger::empty()->addGenesis(
            Output::signedBy($publicKey1, 500, 'funds-1'),
            Output::signedBy($publicKey2, 300, 'funds-2'),
        );

        $spendId = 'multi-tx';
        $sig1 = base64_encode(sodium_crypto_sign_detached($spendId, $privateKey1));
        $sig2 = base64_encode(sodium_crypto_sign_detached($spendId, $privateKey2));

        $ledger = $ledger->apply(Tx::create(
            inputIds: ['funds-1', 'funds-2'],
            outputs: [Output::open(750)],
            proofs: [$sig1, $sig2],
            id: $spendId,
        ));

        self::assertSame(750, $ledger->totalUnspentAmount());
    }
}
