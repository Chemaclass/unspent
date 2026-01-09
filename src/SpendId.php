<?php

declare(strict_types=1);

namespace Chemaclass\Unspent;

use InvalidArgumentException;
use Stringable;

final readonly class SpendId implements Stringable
{
    public function __construct(
        public string $value,
    ) {
        if (trim($value) === '') {
            throw new InvalidArgumentException('SpendId cannot be empty');
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
