<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Event;

use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\TxId;

/**
 * Dispatched when a new output is created.
 */
final readonly class OutputCreated extends LedgerEvent
{
    /**
     * @param float $timestamp Unix timestamp with microseconds
     */
    public function __construct(
        public Output $output,
        public TxId $createdBy,
        float $timestamp = 0.0,
    ) {
        parent::__construct($timestamp ?: microtime(true));
    }

    public function eventType(): string
    {
        return 'output.created';
    }
}
