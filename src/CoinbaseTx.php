<?php

declare(strict_types=1);

namespace Chemaclass\Unspent;

use Chemaclass\Unspent\Exception\DuplicateOutputIdException;
use InvalidArgumentException;

final readonly class CoinbaseTx
{
    /**
     * @param list<Output> $outputs
     */
    public function __construct(
        public TxId $id,
        public array $outputs,
    ) {
        if ($outputs === []) {
            throw new InvalidArgumentException('CoinbaseTx must have at least one output');
        }

        $this->assertNoDuplicateOutputIds();
    }

    /**
     * @param list<Output> $outputs
     */
    public static function create(array $outputs, ?string $id = null): self
    {
        $actualId = $id ?? IdGenerator::forCoinbase($outputs);

        return new self(new TxId($actualId), $outputs);
    }

    public function totalOutputAmount(): int
    {
        return array_sum(array_map(
            static fn (Output $o): int => $o->amount,
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
