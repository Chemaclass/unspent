<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Feature;

use Chemaclass\Unspent\InMemoryLedger;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\Tx;
use Chemaclass\Unspent\TxId;
use PHPUnit\Framework\TestCase;

final class LedgerIntegrationTest extends TestCase
{
    public function test_readme_usage_example(): void
    {
        // Create a ledger with genesis outputs
        $ledger = InMemoryLedger::empty()
            ->addGenesis(
                Output::open(1000, 'genesis-1'),
                Output::open(500, 'genesis-2'),
            );

        self::assertSame(1500, $ledger->totalUnspentAmount());

        // Apply a spend with implicit fee (1000 -> 600 + 390 = 990, fee = 10)
        $ledger = $ledger->apply(Tx::create(
            spendIds: ['genesis-1'],
            outputs: [
                Output::open(600, 'alice'),
                Output::open(390, 'bob'),
            ],
            id: 'tx-001',
        ));

        // Verify conservation minus fee
        self::assertSame(1490, $ledger->totalUnspentAmount());
        self::assertSame(10, $ledger->feeForTx(new TxId('tx-001')));

        // Check unspent outputs
        $unspent = $ledger->unspent();
        self::assertFalse($unspent->contains(new OutputId('genesis-1')));
        self::assertTrue($unspent->contains(new OutputId('genesis-2')));
        self::assertTrue($unspent->contains(new OutputId('alice')));
        self::assertTrue($unspent->contains(new OutputId('bob')));

        // Get specific output
        $aliceOutput = $unspent->get(new OutputId('alice'));
        self::assertNotNull($aliceOutput);
        self::assertSame(600, $aliceOutput->amount);

        // Check spend history
        self::assertTrue($ledger->isTxApplied(new TxId('tx-001')));
        self::assertFalse($ledger->isTxApplied(new TxId('tx-999')));

        // Iterate over unspent outputs
        $count = 0;
        foreach ($unspent as $id => $output) {
            self::assertIsString($id);
            self::assertInstanceOf(Output::class, $output);
            ++$count;
        }
        self::assertSame(3, $count);
    }

    public function test_chain_of_spends_with_fees(): void
    {
        $ledger = InMemoryLedger::empty()
            ->addGenesis(Output::open(1000, 'genesis'))
            ->apply(Tx::create(
                spendIds: ['genesis'],
                outputs: [Output::open(990, 'a')],
                id: 'tx-1',
            ))
            ->apply(Tx::create(
                spendIds: ['a'],
                outputs: [Output::open(980, 'b')],
                id: 'tx-2',
            ))
            ->apply(Tx::create(
                spendIds: ['b'],
                outputs: [Output::open(970, 'c')],
                id: 'tx-3',
            ));

        // Total fees: 10 + 10 + 10 = 30
        self::assertSame(30, $ledger->totalFeesCollected());
        self::assertSame(970, $ledger->totalUnspentAmount());

        // Query individual fees
        self::assertSame(10, $ledger->feeForTx(new TxId('tx-1')));
        self::assertSame(10, $ledger->feeForTx(new TxId('tx-2')));
        self::assertSame(10, $ledger->feeForTx(new TxId('tx-3')));

        // All spend fees
        $fees = $ledger->allTxFees();
        self::assertCount(3, $fees);
        self::assertSame(['tx-1' => 10, 'tx-2' => 10, 'tx-3' => 10], $fees);
    }

    public function test_multi_input_spend(): void
    {
        $ledger = InMemoryLedger::empty()
            ->addGenesis(
                Output::open(100, 'a'),
                Output::open(200, 'b'),
                Output::open(300, 'c'),
            )
            ->apply(Tx::create(
                spendIds: ['a', 'b', 'c'],
                outputs: [Output::open(600, 'combined')],
                id: 'combine',
            ));

        self::assertSame(600, $ledger->totalUnspentAmount());
        self::assertSame(0, $ledger->feeForTx(new TxId('combine')));
        self::assertSame(1, $ledger->unspent()->count());
    }
}
