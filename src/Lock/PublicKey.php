<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Lock;

use Chemaclass\Unspent\Exception\AuthorizationException;
use Chemaclass\Unspent\OutputLock;
use Chemaclass\Unspent\Tx;
use InvalidArgumentException;

/**
 * A lock that requires Ed25519 signature verification.
 *
 * The tx must provide a valid signature in `proofs` at the same index as the spend.
 * The message to sign is the tx ID.
 */
final readonly class PublicKey implements OutputLock
{
    public function __construct(
        public string $key,
    ) {
        $decoded = base64_decode($key, true);
        if ($decoded === false || \strlen($decoded) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new InvalidArgumentException(
                'Invalid Ed25519 public key: must be 32-byte key encoded as base64',
            );
        }
    }

    public function validate(Tx $tx, int $spendIndex): void
    {
        $signature = $tx->proofs[$spendIndex] ?? null;
        if ($signature === null) {
            throw AuthorizationException::missingProof($spendIndex);
        }

        $message = $tx->id->value;

        if (!$this->verifySignature($signature, $message)) {
            throw AuthorizationException::invalidSignature($spendIndex);
        }
    }

    /**
     * @return array{type: string, key: string}
     */
    public function toArray(): array
    {
        return ['type' => LockType::PUBLIC_KEY->value, 'key' => $this->key];
    }

    private function verifySignature(string $signature, string $message): bool
    {
        $decodedSignature = base64_decode($signature, true);
        $decodedKey = base64_decode($this->key, true);

        // Explicit length validation (Ed25519 signature = 64 bytes)
        if ($decodedSignature === false || \strlen($decodedSignature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        // Should never fail if constructor validates, but defense in depth
        if ($decodedKey === false || \strlen($decodedKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($decodedSignature, $message, $decodedKey);
    }
}
