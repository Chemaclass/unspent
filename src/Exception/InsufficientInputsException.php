<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Exception;

final class InsufficientInputsException extends UnspentException
{
    public static function create(int $inputAmount, int $outputAmount): self
    {
        return new self(sprintf(
            'Insufficient inputs: input amount (%d) is less than output amount (%d)',
            $inputAmount,
            $outputAmount,
        ));
    }
}
