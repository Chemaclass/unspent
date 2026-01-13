<?php

declare(strict_types=1);

namespace Chemaclass\Unspent;

use ArrayIterator;
use Chemaclass\Unspent\Lock\LockFactory;
use Chemaclass\Unspent\Lock\Owner;
use Countable;
use InvalidArgumentException;
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
        return array_values(array_map(
            static fn (Output $output): OutputId => $output->id,
            $this->outputs,
        ));
    }

    /**
     * Returns a new UnspentSet containing only outputs matching the predicate.
     *
     * @param callable(Output): bool $predicate
     */
    public function filter(callable $predicate): self
    {
        $filtered = array_filter($this->outputs, $predicate);
        $total = array_sum(array_map(static fn (Output $o): int => $o->amount, $filtered));

        return new self($filtered, $total);
    }

    /**
     * Returns all outputs owned by a specific owner.
     *
     * Only returns outputs with an Owner lock matching the given name.
     * For database-level queries, prefer QueryableLedgerRepository::findUnspentByOwner().
     */
    public function ownedBy(string $owner): self
    {
        return $this->filter(
            static fn (Output $o): bool => $o->lock instanceof Owner && $o->lock->name === $owner,
        );
    }

    /**
     * Returns total amount owned by a specific owner.
     *
     * For database-level queries, prefer QueryableLedgerRepository::sumUnspentByOwner().
     */
    public function totalAmountOwnedBy(string $owner): int
    {
        return $this->ownedBy($owner)->totalAmount();
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->outputs);
    }

    /**
     * Serializes the unspent set to an array format.
     *
     * @return array<string, array{amount: int, lock: array{type: string, ...}}>
     */
    public function toArray(): array
    {
        $result = [];
        foreach ($this->outputs as $output) {
            $result[$output->id->value] = [
                'amount' => $output->amount,
                'lock' => $output->lock->toArray(),
            ];
        }

        return $result;
    }

    /**
     * Creates an UnspentSet from a serialized array.
     *
     * @param array<string, array{amount: int, lock?: array<string, mixed>}> $data
     */
    public static function fromArray(array $data): self
    {
        $outputs = [];
        foreach ($data as $id => $item) {
            if (!isset($item['lock'])) {
                throw new InvalidArgumentException(
                    "Output '{$id}' missing lock data - cannot deserialize safely",
                );
            }

            $outputs[] = new Output(
                new OutputId((string) $id),
                $item['amount'],
                LockFactory::fromArray($item['lock']),
            );
        }

        return self::fromOutputs(...$outputs);
    }
}
