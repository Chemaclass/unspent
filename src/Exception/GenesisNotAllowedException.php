<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Exception;

use RuntimeException;

final class GenesisNotAllowedException extends RuntimeException
{
    public static function ledgerNotEmpty(): self
    {
        return new self('Genesis outputs can only be added to an empty ledger');
    }
}
