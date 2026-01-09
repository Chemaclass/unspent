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
     */
    public static function create(string $id, array $inputIds, array $outputs): self
    {
        return new self(
            id: new SpendId($id),
            inputs: array_map(
                static fn(string $inputId): OutputId => new OutputId($inputId),
                $inputIds,
            ),
            outputs: $outputs,
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
