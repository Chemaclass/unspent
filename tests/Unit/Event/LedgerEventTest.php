<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit\Event;

use Chemaclass\Unspent\CoinbaseTx;
use Chemaclass\Unspent\Event\CoinbaseApplied;
use Chemaclass\Unspent\Event\OutputCreated;
use Chemaclass\Unspent\Event\OutputSpent;
use Chemaclass\Unspent\Event\TransactionApplied;
use Chemaclass\Unspent\Event\ValidationFailed;
use Chemaclass\Unspent\Exception\InsufficientSpendsException;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\Tx;
use Chemaclass\Unspent\TxId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LedgerEventTest extends TestCase
{
    #[Test]
    public function transaction_applied_event(): void
    {
        $tx = Tx::create(
            spendIds: ['input-1'],
            outputs: [Output::ownedBy('bob', 90)],
            signedBy: 'alice',
        );

        $event = new TransactionApplied(
            transaction: $tx,
            fee: 10,
            inputTotal: 100,
            outputTotal: 90,
        );

        self::assertSame('transaction.applied', $event->eventType());
        self::assertSame($tx, $event->transaction);
        self::assertSame(10, $event->fee);
        self::assertSame(100, $event->inputTotal);
        self::assertSame(90, $event->outputTotal);
        self::assertGreaterThan(0, $event->timestamp);
    }

    #[Test]
    public function coinbase_applied_event(): void
    {
        $coinbase = CoinbaseTx::create(
            outputs: [Output::ownedBy('miner', 50)],
        );

        $event = new CoinbaseApplied(
            coinbase: $coinbase,
            mintedAmount: 50,
            totalMinted: 100,
        );

        self::assertSame('coinbase.applied', $event->eventType());
        self::assertSame($coinbase, $event->coinbase);
        self::assertSame(50, $event->mintedAmount);
        self::assertSame(100, $event->totalMinted);
    }

    #[Test]
    public function output_created_event(): void
    {
        $output = Output::ownedBy('bob', 100);
        $txId = new TxId('tx-1');

        $event = new OutputCreated(
            output: $output,
            createdBy: $txId,
        );

        self::assertSame('output.created', $event->eventType());
        self::assertSame($output, $event->output);
        self::assertSame($txId, $event->createdBy);
    }

    #[Test]
    public function output_spent_event(): void
    {
        $outputId = new OutputId('output-1');
        $txId = new TxId('tx-1');

        $event = new OutputSpent(
            outputId: $outputId,
            amount: 100,
            spentBy: $txId,
        );

        self::assertSame('output.spent', $event->eventType());
        self::assertSame($outputId, $event->outputId);
        self::assertSame(100, $event->amount);
        self::assertSame($txId, $event->spentBy);
    }

    #[Test]
    public function validation_failed_event(): void
    {
        $tx = Tx::create(
            spendIds: ['input-1'],
            outputs: [Output::ownedBy('bob', 200)],
        );
        $exception = InsufficientSpendsException::create(100, 200);

        $event = new ValidationFailed(
            transaction: $tx,
            exception: $exception,
        );

        self::assertSame('validation.failed', $event->eventType());
        self::assertSame($tx, $event->transaction);
        self::assertSame($exception, $event->exception);
    }

    #[Test]
    public function events_accept_custom_timestamp(): void
    {
        $customTimestamp = 1234567890.123;

        $tx = Tx::create(
            spendIds: ['input-1'],
            outputs: [Output::ownedBy('bob', 90)],
        );

        $event = new TransactionApplied(
            transaction: $tx,
            fee: 10,
            inputTotal: 100,
            outputTotal: 90,
            timestamp: $customTimestamp,
        );

        self::assertSame($customTimestamp, $event->timestamp);
    }
}
