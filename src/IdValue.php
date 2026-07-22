<?php

declare(strict_types=1);

namespace Chemaclass\Unspent;

use InvalidArgumentException;

/**
 * Shared validation and value semantics for string-based identifier value objects.
 */
trait IdValue
{
    private const int MAX_LENGTH = 64;

    public function __toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    private static function assertValidIdValue(string $value, string $label): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException("{$label} cannot be empty");
        }

        if (\strlen($value) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(
                "{$label} cannot exceed " . self::MAX_LENGTH . ' characters',
            );
        }

        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $value)) {
            throw new InvalidArgumentException(
                "{$label} can only contain alphanumeric characters, dashes, and underscores",
            );
        }
    }
}
