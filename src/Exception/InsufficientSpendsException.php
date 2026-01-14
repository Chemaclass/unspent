<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Exception;

/**
 * Thrown when the total spend amount is less than the total output amount.
 *
 * Error code: 1201
 */
final class InsufficientSpendsException extends UnspentException
{
    public const int CODE = 1201;

    public static function create(int $spendAmount, int $outputAmount): self
    {
        return new self(
            \sprintf(
                'Insufficient spends: spend amount (%d) is less than output amount (%d)',
                $spendAmount,
                $outputAmount,
            ),
            self::CODE,
        );
    }
}
