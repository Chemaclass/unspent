<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Exception;

final class InsufficientSpendsException extends UnspentException
{
    public static function create(int $spendAmount, int $outputAmount): self
    {
        return new self(\sprintf(
            'Insufficient spends: spend amount (%d) is less than output amount (%d)',
            $spendAmount,
            $outputAmount,
        ));
    }
}
