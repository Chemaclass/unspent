<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Persistence;

use Chemaclass\Unspent\LedgerInterface;

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
    public function save(string $id, LedgerInterface $ledger): void;

    /**
     * Find a ledger by identifier.
     *
     * @throws PersistenceException on storage failure
     *
     * @return LedgerInterface|null The ledger if found, null otherwise
     */
    public function find(string $id): ?LedgerInterface;

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
