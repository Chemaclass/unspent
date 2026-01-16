<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit\Lock;

use Chemaclass\Unspent\Exception\AuthorizationException;
use Chemaclass\Unspent\Lock\LockType;
use Chemaclass\Unspent\Lock\PublicKey;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\OutputLock;
use Chemaclass\Unspent\Tx;
use Chemaclass\Unspent\TxId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PublicKeyTest extends TestCase
{
    private string $validPublicKey;
    private string $validPrivateKey;

    protected function setUp(): void
    {
        // Generate a valid Ed25519 key pair for testing
        $keyPair = sodium_crypto_sign_keypair();
        $this->validPrivateKey = base64_encode(sodium_crypto_sign_secretkey($keyPair));
        $this->validPublicKey = base64_encode(sodium_crypto_sign_publickey($keyPair));
    }

    public function test_implements_output_lock_interface(): void
    {
        $lock = new PublicKey($this->validPublicKey);

        self::assertInstanceOf(OutputLock::class, $lock);
    }

    public function test_stores_public_key(): void
    {
        $lock = new PublicKey($this->validPublicKey);

        self::assertSame($this->validPublicKey, $lock->key);
    }

    public function test_throws_on_invalid_base64(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Ed25519 public key');

        new PublicKey('not-valid-base64!!!');
    }

    public function test_throws_on_wrong_key_length(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Ed25519 public key: must be 32-byte key encoded as base64');

        // 16 bytes instead of 32
        new PublicKey(base64_encode(random_bytes(16)));
    }

    public function test_throws_on_empty_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Ed25519 public key');

        new PublicKey('');
    }

    public function test_validate_passes_with_valid_signature(): void
    {
        $lock = new PublicKey($this->validPublicKey);

        $txId = 'tx1';
        $privateKey = $this->decodePrivateKey();
        $signature = base64_encode(sodium_crypto_sign_detached($txId, $privateKey));

        $tx = new Tx(
            id: new TxId($txId),
            spends: [new OutputId('a')],
            outputs: [Output::open(100, 'b')],
            proofs: [$signature],
        );

        $this->expectNotToPerformAssertions();

        $lock->validate($tx, 0);
    }

    public function test_validate_throws_when_missing_proof(): void
    {
        $lock = new PublicKey($this->validPublicKey);

        $tx = new Tx(
            id: new TxId('tx1'),
            spends: [new OutputId('a')],
            outputs: [Output::open(100, 'b')],
            proofs: [],
        );

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Missing authorization proof for spend 0');

        $lock->validate($tx, 0);
    }

    public function test_validate_throws_when_proof_at_wrong_index(): void
    {
        $lock = new PublicKey($this->validPublicKey);

        $txId = 'tx1';
        $privateKey = $this->decodePrivateKey();
        $signature = base64_encode(sodium_crypto_sign_detached($txId, $privateKey));

        $tx = new Tx(
            id: new TxId($txId),
            spends: [new OutputId('a'), new OutputId('b')],
            outputs: [Output::open(100, 'c')],
            proofs: ['', $signature], // proof at index 1, but validating index 0
        );

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Invalid signature for spend 0');

        $lock->validate($tx, 0);
    }

    public function test_validate_throws_with_invalid_signature(): void
    {
        $lock = new PublicKey($this->validPublicKey);

        // Create a signature with different data
        $privateKey = $this->decodePrivateKey();
        $wrongSignature = base64_encode(sodium_crypto_sign_detached('wrong-message', $privateKey));

        $tx = new Tx(
            id: new TxId('tx1'),
            spends: [new OutputId('a')],
            outputs: [Output::open(100, 'b')],
            proofs: [$wrongSignature],
        );

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Invalid signature for spend 0');

        $lock->validate($tx, 0);
    }

    public function test_validate_throws_with_wrong_key_signature(): void
    {
        $lock = new PublicKey($this->validPublicKey);

        // Create a signature with a different key pair
        $otherKeyPair = sodium_crypto_sign_keypair();
        $otherPrivateKey = sodium_crypto_sign_secretkey($otherKeyPair);
        $wrongKeySignature = base64_encode(sodium_crypto_sign_detached('tx1', $otherPrivateKey));

        $tx = new Tx(
            id: new TxId('tx1'),
            spends: [new OutputId('a')],
            outputs: [Output::open(100, 'b')],
            proofs: [$wrongKeySignature],
        );

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Invalid signature for spend 0');

        $lock->validate($tx, 0);
    }

    public function test_validate_throws_with_malformed_signature(): void
    {
        $lock = new PublicKey($this->validPublicKey);

        $tx = new Tx(
            id: new TxId('tx1'),
            spends: [new OutputId('a')],
            outputs: [Output::open(100, 'b')],
            proofs: ['not-a-valid-signature'],
        );

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Invalid signature for spend 0');

        $lock->validate($tx, 0);
    }

    public function test_validate_throws_with_truncated_signature(): void
    {
        $lock = new PublicKey($this->validPublicKey);

        // 32 bytes instead of 64
        $truncatedSignature = base64_encode(random_bytes(32));

        $tx = new Tx(
            id: new TxId('tx1'),
            spends: [new OutputId('a')],
            outputs: [Output::open(100, 'b')],
            proofs: [$truncatedSignature],
        );

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Invalid signature for spend 0');

        $lock->validate($tx, 0);
    }

    public function test_validate_uses_correct_spend_index(): void
    {
        $lock = new PublicKey($this->validPublicKey);

        $txId = 'tx1';
        $privateKey = $this->decodePrivateKey();
        $signature = base64_encode(sodium_crypto_sign_detached($txId, $privateKey));

        $tx = new Tx(
            id: new TxId($txId),
            spends: [new OutputId('a'), new OutputId('b')],
            outputs: [Output::open(100, 'c')],
            proofs: ['placeholder', $signature], // proof at index 1
        );

        $this->expectNotToPerformAssertions();

        $lock->validate($tx, 1);
    }

    public function test_type_returns_pubkey(): void
    {
        $lock = new PublicKey($this->validPublicKey);

        self::assertSame(LockType::PUBLIC_KEY->value, $lock->type());
    }

    public function test_to_array_returns_correct_format(): void
    {
        $lock = new PublicKey($this->validPublicKey);

        $expected = ['type' => 'pubkey', 'key' => $this->validPublicKey];

        self::assertSame($expected, $lock->toArray());
    }

    /**
     * @return non-empty-string
     */
    private function decodePrivateKey(): string
    {
        $decoded = base64_decode($this->validPrivateKey, true);
        \assert($decoded !== false && $decoded !== '');

        return $decoded;
    }
}
