<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Exception;

/**
 * Thrown when a spend is not authorized to consume an output.
 */
final class AuthorizationException extends UnspentException
{
    public static function notOwner(string $expected, ?string $actual): self
    {
        $actualStr = $actual ?? 'null';

        return new self("Output owned by '{$expected}', but spend authorized by '{$actualStr}'");
    }

    public static function missingProof(int $inputIndex): self
    {
        return new self("Missing authorization proof for input {$inputIndex}");
    }

    public static function invalidSignature(int $inputIndex): self
    {
        return new self("Invalid signature for input {$inputIndex}");
    }
}
