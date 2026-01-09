<?php

declare(strict_types=1);

namespace Chemaclass\Unspent;

use Chemaclass\Unspent\Exception\DuplicateOutputIdException;
use InvalidArgumentException;

final readonly class Coinbase
{
    /** @param list<Output> $outputs */
    public function __construct(
        public SpendId $id,
        public array $outputs,
    ) {
        if ($outputs === []) {
            throw new InvalidArgumentException('Coinbase must have at least one output');
        }

        $this->assertNoDuplicateOutputIds();
    }

    /** @param list<Output> $outputs */
    public static function create(string $id, array $outputs): self
    {
        return new self(new SpendId($id), $outputs);
    }

    public function totalOutputAmount(): int
    {
        return array_sum(array_map(
            static fn(Output $o): int => $o->amount,
            $this->outputs,
        ));
    }

    private function assertNoDuplicateOutputIds(): void
    {
        $seen = [];
        foreach ($this->outputs as $output) {
            $key = $output->id->value;
            if (isset($seen[$key])) {
                throw DuplicateOutputIdException::forId($key);
            }
            $seen[$key] = true;
        }
    }
}
