<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Exception;

use RuntimeException;

final class DuplicateOutputIdException extends RuntimeException
{
    public static function forId(string $id): self
    {
        return new self(sprintf("Duplicate output id: '%s'", $id));
    }
}
