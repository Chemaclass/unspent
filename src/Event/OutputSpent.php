<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Event;

use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\TxId;

/**
 * Dispatched when an output is spent (consumed).
 */
final readonly class OutputSpent extends LedgerEvent
{
    /**
     * @param OutputId $outputId  The spent output ID
     * @param int      $amount    Amount that was spent
     * @param TxId     $spentBy   Transaction that spent this output
     * @param float    $timestamp Unix timestamp with microseconds
     */
    public function __construct(
        public OutputId $outputId,
        public int $amount,
        public TxId $spentBy,
        float $timestamp = 0.0,
    ) {
        parent::__construct($timestamp ?: microtime(true));
    }

    public function eventType(): string
    {
        return 'output.spent';
    }
}
