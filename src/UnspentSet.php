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
 * A secondary owner index (owner name => set of owned output ids) is maintained
 * incrementally so owner-scoped lookups cost O(outputs-owned-by-owner) instead
 * of O(total outputs).
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
     * @param array<string, Output>              $outputs    Output id => Output
     * @param array<string, array<string, true>> $ownerIndex Owner name => set of owned output ids
     */
    private function __construct(
        private array $outputs,
        private int $cachedTotal,
        private array $ownerIndex,
    ) {
    }

    public static function empty(): self
    {
        return new self([], 0, []);
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

    /**
     * Returns an isolated snapshot of the current outputs for external reads.
     *
     * The snapshot shares the underlying array copy-on-write and is marked as
     * shared, so mutating it forks instead of touching this set. This set stays
     * owned, so the ledger's own writes remain in place — reading via
     * Ledger::unspent() does not force a full copy on the next write.
     */
    public function snapshot(): self
    {
        return new self($this->outputs, $this->cachedTotal, $this->ownerIndex)->release();
    }

    public function add(Output $output): self
    {
        $key = $output->id->value;
        $delta = $output->amount;
        $existing = $this->outputs[$key] ?? null;
        if ($existing !== null) {
            $delta -= $existing->amount;
        }

        if ($this->owned) {
            if ($existing !== null) {
                $this->unindexOwner($this->ownerIndex, $existing);
            }
            $this->outputs[$key] = $output;
            $this->cachedTotal += $delta;
            $this->indexOwner($this->ownerIndex, $output);

            return $this;
        }

        // Fork - create a copy since we don't own the array
        $newOutputs = $this->outputs;
        $newIndex = $this->ownerIndex;
        if ($existing !== null) {
            $this->unindexOwner($newIndex, $existing);
        }
        $newOutputs[$key] = $output;
        $this->indexOwner($newIndex, $output);

        return new self($newOutputs, $this->cachedTotal + $delta, $newIndex);
    }

    public function addAll(Output ...$outputs): self
    {
        if ($outputs === []) {
            return $this;
        }

        if ($this->owned) {
            foreach ($outputs as $output) {
                $key = $output->id->value;
                $existing = $this->outputs[$key] ?? null;
                if ($existing !== null) {
                    $this->cachedTotal -= $existing->amount;
                    $this->unindexOwner($this->ownerIndex, $existing);
                }
                $this->outputs[$key] = $output;
                $this->cachedTotal += $output->amount;
                $this->indexOwner($this->ownerIndex, $output);
            }

            return $this;
        }

        // Fork
        $newOutputs = $this->outputs;
        $newTotal = $this->cachedTotal;
        $newIndex = $this->ownerIndex;
        foreach ($outputs as $output) {
            $key = $output->id->value;
            $existing = $newOutputs[$key] ?? null;
            if ($existing !== null) {
                $newTotal -= $existing->amount;
                $this->unindexOwner($newIndex, $existing);
            }
            $newOutputs[$key] = $output;
            $newTotal += $output->amount;
            $this->indexOwner($newIndex, $output);
        }

        return new self($newOutputs, $newTotal, $newIndex);
    }

    public function remove(OutputId $id): self
    {
        $key = $id->value;
        $existing = $this->outputs[$key] ?? null;
        if ($existing === null) {
            return $this;
        }

        if ($this->owned) {
            unset($this->outputs[$key]);
            $this->cachedTotal -= $existing->amount;
            $this->unindexOwner($this->ownerIndex, $existing);

            return $this;
        }

        // Fork
        $newOutputs = $this->outputs;
        $newIndex = $this->ownerIndex;
        unset($newOutputs[$key]);
        $this->unindexOwner($newIndex, $existing);

        return new self($newOutputs, $this->cachedTotal - $existing->amount, $newIndex);
    }

    public function removeAll(OutputId ...$ids): self
    {
        if ($ids === []) {
            return $this;
        }

        if ($this->owned) {
            foreach ($ids as $id) {
                $key = $id->value;
                $existing = $this->outputs[$key] ?? null;
                if ($existing !== null) {
                    $this->cachedTotal -= $existing->amount;
                    unset($this->outputs[$key]);
                    $this->unindexOwner($this->ownerIndex, $existing);
                }
            }

            return $this;
        }

        // Fork
        $newOutputs = $this->outputs;
        $newTotal = $this->cachedTotal;
        $newIndex = $this->ownerIndex;
        foreach ($ids as $id) {
            $key = $id->value;
            $existing = $newOutputs[$key] ?? null;
            if ($existing !== null) {
                $newTotal -= $existing->amount;
                unset($newOutputs[$key]);
                $this->unindexOwner($newIndex, $existing);
            }
        }

        return new self($newOutputs, $newTotal, $newIndex);
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
        $total = 0;
        $index = [];
        foreach ($filtered as $output) {
            $total += $output->amount;
            $this->indexOwner($index, $output);
        }

        return new self($filtered, $total, $index);
    }

    /**
     * Returns all outputs owned by a specific owner.
     *
     * Only returns outputs with an Owner lock matching the given name. Resolved
     * through the owner index in O(outputs-owned-by-owner).
     * For database-level queries, prefer QueryableLedgerRepository::findUnspentByOwner().
     */
    public function ownedBy(string $owner): self
    {
        $ids = $this->ownerIndex[$owner] ?? [];
        if ($ids === []) {
            return self::empty();
        }

        $outputs = [];
        $total = 0;
        foreach (array_keys($ids) as $id) {
            $output = $this->outputs[$id];
            $outputs[$id] = $output;
            $total += $output->amount;
        }

        return new self($outputs, $total, [$owner => $ids]);
    }

    /**
     * Returns total amount owned by a specific owner.
     *
     * Resolved through the owner index without allocating an intermediate set.
     * For database-level queries, prefer QueryableLedgerRepository::sumUnspentByOwner().
     */
    public function totalAmountOwnedBy(string $owner): int
    {
        $total = 0;
        foreach (array_keys($this->ownerIndex[$owner] ?? []) as $id) {
            $total += $this->outputs[$id]->amount;
        }

        return $total;
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

    /**
     * @param array<string, array<string, true>> $index
     */
    private function indexOwner(array &$index, Output $output): void
    {
        if ($output->lock instanceof Owner) {
            $index[$output->lock->name][$output->id->value] = true;
        }
    }

    /**
     * @param array<string, array<string, true>> $index
     */
    private function unindexOwner(array &$index, Output $output): void
    {
        if (!$output->lock instanceof Owner) {
            return;
        }

        $name = $output->lock->name;
        unset($index[$name][$output->id->value]);
        if (($index[$name] ?? []) === []) {
            unset($index[$name]);
        }
    }
}
