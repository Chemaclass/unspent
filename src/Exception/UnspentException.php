<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Exception;

use RuntimeException;

/**
 * Base exception for all domain exceptions in the Unspent library.
 *
 * Consumers can catch this single type to handle all domain errors.
 */
abstract class UnspentException extends RuntimeException
{
}
