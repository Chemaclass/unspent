<?php

declare(strict_types=1);

namespace Chemaclass\Unspent;

use Chemaclass\Unspent\Validation\DuplicateValidator;
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

        DuplicateValidator::assertNoDuplicateOutputIds($outputs);
    }

    /**
     * @param list<Output> $outputs
     */
    public static function create(array $outputs, ?string $id = null): self
    {
        return new self(
            id: new TxId($id ?? IdGenerator::forCoinbase($outputs)),
            outputs: $outputs,
        );
    }

    public function totalOutputAmount(): int
    {
        return array_sum(array_map(
            static fn (Output $o): int => $o->amount,
            $this->outputs,
        ));
    }
}
