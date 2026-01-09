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
     * Validates that the spend is authorized to consume this output.
     *
     * @param Spend $spend      The spend attempting to consume the output
     * @param int   $inputIndex Which input in the spend references this output
     *
     * @throws Exception\AuthorizationException If not authorized
     */
    public function validate(Spend $spend, int $inputIndex): void;

    /**
     * Serializes the lock for persistence.
     *
     * @return array{type: string, ...}
     */
    public function toArray(): array;
}
