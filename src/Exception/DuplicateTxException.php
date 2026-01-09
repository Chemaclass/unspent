<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Exception;

final class DuplicateTxException extends UnspentException
{
    public static function forId(string $id): self
    {
        return new self(\sprintf("Tx '%s' has already been applied", $id));
    }
}
