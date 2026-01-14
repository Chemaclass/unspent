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
 * Optimized unspent output collection with copy-on-fork semantics.
 *
 * Performance optimization: Instead of copying the entire array on every
 * mutation, we track "ownership" of the internal array. Mutations happen
 * in-place when we own the array, and copy only when forking from shared state.
 *
 * @implements IteratorAggregate<string, Output>
 */
final class UnspentSet implements Countable, IteratorAggregate
{
    /**
     * Whether this instance owns its array and can mutate it.
     * Set to false when the set is exposed externally (shared).
     */
    private bool $owned = true;

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

    /**
     * Mark this set as shared (not owned). Future mutations will copy.
     *
     * @internal called when exposing the set externally
     */
    public function release(): self
    {
        $this->owned = false;

        return $this;
    }

    public function add(Output $output): self
    {
        $key = $output->id->value;
        $delta = $output->amount;

        if (isset($this->outputs[$key])) {
            $delta -= $this->outputs[$key]->amount;
        }

        if ($this->owned) {
            // Mutate in place - we own this array
            $this->outputs[$key] = $output;
            $this->cachedTotal += $delta;

            return $this;
        }

        // Fork - create a copy since we don't own the array
        $newOutputs = $this->outputs;
        $newOutputs[$key] = $output;

        return new self($newOutputs, $this->cachedTotal + $delta);
    }

    public function addAll(Output ...$outputs): self
    {
        if ($outputs === []) {
            return $this;
        }

        if ($this->owned) {
            // Mutate in place
            foreach ($outputs as $output) {
                $key = $output->id->value;
                if (isset($this->outputs[$key])) {
                    $this->cachedTotal -= $this->outputs[$key]->amount;
                }
                $this->outputs[$key] = $output;
                $this->cachedTotal += $output->amount;
            }

            return $this;
        }

        // Fork
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

        $removedAmount = $this->outputs[$key]->amount;

        if ($this->owned) {
            // Mutate in place
            unset($this->outputs[$key]);
            $this->cachedTotal -= $removedAmount;

            return $this;
        }

        // Fork
        $newOutputs = $this->outputs;
        unset($newOutputs[$key]);

        return new self($newOutputs, $this->cachedTotal - $removedAmount);
    }

    public function removeAll(OutputId ...$ids): self
    {
        if ($ids === []) {
            return $this;
        }

        if ($this->owned) {
            // Mutate in place
            foreach ($ids as $id) {
                $key = $id->value;
                if (isset($this->outputs[$key])) {
                    $this->cachedTotal -= $this->outputs[$key]->amount;
                    unset($this->outputs[$key]);
                }
            }

            return $this;
        }

        // Fork
        $newOutputs = $this->outputs;
        $newTotal = $this->cachedTotal;

        foreach ($ids as $id) {
            $key = $id->value;
            if (isset($newOutputs[$key])) {
                $newTotal -= $newOutputs[$key]->amount;
                unset($newOutputs[$key]);
            }
        }

        return new self($newOutputs, $newTotal);
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
