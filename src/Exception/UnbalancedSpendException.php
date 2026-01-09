<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Exception;

use RuntimeException;

final class UnbalancedSpendException extends RuntimeException
{
    public static function create(int $inputAmount, int $outputAmount): self
    {
        return new self(sprintf(
            'Spend is unbalanced: input amount (%d) does not equal output amount (%d)',
            $inputAmount,
            $outputAmount,
        ));
    }
}
