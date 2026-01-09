<?php

declare(strict_types=1);

namespace Chemaclass\Unspent;

use Chemaclass\Unspent\Lock\NoLock;
use InvalidArgumentException;

final readonly class Output
{
    public function __construct(
        public OutputId $id,
        public int $amount,
        public OutputLock $lock = new NoLock(),
    ) {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Amount must be positive');
        }
    }

    public static function create(
        int $amount,
        ?string $id = null,
        ?OutputLock $lock = null,
    ): self {
        $actualId = $id ?? IdGenerator::forOutput($amount);

        return new self(
            new OutputId($actualId),
            $amount,
            $lock ?? new NoLock(),
        );
    }
}
