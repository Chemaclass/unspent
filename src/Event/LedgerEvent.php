<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Event;

/**
 * Base class for all ledger events.
 *
 * Events are dispatched after successful operations and can be used
 * for logging, analytics, webhooks, or event sourcing integration.
 */
abstract readonly class LedgerEvent
{
    public function __construct(
        public float $timestamp,
    ) {
    }

    /**
     * Returns a string identifier for the event type.
     */
    abstract public function eventType(): string;
}
