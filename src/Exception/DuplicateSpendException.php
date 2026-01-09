<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Exception;

use RuntimeException;

final class DuplicateSpendException extends RuntimeException
{
    public static function forId(string $id): self
    {
        return new self(sprintf("Spend '%s' has already been applied", $id));
    }
}
