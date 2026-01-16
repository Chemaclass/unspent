<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Event;

use Chemaclass\Unspent\Exception\UnspentException;
use Chemaclass\Unspent\Tx;

/**
 * Dispatched when transaction validation fails.
 */
final readonly class ValidationFailed extends LedgerEvent
{
    /**
     * @param Tx               $transaction The transaction that failed
     * @param UnspentException $exception   The validation exception
     * @param float            $timestamp   Unix timestamp with microseconds
     */
    public function __construct(
        public Tx $transaction,
        public UnspentException $exception,
        float $timestamp = 0.0,
    ) {
        parent::__construct($timestamp ?: microtime(true));
    }

    public function eventType(): string
    {
        return 'validation.failed';
    }
}
