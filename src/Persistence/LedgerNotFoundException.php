<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Persistence;

/**
 * Exception thrown when a requested ledger does not exist.
 */
final class LedgerNotFoundException extends PersistenceException
{
    public static function withId(string $id): self
    {
        return new self("Ledger '{$id}' not found");
    }
}
