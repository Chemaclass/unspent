<?php

declare(strict_types=1);

namespace Chemaclass\Unspent;

/**
 * Represents the spending status of an output.
 */
enum OutputStatus: string
{
    case UNSPENT = 'unspent';
    case SPENT = 'spent';

    /**
     * Create from a nullable spent-by transaction ID.
     */
    public static function fromSpentBy(?string $spentBy): self
    {
        return $spentBy !== null ? self::SPENT : self::UNSPENT;
    }

    public function isSpent(): bool
    {
        return $this === self::SPENT;
    }

    public function isUnspent(): bool
    {
        return $this === self::UNSPENT;
    }
}
