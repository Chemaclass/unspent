<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Lock;

/**
 * Lock type enumeration for type-safe lock identification.
 *
 * Replaces string literals ('none', 'owner', 'pubkey') with
 * a strongly-typed enum for better IDE support and validation.
 */
enum LockType: string
{
    case NONE = 'none';
    case OWNER = 'owner';
    case PUBLIC_KEY = 'pubkey';

    /**
     * Check if a string is a known built-in lock type.
     */
    public static function isBuiltIn(string $type): bool
    {
        return self::tryFrom($type) !== null;
    }
}
