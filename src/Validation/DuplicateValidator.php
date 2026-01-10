<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Validation;

use Chemaclass\Unspent\Exception\DuplicateOutputIdException;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use InvalidArgumentException;

/**
 * Utility class for detecting duplicate IDs in collections.
 *
 * Extracted from Tx, CoinbaseTx, and Ledger to eliminate code duplication (DRY).
 */
final class DuplicateValidator
{
    /**
     * Assert that no duplicate input IDs exist.
     *
     * @param list<OutputId> $inputs
     *
     * @throws InvalidArgumentException If duplicate input ID found
     */
    public static function assertNoDuplicateInputIds(array $inputs): void
    {
        $seen = [];
        foreach ($inputs as $inputId) {
            $key = $inputId->value;
            if (isset($seen[$key])) {
                throw new InvalidArgumentException("Duplicate input id: '{$key}'");
            }
            $seen[$key] = true;
        }
    }

    /**
     * Assert that no duplicate output IDs exist.
     *
     * @param list<Output> $outputs
     *
     * @throws DuplicateOutputIdException If duplicate output ID found
     */
    public static function assertNoDuplicateOutputIds(array $outputs): void
    {
        $seen = [];
        foreach ($outputs as $output) {
            $key = $output->id->value;
            if (isset($seen[$key])) {
                throw DuplicateOutputIdException::forId($key);
            }
            $seen[$key] = true;
        }
    }
}
