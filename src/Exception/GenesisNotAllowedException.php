<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Exception;

/**
 * Thrown when attempting to create genesis outputs on a non-empty ledger.
 *
 * Error code: 1300
 */
final class GenesisNotAllowedException extends UnspentException
{
    public const int CODE = 1300;

    public static function ledgerNotEmpty(): self
    {
        return new self('Genesis outputs can only be added to an empty ledger', self::CODE);
    }
}
