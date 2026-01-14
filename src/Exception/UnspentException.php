<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Exception;

use RuntimeException;

/**
 * Base exception for all domain exceptions in the Unspent library.
 *
 * Consumers can catch this single type to handle all domain errors.
 *
 * Error code ranges:
 * - 1000-1099: Authorization errors
 * - 1100-1199: Duplicate/conflict errors
 * - 1200-1299: Spending errors
 * - 1300-1399: Genesis/initialization errors
 */
abstract class UnspentException extends RuntimeException
{
}
