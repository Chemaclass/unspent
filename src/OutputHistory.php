<?php

declare(strict_types=1);

namespace Chemaclass\Unspent;

/**
 * Represents the complete history of an output.
 *
 * Provides type-safe access to output provenance:
 * - What transaction created it (or 'genesis')
 * - What transaction spent it (if spent)
 * - Current status (spent/unspent)
 */
final readonly class OutputHistory
{
    public function __construct(
        public OutputId $id,
        public int $amount,
        public OutputLock $lock,
        public ?string $createdBy,
        public ?string $spentBy,
        public OutputStatus $status,
    ) {
    }

    /**
     * Create from an Output with provenance information.
     */
    public static function fromOutput(
        Output $output,
        ?string $createdBy,
        ?string $spentBy,
    ): self {
        return new self(
            id: $output->id,
            amount: $output->amount,
            lock: $output->lock,
            createdBy: $createdBy,
            spentBy: $spentBy,
            status: OutputStatus::fromSpentBy($spentBy),
        );
    }

    public function isSpent(): bool
    {
        return $this->status->isSpent();
    }

    public function isUnspent(): bool
    {
        return $this->status->isUnspent();
    }

    public function isGenesis(): bool
    {
        return $this->createdBy === 'genesis';
    }

    /**
     * Convert to array for serialization.
     *
     * @return array{id: string, amount: int, lock: array<string, mixed>, createdBy: string|null, spentBy: string|null, status: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id->value,
            'amount' => $this->amount,
            'lock' => $this->lock->toArray(),
            'createdBy' => $this->createdBy,
            'spentBy' => $this->spentBy,
            'status' => $this->status->value,
        ];
    }
}
