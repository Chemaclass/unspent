<?php

declare(strict_types=1);

namespace Chemaclass\Unspent;

use Countable;
use IteratorAggregate;
use Traversable;
use ArrayIterator;

/**
 * @implements IteratorAggregate<string, Output>
 */
final readonly class UnspentSet implements Countable, IteratorAggregate
{
    /**
     * @param array<string, Output> $outputs
     */
    private function __construct(
        private array $outputs,
    ) {}

    public static function empty(): self
    {
        return new self([]);
    }

    public function add(Output $output): self
    {
        $outputs = $this->outputs;
        $outputs[$output->id->value] = $output;

        return new self($outputs);
    }

    public function remove(OutputId $id): self
    {
        $outputs = $this->outputs;
        unset($outputs[$id->value]);

        return new self($outputs);
    }

    public function contains(OutputId $id): bool
    {
        return isset($this->outputs[$id->value]);
    }

    public function get(OutputId $id): ?Output
    {
        return $this->outputs[$id->value] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->outputs === [];
    }

    public function count(): int
    {
        return count($this->outputs);
    }

    public function totalAmount(): int
    {
        return array_sum(array_map(
            static fn(Output $output): int => $output->amount,
            $this->outputs,
        ));
    }

    /**
     * @return list<OutputId>
     */
    public function outputIds(): array
    {
        return array_map(
            static fn(Output $output): OutputId => $output->id,
            array_values($this->outputs),
        );
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->outputs);
    }
}
