<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Benchmark;

use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\Persistence\Sqlite\SqliteHistoryRepository;
use Chemaclass\Unspent\Persistence\Sqlite\SqliteSchema;
use Chemaclass\Unspent\Tx;
use PDO;
use PhpBench\Attributes as Bench;

/**
 * Benchmarks for Ledger operations.
 *
 * Run: vendor/bin/phpbench run --report=aggregate
 * Or:  composer benchmark
 */
#[Bench\BeforeMethods('setUp')]
final class LedgerBench
{
    private Ledger $inMemoryLedger;

    public function setUp(): void
    {
        $this->inMemoryLedger = Ledger::withGenesis(
            Output::open(PHP_INT_MAX, 'genesis'),
        );
    }

    #[Bench\Revs(100)]
    #[Bench\Iterations(5)]
    #[Bench\Subject]
    public function benchInMemoryApply(): void
    {
        $ledger = $this->inMemoryLedger;

        for ($i = 0; $i < 10; ++$i) {
            $outputIds = $ledger->unspent()->outputIds();
            $selectedId = $outputIds[array_rand($outputIds)];
            $output = $ledger->unspent()->get($selectedId);

            if ($output === null) {
                break;
            }

            $amount = $output->amount;
            $half = (int) ($amount / 2);

            if ($half < 1) {
                break;
            }

            $ledger = $ledger->apply(Tx::create(
                spendIds: [$selectedId->value],
                outputs: [
                    Output::open($half, "out-{$i}-a"),
                    Output::open($amount - $half, "out-{$i}-b"),
                ],
                id: "tx-{$i}",
            ));
        }
    }

    #[Bench\Revs(10)]
    #[Bench\Iterations(3)]
    #[Bench\Subject]
    public function benchSqliteApply(): void
    {
        // Create fresh SQLite ledger for each benchmark iteration
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        new SqliteSchema($pdo)->create();

        $repository = new SqliteHistoryRepository($pdo, 'bench');
        $ledger = Ledger::withRepository($repository)
            ->addGenesis(Output::open(PHP_INT_MAX, 'genesis'));

        for ($i = 0; $i < 10; ++$i) {
            $outputIds = $ledger->unspent()->outputIds();
            $selectedId = $outputIds[array_rand($outputIds)];
            $output = $ledger->unspent()->get($selectedId);

            if ($output === null) {
                break;
            }

            $amount = $output->amount;
            $half = (int) ($amount / 2);

            if ($half < 1) {
                break;
            }

            $ledger = $ledger->apply(Tx::create(
                spendIds: [$selectedId->value],
                outputs: [
                    Output::open($half, "out-{$i}-a"),
                    Output::open($amount - $half, "out-{$i}-b"),
                ],
                id: "tx-{$i}",
            ));
        }
    }

    #[Bench\Revs(10)]
    #[Bench\Iterations(3)]
    #[Bench\Subject]
    public function benchGenesisWithManyOutputs(): void
    {
        $outputs = [];
        for ($i = 0; $i < 1000; ++$i) {
            $outputs[] = Output::open(100, "utxo-{$i}");
        }

        Ledger::withGenesis(...$outputs);
    }
}
