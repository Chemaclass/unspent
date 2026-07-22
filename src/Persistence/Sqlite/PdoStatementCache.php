<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Persistence\Sqlite;

use PDOStatement;

/**
 * Caches prepared PDO statements keyed by SQL string.
 *
 * Requires the using class to expose a `private readonly PDO $pdo` property.
 */
trait PdoStatementCache
{
    /** @var array<string, PDOStatement> */
    private array $stmtCache = [];

    private function prepare(string $sql): PDOStatement
    {
        return $this->stmtCache[$sql] ??= $this->pdo->prepare($sql);
    }
}
