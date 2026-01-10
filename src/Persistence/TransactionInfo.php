<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Persistence;

/**
 * Represents transaction fee information.
 *
 * Used by repository queries to return structured transaction data
 * instead of raw arrays.
 */
final readonly class TransactionInfo
{
    public function __construct(
        public string $id,
        public int $fee,
    ) {
    }

    /**
     * Create from a database row.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: $row['id'],
            fee: (int) $row['fee'],
        );
    }

    /**
     * Convert to array for backward compatibility.
     *
     * @return array{id: string, fee: int}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'fee' => $this->fee,
        ];
    }
}
