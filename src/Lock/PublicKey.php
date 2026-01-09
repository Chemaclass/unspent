<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Lock;

use Chemaclass\Unspent\Exception\AuthorizationException;
use Chemaclass\Unspent\OutputLock;
use Chemaclass\Unspent\Spend;

/**
 * A lock that requires Ed25519 signature verification.
 *
 * The spend must provide a valid signature in `proofs` at the same index as the input.
 * The message to sign is the spend ID.
 */
final readonly class PublicKey implements OutputLock
{
    public function __construct(
        public string $key,
    ) {}

    public function validate(Spend $spend, int $inputIndex): void
    {
        $signature = $spend->proofs[$inputIndex] ?? null;
        if ($signature === null) {
            throw AuthorizationException::missingProof($inputIndex);
        }

        $message = $spend->id->value;

        if (!$this->verifySignature($signature, $message)) {
            throw AuthorizationException::invalidSignature($inputIndex);
        }
    }

    private function verifySignature(string $signature, string $message): bool
    {
        $decodedSignature = base64_decode($signature, true);
        $decodedKey = base64_decode($this->key, true);

        if ($decodedSignature === false || $decodedSignature === '') {
            return false;
        }

        if ($decodedKey === false || $decodedKey === '') {
            return false;
        }

        return sodium_crypto_sign_verify_detached($decodedSignature, $message, $decodedKey);
    }

    /**
     * @return array{type: string, key: string}
     */
    public function toArray(): array
    {
        return ['type' => 'pubkey', 'key' => $this->key];
    }
}
