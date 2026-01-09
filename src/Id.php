<?php

declare(strict_types=1);

namespace Chemaclass\Unspent;

use Stringable;

/**
 * Common interface for all identifier value objects.
 *
 * Provides a consistent API for working with IDs generically
 * while concrete types (OutputId, SpendId) maintain type safety.
 */
interface Id extends Stringable
{
    public string $value { get; }
}
