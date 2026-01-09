<?php

declare(strict_types=1);

namespace Chemaclass\Unspent;

use InvalidArgumentException;

final readonly class Output
{
    public function __construct(
        public OutputId $id,
        public int $amount,
    ) {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Amount must be positive');
        }
    }

    public static function create(int $amount, ?string $id = null): self
    {
        $actualId = $id ?? IdGenerator::forOutput($amount);

        return new self(new OutputId($actualId), $amount);
    }
}
