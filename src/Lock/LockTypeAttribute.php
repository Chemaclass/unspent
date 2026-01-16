<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Lock;

use Attribute;

/**
 * Marks a class as a lock type for auto-discovery.
 *
 * Usage:
 *     #[LockTypeAttribute('timelock')]
 *     final readonly class TimeLock implements OutputLock { ... }
 *
 * Then register via:
 *     LockFactory::discoverInDirectory(__DIR__ . '/Locks');
 *     // or
 *     LockFactory::registerFromClass(TimeLock::class);
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class LockTypeAttribute
{
    public function __construct(
        public string $type,
    ) {
    }
}
