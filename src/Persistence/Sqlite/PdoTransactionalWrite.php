<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Persistence\Sqlite;

use Chemaclass\Unspent\Persistence\PersistenceException;
use PDOException;

/**
 * Runs a write operation inside a PDO transaction, rolling back and
 * translating failures into PersistenceException.
 *
 * Requires the using class to expose a `private readonly PDO $pdo` property.
 */
trait PdoTransactionalWrite
{
    /**
     * @param callable(): void $operation
     */
    private function runInTransaction(string $ledgerId, callable $operation): void
    {
        try {
            $this->pdo->beginTransaction();
            $operation();
            $this->pdo->commit();
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            throw PersistenceException::saveFailed($ledgerId, $e->getMessage());
        }
    }
}
