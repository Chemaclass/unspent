<?php

declare(strict_types=1);

namespace Chemaclass\Unspent;

/**
 * Interface for output locking mechanisms.
 *
 * Locks determine who can spend an output. Implementations can range from
 * no restrictions (NoLock) to cryptographic verification (SignatureLock).
 */
interface OutputLock
{
    /**
     * Validates that the transaction is authorized to consume this output.
     *
     * @param Tx  $tx         The transaction attempting to consume the output
     * @param int $spendIndex Which spend in the tx references this output
     *
     * @throws Exception\AuthorizationException If not authorized
     */
    public function validate(Tx $tx, int $spendIndex): void;

    /**
     * Serializes the lock for persistence.
     *
     * @return array{type: string, name?:string, ...}
     */
    public function toArray(): array;
}
