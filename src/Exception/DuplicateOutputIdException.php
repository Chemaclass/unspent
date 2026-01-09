<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Exception;

final class DuplicateOutputIdException extends UnspentException
{
    public static function forId(string $id): self
    {
        return new self(\sprintf("Duplicate output id: '%s'", $id));
    }
}
