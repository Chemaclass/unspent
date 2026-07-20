<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Event;

use Chemaclass\Unspent\CoinbaseTx;

/**
 * Dispatched when a coinbase (minting) transaction is applied.
 */
final readonly class CoinbaseApplied extends LedgerEvent
{
    /**
     * @param int   $totalMinted Ledger-wide running total after this mint (vs. $mintedAmount, this tx's own amount)
     * @param float $timestamp   Unix timestamp with microseconds
     */
    public function __construct(
        public CoinbaseTx $coinbase,
        public int $mintedAmount,
        public int $totalMinted,
        float $timestamp = 0.0,
    ) {
        parent::__construct($timestamp ?: microtime(true));
    }

    public function eventType(): string
    {
        return 'coinbase.applied';
    }
}
