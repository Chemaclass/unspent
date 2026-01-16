<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit\Logging;

use Chemaclass\Unspent\CoinbaseTx;
use Chemaclass\Unspent\Exception\DuplicateTxException;
use Chemaclass\Unspent\Exception\InsufficientSpendsException;
use Chemaclass\Unspent\Exception\OutputAlreadySpentException;
use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Logging\LoggingLedger;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\Tx;
use Chemaclass\Unspent\TxId;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class LoggingLedgerTest extends TestCase
{
    public function test_wrap_creates_logging_ledger(): void
    {
        $ledger = Ledger::inMemory();
        $logger = $this->createMock(LoggerInterface::class);

        $loggingLedger = LoggingLedger::wrap($ledger, $logger);

        self::assertInstanceOf(LoggingLedger::class, $loggingLedger);
    }

    public function test_unwrap_returns_underlying_ledger(): void
    {
        $ledger = Ledger::inMemory();
        $logger = $this->createMock(LoggerInterface::class);

        $loggingLedger = LoggingLedger::wrap($ledger, $logger);

        self::assertSame($ledger, $loggingLedger->unwrap());
    }

    public function test_apply_logs_before_and_after(): void
    {
        $ledger = Ledger::withGenesis(Output::open(100, 'a'));
        $logger = $this->createMock(LoggerInterface::class);

        $logger->expects($this->exactly(2))
            ->method('info')
            ->willReturnCallback(static function (string $message, array $context): void {
                if ($message === 'Applying transaction') {
                    self::assertSame('tx1', $context['tx_id']);
                    self::assertSame(1, $context['spends_count']);
                    self::assertSame(1, $context['outputs_count']);
                } elseif ($message === 'Transaction applied') {
                    self::assertSame('tx1', $context['tx_id']);
                    self::assertArrayHasKey('fee', $context);
                    self::assertArrayHasKey('new_unspent_count', $context);
                }
            });

        $loggingLedger = LoggingLedger::wrap($ledger, $logger);

        $loggingLedger->apply(new Tx(
            id: new TxId('tx1'),
            spends: [new OutputId('a')],
            outputs: [Output::open(90, 'b')],
        ));
    }

    public function test_apply_calculates_fee_correctly(): void
    {
        $ledger = Ledger::withGenesis(Output::open(100, 'a'));
        $logger = $this->createMock(LoggerInterface::class);

        $logger->expects($this->exactly(2))
            ->method('info')
            ->willReturnCallback(static function (string $message, array $context): void {
                if ($message === 'Transaction applied') {
                    self::assertSame(10, $context['fee']);
                }
            });

        $loggingLedger = LoggingLedger::wrap($ledger, $logger);

        $loggingLedger->apply(new Tx(
            id: new TxId('tx1'),
            spends: [new OutputId('a')],
            outputs: [Output::open(90, 'b')],
        ));
    }

    public function test_apply_logs_warning_on_exception(): void
    {
        $ledger = Ledger::withGenesis(Output::open(100, 'a'));
        $logger = $this->createMock(LoggerInterface::class);

        $logger->expects($this->once())
            ->method('info');

        $logger->expects($this->once())
            ->method('warning')
            ->willReturnCallback(static function (string $message, array $context): void {
                self::assertSame('Transaction failed', $message);
                self::assertSame('tx1', $context['tx_id']);
                self::assertStringContainsString('nonexistent', $context['error']);
                self::assertSame(OutputAlreadySpentException::class, $context['exception_type']);
            });

        $loggingLedger = LoggingLedger::wrap($ledger, $logger);

        $this->expectException(OutputAlreadySpentException::class);

        $loggingLedger->apply(new Tx(
            id: new TxId('tx1'),
            spends: [new OutputId('nonexistent')],
            outputs: [Output::open(100, 'b')],
        ));
    }

    public function test_apply_coinbase_logs_minting(): void
    {
        $ledger = Ledger::inMemory();
        $logger = $this->createMock(LoggerInterface::class);

        $logger->expects($this->exactly(2))
            ->method('info')
            ->willReturnCallback(static function (string $message, array $context): void {
                if ($message === 'Applying coinbase transaction') {
                    self::assertSame('block-1', $context['tx_id']);
                    self::assertSame(50, $context['minted_amount']);
                    self::assertSame(1, $context['outputs_count']);
                } elseif ($message === 'Coinbase applied') {
                    self::assertSame('block-1', $context['tx_id']);
                    self::assertSame(50, $context['minted']);
                    self::assertSame(50, $context['total_minted']);
                }
            });

        $loggingLedger = LoggingLedger::wrap($ledger, $logger);

        $loggingLedger->applyCoinbase(CoinbaseTx::create([Output::open(50, 'reward')], 'block-1'));
    }

    public function test_apply_coinbase_logs_warning_on_exception(): void
    {
        $ledger = Ledger::inMemory()
            ->applyCoinbase(CoinbaseTx::create([Output::open(50, 'reward')], 'block-1'));
        $logger = $this->createMock(LoggerInterface::class);

        $logger->expects($this->once())
            ->method('info');

        $logger->expects($this->once())
            ->method('warning')
            ->willReturnCallback(static function (string $message, array $context): void {
                self::assertSame('Coinbase failed', $message);
                self::assertSame('block-1', $context['tx_id']);
                self::assertStringContainsString('block-1', $context['error']);
            });

        $loggingLedger = LoggingLedger::wrap($ledger, $logger);

        $this->expectException(DuplicateTxException::class);

        $loggingLedger->applyCoinbase(CoinbaseTx::create([Output::open(50, 'other')], 'block-1'));
    }

    public function test_transfer_logs_details(): void
    {
        $ledger = Ledger::withGenesis(Output::ownedBy('alice', 1000, 'alice-funds'));
        $logger = $this->createMock(LoggerInterface::class);

        $logger->expects($this->exactly(2))
            ->method('info')
            ->willReturnCallback(static function (string $message, array $context): void {
                self::assertSame('alice', $context['from']);
                self::assertSame('bob', $context['to']);
                self::assertSame(300, $context['amount']);
                self::assertSame(10, $context['fee']);
            });

        $loggingLedger = LoggingLedger::wrap($ledger, $logger);

        $loggingLedger->transfer('alice', 'bob', 300, 10);
    }

    public function test_transfer_logs_warning_on_exception(): void
    {
        $ledger = Ledger::withGenesis(Output::ownedBy('alice', 100, 'alice-funds'));
        $logger = $this->createMock(LoggerInterface::class);

        $logger->expects($this->once())
            ->method('info');

        $logger->expects($this->once())
            ->method('warning')
            ->willReturnCallback(static function (string $message, array $context): void {
                self::assertSame('Transfer failed', $message);
                self::assertSame('alice', $context['from']);
                self::assertSame('bob', $context['to']);
                self::assertSame(500, $context['amount']);
                self::assertStringContainsString('Insufficient', $context['error']);
            });

        $loggingLedger = LoggingLedger::wrap($ledger, $logger);

        $this->expectException(InsufficientSpendsException::class);

        $loggingLedger->transfer('alice', 'bob', 500);
    }

    public function test_debit_logs_details(): void
    {
        $ledger = Ledger::withGenesis(Output::ownedBy('alice', 1000, 'alice-funds'));
        $logger = $this->createMock(LoggerInterface::class);

        $logger->expects($this->exactly(2))
            ->method('info')
            ->willReturnCallback(static function (string $message, array $context): void {
                self::assertSame('alice', $context['owner']);
                self::assertSame(300, $context['amount']);
                self::assertSame(50, $context['fee']);
            });

        $loggingLedger = LoggingLedger::wrap($ledger, $logger);

        $loggingLedger->debit('alice', 300, 50);
    }

    public function test_debit_logs_warning_on_exception(): void
    {
        $ledger = Ledger::withGenesis(Output::ownedBy('alice', 100, 'alice-funds'));
        $logger = $this->createMock(LoggerInterface::class);

        $logger->expects($this->once())
            ->method('info');

        $logger->expects($this->once())
            ->method('warning')
            ->willReturnCallback(static function (string $message, array $context): void {
                self::assertSame('Debit failed', $message);
                self::assertSame('alice', $context['owner']);
                self::assertSame(500, $context['amount']);
                self::assertStringContainsString('Insufficient', $context['error']);
            });

        $loggingLedger = LoggingLedger::wrap($ledger, $logger);

        $this->expectException(InsufficientSpendsException::class);

        $loggingLedger->debit('alice', 500);
    }

    public function test_credit_logs_details(): void
    {
        $ledger = Ledger::inMemory();
        $logger = $this->createMock(LoggerInterface::class);

        $logger->expects($this->exactly(2))
            ->method('info')
            ->willReturnCallback(static function (string $message, array $context): void {
                self::assertSame('alice', $context['owner']);
                self::assertSame(500, $context['amount']);
                if ($message === 'Credit completed') {
                    self::assertSame(500, $context['total_minted']);
                }
            });

        $loggingLedger = LoggingLedger::wrap($ledger, $logger);

        $loggingLedger->credit('alice', 500);
    }

    public function test_credit_logs_warning_on_exception(): void
    {
        $ledger = Ledger::inMemory()
            ->credit('alice', 500, 'tx-1');
        $logger = $this->createMock(LoggerInterface::class);

        $logger->expects($this->once())
            ->method('info');

        $logger->expects($this->once())
            ->method('warning')
            ->willReturnCallback(static function (string $message, array $context): void {
                self::assertSame('Credit failed', $message);
                self::assertSame('bob', $context['owner']);
                self::assertSame(100, $context['amount']);
                self::assertStringContainsString('tx-1', $context['error']);
            });

        $loggingLedger = LoggingLedger::wrap($ledger, $logger);

        $this->expectException(DuplicateTxException::class);

        $loggingLedger->credit('bob', 100, 'tx-1');
    }

    public function test_read_only_methods_do_not_log(): void
    {
        $ledger = Ledger::withGenesis(Output::ownedBy('alice', 1000, 'alice-funds'));
        $logger = $this->createMock(LoggerInterface::class);

        // Logger should never be called for read-only operations
        $logger->expects($this->never())->method('info');
        $logger->expects($this->never())->method('warning');
        $logger->expects($this->never())->method('error');

        $loggingLedger = LoggingLedger::wrap($ledger, $logger);

        // Call all read-only methods
        $loggingLedger->unspent();
        $loggingLedger->totalUnspentAmount();
        $loggingLedger->unspentByOwner('alice');
        $loggingLedger->totalUnspentByOwner('alice');
        $loggingLedger->isTxApplied(new TxId('nonexistent'));
        $loggingLedger->totalFeesCollected();
        $loggingLedger->feeForTx(new TxId('nonexistent'));
        $loggingLedger->allTxFees();
        $loggingLedger->totalMinted();
        $loggingLedger->isCoinbase(new TxId('nonexistent'));
        $loggingLedger->coinbaseAmount(new TxId('nonexistent'));
        $loggingLedger->outputCreatedBy(new OutputId('alice-funds'));
        $loggingLedger->outputSpentBy(new OutputId('alice-funds'));
        $loggingLedger->getOutput(new OutputId('alice-funds'));
        $loggingLedger->outputExists(new OutputId('alice-funds'));
        $loggingLedger->outputHistory(new OutputId('alice-funds'));
        $loggingLedger->historyRepository();
        $loggingLedger->toArray();
        $loggingLedger->toJson();
    }

    public function test_read_only_methods_delegate_correctly(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 1000, 'alice-funds'),
            Output::ownedBy('bob', 500, 'bob-funds'),
        );
        $logger = $this->createMock(LoggerInterface::class);

        $loggingLedger = LoggingLedger::wrap($ledger, $logger);

        self::assertSame(1500, $loggingLedger->totalUnspentAmount());
        self::assertSame(2, $loggingLedger->unspent()->count());
        self::assertSame(1000, $loggingLedger->totalUnspentByOwner('alice'));
        self::assertSame(500, $loggingLedger->totalUnspentByOwner('bob'));
        self::assertFalse($loggingLedger->isTxApplied(new TxId('nonexistent')));
        self::assertSame(0, $loggingLedger->totalFeesCollected());
        self::assertNull($loggingLedger->feeForTx(new TxId('nonexistent')));
        self::assertSame([], $loggingLedger->allTxFees());
        self::assertSame(0, $loggingLedger->totalMinted());
        self::assertFalse($loggingLedger->isCoinbase(new TxId('nonexistent')));
        self::assertNull($loggingLedger->coinbaseAmount(new TxId('nonexistent')));
        self::assertTrue($loggingLedger->outputExists(new OutputId('alice-funds')));
    }

    public function test_apply_returns_new_logging_ledger_instance(): void
    {
        $ledger = Ledger::withGenesis(Output::open(100, 'a'));
        $logger = $this->createMock(LoggerInterface::class);

        $loggingLedger = LoggingLedger::wrap($ledger, $logger);

        $newLoggingLedger = $loggingLedger->apply(new Tx(
            id: new TxId('tx1'),
            spends: [new OutputId('a')],
            outputs: [Output::open(100, 'b')],
        ));

        self::assertInstanceOf(LoggingLedger::class, $newLoggingLedger);
        self::assertNotSame($loggingLedger, $newLoggingLedger);
        self::assertTrue($newLoggingLedger->isTxApplied(new TxId('tx1')));
    }

    public function test_can_apply_delegates_without_logging(): void
    {
        $ledger = Ledger::withGenesis(Output::open(100, 'a'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('info');
        $logger->expects($this->never())->method('warning');

        $loggingLedger = LoggingLedger::wrap($ledger, $logger);

        $tx = new Tx(
            id: new TxId('tx1'),
            spends: [new OutputId('a')],
            outputs: [Output::open(100, 'b')],
        );

        $result = $loggingLedger->canApply($tx);

        self::assertNull($result);
    }

    public function test_can_apply_returns_exception_for_invalid_tx(): void
    {
        $ledger = Ledger::withGenesis(Output::open(100, 'a'));
        $logger = $this->createMock(LoggerInterface::class);

        $loggingLedger = LoggingLedger::wrap($ledger, $logger);

        $tx = new Tx(
            id: new TxId('tx1'),
            spends: [new OutputId('nonexistent')],
            outputs: [Output::open(100, 'b')],
        );

        $result = $loggingLedger->canApply($tx);

        self::assertInstanceOf(OutputAlreadySpentException::class, $result);
    }
}
