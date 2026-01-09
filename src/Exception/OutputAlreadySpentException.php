<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Exception;

final class OutputAlreadySpentException extends UnspentException
{
    public static function forId(string $id): self
    {
        return new self(\sprintf("Output '%s' is not in the unspent set", $id));
    }
}
