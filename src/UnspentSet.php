<?php

declare(strict_types=1);

namespace Chemaclass\Unspent;

use ArrayIterator;
use Chemaclass\Unspent\Lock\LockFactory;
use Countable;
use IteratorAggregate;
use Traversable;

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
        private int $cachedTotal,
    ) {
    }

    public static function empty(): self
    {
        return new self([], 0);
    }

    public static function fromOutputs(Output ...$outputs): self
    {
        return self::empty()->addAll(...$outputs);
    }

    public function add(Output $output): self
    {
        $key = $output->id->value;
        $outputs = $this->outputs;
        $delta = $output->amount;

        if (isset($outputs[$key])) {
            $delta -= $outputs[$key]->amount;
        }

        $outputs[$key] = $output;

        return new self($outputs, $this->cachedTotal + $delta);
    }

    public function addAll(Output ...$outputs): self
    {
        if ($outputs === []) {
            return $this;
        }

        $newOutputs = $this->outputs;
        $newTotal = $this->cachedTotal;

        foreach ($outputs as $output) {
            $key = $output->id->value;
            if (isset($newOutputs[$key])) {
                $newTotal -= $newOutputs[$key]->amount;
            }
            $newOutputs[$key] = $output;
            $newTotal += $output->amount;
        }

        return new self($newOutputs, $newTotal);
    }

    public function remove(OutputId $id): self
    {
        $key = $id->value;
        if (!isset($this->outputs[$key])) {
            return $this;
        }

        $outputs = $this->outputs;
        $removedAmount = $outputs[$key]->amount;
        unset($outputs[$key]);

        return new self($outputs, $this->cachedTotal - $removedAmount);
    }

    public function removeAll(OutputId ...$ids): self
    {
        if ($ids === []) {
            return $this;
        }

        $outputs = $this->outputs;
        $newTotal = $this->cachedTotal;

        foreach ($ids as $id) {
            $key = $id->value;
            if (isset($outputs[$key])) {
                $newTotal -= $outputs[$key]->amount;
                unset($outputs[$key]);
            }
        }

        return new self($outputs, $newTotal);
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
        return \count($this->outputs);
    }

    public function totalAmount(): int
    {
        return $this->cachedTotal;
    }

    /**
     * @return list<OutputId>
     */
    public function outputIds(): array
    {
        return array_map(
            static fn (Output $output): OutputId => $output->id,
            array_values($this->outputs),
        );
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->outputs);
    }

    /**
     * Serializes the unspent set to an array format.
     *
     * @return list<array{id: string, amount: int, lock: array{type: string, ...}}>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (Output $o): array => [
                'id' => $o->id->value,
                'amount' => $o->amount,
                'lock' => $o->lock->toArray(),
            ],
            array_values($this->outputs),
        );
    }

    /**
     * Creates an UnspentSet from a serialized array.
     *
     * @param list<array{id: string, amount: int, lock?: array<string, mixed>}> $data
     */
    public static function fromArray(array $data): self
    {
        $outputs = array_map(
            static fn (array $item): Output => new Output(
                new OutputId($item['id']),
                $item['amount'],
                isset($item['lock']) ? LockFactory::fromArray($item['lock']) : new Lock\NoLock(),
            ),
            $data,
        );

        return self::fromOutputs(...$outputs);
    }
}
