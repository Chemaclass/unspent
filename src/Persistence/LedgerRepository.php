<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Persistence;

use Chemaclass\Unspent\Ledger;

/**
 * Repository interface for persisting ledgers.
 */
interface LedgerRepository
{
    /**
     * Save a ledger with the given identifier.
     *
     * @throws PersistenceException on storage failure
     */
    public function save(string $id, Ledger $ledger): void;

    /**
     * Find a ledger by identifier.
     *
     * @throws PersistenceException on storage failure
     *
     * @return Ledger|null The ledger if found, null otherwise
     */
    public function find(string $id): ?Ledger;

    /**
     * Delete a ledger by identifier.
     *
     * @throws PersistenceException on storage failure
     */
    public function delete(string $id): void;

    /**
     * Check if a ledger exists.
     */
    public function exists(string $id): bool;
}
