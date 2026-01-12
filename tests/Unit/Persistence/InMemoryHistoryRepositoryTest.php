<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit\Persistence;

use Chemaclass\Unspent\CoinbaseTx;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\OutputStatus;
use Chemaclass\Unspent\Persistence\InMemoryHistoryRepository;
use Chemaclass\Unspent\Tx;
use Chemaclass\Unspent\TxId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InMemoryHistoryRepositoryTest extends TestCase
{
    #[Test]
    public function saves_and_finds_transaction_fee(): void
    {
        $repository = new InMemoryHistoryRepository();

        $tx = Tx::create(
            spendIds: ['input-1'],
            outputs: [Output::open(90, 'output-1')],
            id: 'tx-1',
        );

        $repository->saveTransaction($tx, fee: 10, spentOutputData: [
            'input-1' => ['amount' => 100, 'lock' => ['type' => 'none']],
        ]);

        self::assertSame(10, $repository->findFeeForTx(new TxId('tx-1')));
        self::assertNull($repository->findFeeForTx(new TxId('unknown')));
    }

    #[Test]
    public function find_all_tx_fees_returns_all_fees(): void
    {
        $repository = new InMemoryHistoryRepository();

        $tx1 = Tx::create(spendIds: ['i1'], outputs: [Output::open(90)], id: 'tx-1');
        $tx2 = Tx::create(spendIds: ['i2'], outputs: [Output::open(80)], id: 'tx-2');

        $repository->saveTransaction($tx1, fee: 10, spentOutputData: [
            'i1' => ['amount' => 100, 'lock' => ['type' => 'none']],
        ]);
        $repository->saveTransaction($tx2, fee: 20, spentOutputData: [
            'i2' => ['amount' => 100, 'lock' => ['type' => 'none']],
        ]);

        $fees = $repository->findAllTxFees();

        self::assertSame(['tx-1' => 10, 'tx-2' => 20], $fees);
    }

    #[Test]
    public function saves_and_finds_coinbase(): void
    {
        $repository = new InMemoryHistoryRepository();

        $coinbase = CoinbaseTx::create(
            outputs: [Output::open(100, 'minted-1')],
            id: 'coinbase-1',
        );

        $repository->saveCoinbase($coinbase);

        self::assertTrue($repository->isCoinbase(new TxId('coinbase-1')));
        self::assertFalse($repository->isCoinbase(new TxId('unknown')));
        self::assertSame(100, $repository->findCoinbaseAmount(new TxId('coinbase-1')));
        self::assertNull($repository->findCoinbaseAmount(new TxId('unknown')));
    }

    #[Test]
    public function saves_genesis_and_tracks_created_by(): void
    {
        $repository = new InMemoryHistoryRepository();

        $outputs = [
            Output::open(100, 'genesis-1'),
            Output::open(200, 'genesis-2'),
        ];

        $repository->saveGenesis($outputs);

        self::assertSame('genesis', $repository->findOutputCreatedBy(new OutputId('genesis-1')));
        self::assertSame('genesis', $repository->findOutputCreatedBy(new OutputId('genesis-2')));
        self::assertNull($repository->findOutputCreatedBy(new OutputId('unknown')));
    }

    #[Test]
    public function tracks_output_created_by_for_transactions(): void
    {
        $repository = new InMemoryHistoryRepository();

        $tx = Tx::create(
            spendIds: ['input-1'],
            outputs: [Output::open(90, 'output-1')],
            id: 'tx-1',
        );

        $repository->saveTransaction($tx, fee: 10, spentOutputData: [
            'input-1' => ['amount' => 100, 'lock' => ['type' => 'none']],
        ]);

        self::assertSame('tx-1', $repository->findOutputCreatedBy(new OutputId('output-1')));
    }

    #[Test]
    public function tracks_output_created_by_for_coinbase(): void
    {
        $repository = new InMemoryHistoryRepository();

        $coinbase = CoinbaseTx::create(
            outputs: [Output::open(100, 'minted-1')],
            id: 'coinbase-1',
        );

        $repository->saveCoinbase($coinbase);

        self::assertSame('coinbase-1', $repository->findOutputCreatedBy(new OutputId('minted-1')));
    }

    #[Test]
    public function tracks_spent_outputs(): void
    {
        $repository = new InMemoryHistoryRepository();

        $tx = Tx::create(
            spendIds: ['input-1'],
            outputs: [Output::open(90, 'output-1')],
            id: 'tx-1',
        );

        $repository->saveTransaction($tx, fee: 10, spentOutputData: [
            'input-1' => ['amount' => 100, 'lock' => ['type' => 'none']],
        ]);

        self::assertSame('tx-1', $repository->findOutputSpentBy(new OutputId('input-1')));
        self::assertNull($repository->findOutputSpentBy(new OutputId('output-1')));
    }

    #[Test]
    public function finds_spent_output(): void
    {
        $repository = new InMemoryHistoryRepository();

        $tx = Tx::create(
            spendIds: ['input-1'],
            outputs: [Output::open(90, 'output-1')],
            id: 'tx-1',
        );

        $repository->saveTransaction($tx, fee: 10, spentOutputData: [
            'input-1' => ['amount' => 100, 'lock' => ['type' => 'none']],
        ]);

        $spentOutput = $repository->findSpentOutput(new OutputId('input-1'));

        self::assertNotNull($spentOutput);
        self::assertSame('input-1', $spentOutput->id->value);
        self::assertSame(100, $spentOutput->amount);

        self::assertNull($repository->findSpentOutput(new OutputId('unknown')));
    }

    #[Test]
    public function finds_output_history(): void
    {
        $repository = new InMemoryHistoryRepository();

        // First save genesis
        $repository->saveGenesis([Output::open(100, 'genesis-output')]);

        // Then spend it
        $tx = Tx::create(
            spendIds: ['genesis-output'],
            outputs: [Output::open(90, 'new-output')],
            id: 'tx-1',
        );

        $repository->saveTransaction($tx, fee: 10, spentOutputData: [
            'genesis-output' => ['amount' => 100, 'lock' => ['type' => 'none']],
        ]);

        $history = $repository->findOutputHistory(new OutputId('genesis-output'));

        self::assertNotNull($history);
        self::assertSame('genesis-output', $history->id->value);
        self::assertSame(100, $history->amount);
        self::assertSame('genesis', $history->createdBy);
        self::assertSame('tx-1', $history->spentBy);
        self::assertSame(OutputStatus::SPENT, $history->status);

        self::assertNull($repository->findOutputHistory(new OutputId('unknown')));
    }

    #[Test]
    public function serialization_to_and_from_array(): void
    {
        $repository = new InMemoryHistoryRepository();

        // Add various data
        $repository->saveGenesis([Output::open(1000, 'genesis-1')]);

        $coinbase = CoinbaseTx::create(
            outputs: [Output::open(50, 'minted-1')],
            id: 'coinbase-1',
        );
        $repository->saveCoinbase($coinbase);

        $tx = Tx::create(
            spendIds: ['genesis-1'],
            outputs: [Output::open(990, 'output-1')],
            id: 'tx-1',
        );
        $repository->saveTransaction($tx, fee: 10, spentOutputData: [
            'genesis-1' => ['amount' => 1000, 'lock' => ['type' => 'none']],
        ]);

        // Serialize
        $data = $repository->toArray();

        // Deserialize
        $restored = InMemoryHistoryRepository::fromArray($data);

        // Verify all data was preserved
        self::assertSame(10, $restored->findFeeForTx(new TxId('tx-1')));
        self::assertTrue($restored->isCoinbase(new TxId('coinbase-1')));
        self::assertSame(50, $restored->findCoinbaseAmount(new TxId('coinbase-1')));
        self::assertSame('genesis', $restored->findOutputCreatedBy(new OutputId('genesis-1')));
        self::assertSame('tx-1', $restored->findOutputCreatedBy(new OutputId('output-1')));
        self::assertSame('coinbase-1', $restored->findOutputCreatedBy(new OutputId('minted-1')));
        self::assertSame('tx-1', $restored->findOutputSpentBy(new OutputId('genesis-1')));

        $spentOutput = $restored->findSpentOutput(new OutputId('genesis-1'));
        self::assertNotNull($spentOutput);
        self::assertSame(1000, $spentOutput->amount);
    }

    #[Test]
    public function from_array_handles_empty_data(): void
    {
        $repository = InMemoryHistoryRepository::fromArray([]);

        self::assertSame([], $repository->findAllTxFees());
        self::assertNull($repository->findFeeForTx(new TxId('any')));
        self::assertFalse($repository->isCoinbase(new TxId('any')));
    }

    #[Test]
    public function constructor_accepts_initial_data(): void
    {
        $repository = new InMemoryHistoryRepository(
            txFees: ['tx-1' => 10],
            coinbaseAmounts: ['cb-1' => 100],
            outputCreatedBy: ['o-1' => 'genesis'],
            outputSpentBy: ['o-1' => 'tx-2'],
            spentOutputs: ['o-1' => ['amount' => 50, 'lock' => ['type' => 'none']]],
        );

        self::assertSame(10, $repository->findFeeForTx(new TxId('tx-1')));
        self::assertSame(100, $repository->findCoinbaseAmount(new TxId('cb-1')));
        self::assertSame('genesis', $repository->findOutputCreatedBy(new OutputId('o-1')));
        self::assertSame('tx-2', $repository->findOutputSpentBy(new OutputId('o-1')));
        self::assertNotNull($repository->findSpentOutput(new OutputId('o-1')));
    }
}
