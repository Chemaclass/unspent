<?php

declare(strict_types=1);

namespace Chemaclass\Unspent;

use InvalidArgumentException;

final readonly class Spend
{
    /**
     * @param list<OutputId> $inputs
     * @param list<Output> $outputs
     */
    public function __construct(
        public SpendId $id,
        public array $inputs,
        public array $outputs,
    ) {
        if ($inputs === []) {
            throw new InvalidArgumentException('Spend must have at least one input');
        }

        if ($outputs === []) {
            throw new InvalidArgumentException('Spend must have at least one output');
        }
    }

    public function totalOutputAmount(): int
    {
        return array_sum(array_map(
            static fn(Output $output): int => $output->amount,
            $this->outputs,
        ));
    }
}
