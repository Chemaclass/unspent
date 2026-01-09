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

    public static function create(string $id, int $amount): self
    {
        return new self(new OutputId($id), $amount);
    }
}
