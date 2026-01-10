<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Lock;

use Chemaclass\Unspent\OutputLock;
use Chemaclass\Unspent\Tx;

/**
 * A lock that allows anyone to spend the output.
 *
 * This is the default lock, providing backwards compatibility
 * with outputs that don't specify ownership restrictions.
 */
final readonly class NoLock implements OutputLock
{
    public function validate(Tx $tx, int $spendIndex): void
    {
        // No restrictions - anyone can spend
    }

    /**
     * @return array{type: string}
     */
    public function toArray(): array
    {
        return ['type' => LockType::NONE->value];
    }
}
