<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Persistence;

use Chemaclass\Unspent\Exception\UnspentException;

/**
 * Base exception for persistence layer errors.
 */
class PersistenceException extends UnspentException
{
    public static function saveFailed(string $id, string $reason): self
    {
        return new self("Failed to save ledger '{$id}': {$reason}");
    }

    public static function findFailed(string $id, string $reason): self
    {
        return new self("Failed to find ledger '{$id}': {$reason}");
    }

    public static function deleteFailed(string $id, string $reason): self
    {
        return new self("Failed to delete ledger '{$id}': {$reason}");
    }

    public static function queryFailed(string $reason): self
    {
        return new self("Query failed: {$reason}");
    }
}
