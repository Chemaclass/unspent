<?php

declare(strict_types=1);

namespace Chemaclass\Unspent;

use Chemaclass\Unspent\Exception\DuplicateOutputIdException;
use InvalidArgumentException;

final readonly class Spend
{
    /**
     * @param list<OutputId> $inputs
     * @param list<Output> $outputs
     * @param list<string> $proofs Authorization proofs (signatures, etc.) indexed by input position
     */
    public function __construct(
        public SpendId $id,
        public array $inputs,
        public array $outputs,
        public ?string $authorizedBy = null,
        public array $proofs = [],
    ) {
        if ($inputs === []) {
            throw new InvalidArgumentException('Spend must have at least one input');
        }

        if ($outputs === []) {
            throw new InvalidArgumentException('Spend must have at least one output');
        }

        $this->assertNoDuplicateInputIds();
        $this->assertNoDuplicateOutputIds();
    }

    private function assertNoDuplicateInputIds(): void
    {
        $seen = [];
        foreach ($this->inputs as $inputId) {
            $key = $inputId->value;
            if (isset($seen[$key])) {
                throw new InvalidArgumentException("Duplicate input id: '{$key}'");
            }
            $seen[$key] = true;
        }
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

    /**
     * @param list<string> $inputIds
     * @param list<Output> $outputs
     * @param list<string> $proofs Authorization proofs indexed by input position
     */
    public static function create(
        array $inputIds,
        array $outputs,
        ?string $id = null,
        ?string $authorizedBy = null,
        array $proofs = [],
    ): self {
        $actualId = $id ?? IdGenerator::forSpend($inputIds, $outputs);

        return new self(
            id: new SpendId($actualId),
            inputs: array_map(
                static fn(string $inputId): OutputId => new OutputId($inputId),
                $inputIds,
            ),
            outputs: $outputs,
            authorizedBy: $authorizedBy,
            proofs: $proofs,
        );
    }

    public function totalOutputAmount(): int
    {
        return array_sum(array_map(
            static fn(Output $output): int => $output->amount,
            $this->outputs,
        ));
    }
}
