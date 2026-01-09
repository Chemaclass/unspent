<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Exception;

final class GenesisNotAllowedException extends UnspentException
{
    public static function ledgerNotEmpty(): self
    {
        return new self('Genesis outputs can only be added to an empty ledger');
    }
}
