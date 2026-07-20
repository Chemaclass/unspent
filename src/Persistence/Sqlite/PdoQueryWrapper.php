<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Persistence\Sqlite;

use Chemaclass\Unspent\Persistence\PersistenceException;
use PDOException;

/**
 * Runs a read query and translates PDO failures into PersistenceException.
 *
 * Extracted from SqliteHistoryRepository and SqliteLedgerRepository, which
 * both repeated the same try/catch(PDOException)->queryFailed() shape
 * across every query method (DRY).
 */
trait PdoQueryWrapper
{
    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function tryQuery(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (PDOException $e) {
            throw PersistenceException::queryFailed($e->getMessage());
        }
    }
}
