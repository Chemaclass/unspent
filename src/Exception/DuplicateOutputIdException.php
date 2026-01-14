<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Exception;

/**
 * Thrown when an output ID already exists in the ledger.
 *
 * Error code: 1100
 */
final class DuplicateOutputIdException extends UnspentException
{
    public const int CODE = 1100;

    public static function forId(string $id): self
    {
        return new self(\sprintf("Duplicate output id: '%s'", $id), self::CODE);
    }
}
