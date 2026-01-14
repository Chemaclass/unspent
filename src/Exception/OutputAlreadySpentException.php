<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Exception;

/**
 * Thrown when attempting to spend an output that has already been spent.
 *
 * Error code: 1200
 */
final class OutputAlreadySpentException extends UnspentException
{
    public const int CODE = 1200;

    public static function forId(string $id): self
    {
        return new self(\sprintf("Output '%s' is not in the unspent set", $id), self::CODE);
    }
}
