<?php

declare(strict_types=1);

namespace Chemaclass\Unspent;

use Stringable;

final readonly class OutputId implements Stringable
{
    public function __construct(
        public string $value,
    ) {}

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
