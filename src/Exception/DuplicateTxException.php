<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Exception;

/**
 * Thrown when a transaction ID has already been applied to the ledger.
 *
 * Error code: 1101
 */
final class DuplicateTxException extends UnspentException
{
    public const int CODE = 1101;

    public static function forId(string $id): self
    {
        return new self(\sprintf("Tx '%s' has already been applied", $id), self::CODE);
    }
}
