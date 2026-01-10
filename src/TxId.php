<?php

declare(strict_types=1);

namespace Chemaclass\Unspent;

use InvalidArgumentException;

final readonly class TxId implements Id
{
    private const int MAX_LENGTH = 64;

    public function __construct(
        public string $value,
    ) {
        if (trim($value) === '') {
            throw new InvalidArgumentException('TxId cannot be empty');
        }

        if (\strlen($value) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(
                'TxId cannot exceed ' . self::MAX_LENGTH . ' characters',
            );
        }

        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $value)) {
            throw new InvalidArgumentException(
                'TxId can only contain alphanumeric characters, dashes, and underscores',
            );
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
