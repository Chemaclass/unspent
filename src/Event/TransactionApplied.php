<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Event;

use Chemaclass\Unspent\Tx;

/**
 * Dispatched when a transaction is successfully applied to the ledger.
 */
final readonly class TransactionApplied extends LedgerEvent
{
    /**
     * @param Tx    $transaction The applied transaction
     * @param int   $fee         Fee paid (difference between inputs and outputs)
     * @param int   $inputTotal  Total amount of spent outputs
     * @param int   $outputTotal Total amount of created outputs
     * @param float $timestamp   Unix timestamp with microseconds
     */
    public function __construct(
        public Tx $transaction,
        public int $fee,
        public int $inputTotal,
        public int $outputTotal,
        float $timestamp = 0.0,
    ) {
        parent::__construct($timestamp ?: microtime(true));
    }

    public function eventType(): string
    {
        return 'transaction.applied';
    }
}
