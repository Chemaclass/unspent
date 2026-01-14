<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Exception;

/**
 * Thrown when a spend is not authorized to consume an output.
 *
 * Error codes:
 * - 1000: Not owner
 * - 1001: Missing proof
 * - 1002: Invalid signature
 */
final class AuthorizationException extends UnspentException
{
    public const int CODE_NOT_OWNER = 1000;
    public const int CODE_MISSING_PROOF = 1001;
    public const int CODE_INVALID_SIGNATURE = 1002;

    public static function notOwner(string $expected, ?string $actual): self
    {
        $actualStr = $actual ?? 'null';

        return new self(
            "Output owned by '{$expected}', but spend signed by '{$actualStr}'",
            self::CODE_NOT_OWNER,
        );
    }

    public static function missingProof(int $spendIndex): self
    {
        return new self(
            "Missing authorization proof for spend {$spendIndex}",
            self::CODE_MISSING_PROOF,
        );
    }

    public static function invalidSignature(int $spendIndex): self
    {
        return new self(
            "Invalid signature for spend {$spendIndex}",
            self::CODE_INVALID_SIGNATURE,
        );
    }
}
