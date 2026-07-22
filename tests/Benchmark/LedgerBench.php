<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Benchmark;

use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\Persistence\Sqlite\SqliteHistoryRepository;
use Chemaclass\Unspent\Persistence\Sqlite\SqliteSchema;
use Chemaclass\Unspent\Tx;
use Generator;
use PDO;
use PhpBench\Attributes as Bench;

/**
 * Benchmarks for Ledger operations.
 *
 * Run: vendor/bin/phpbench run --report=aggregate
 * Or:  composer benchmark
 *
 * Each subject builds its own ledger so every revolution measures real work
 * (a shared ledger would be exhausted after the first revolution).
 */
final class LedgerBench
{
    /**
     * Chain of splits on a fresh in-memory ledger. Baseline apply throughput.
     */
    #[Bench\Revs(50)]
    #[Bench\Iterations(5)]
    #[Bench\Subject]
    public function benchInMemoryApply(): void
    {
        $ledger = Ledger::withGenesis(Output::open(PHP_INT_MAX, 'genesis'));
        $spendId = 'genesis';

        for ($i = 0; $i < 10; ++$i) {
            $output = $ledger->getOutput(new OutputId($spendId));
            if ($output === null) {
                break;
            }

            $half = intdiv($output->amount, 2);
            if ($half < 1) {
                break;
            }

            $ledger->apply(Tx::create(
                spendIds: [$spendId],
                outputs: [
                    Output::open($half, "a-{$i}"),
                    Output::open($output->amount - $half, "b-{$i}"),
                ],
                id: "tx-{$i}",
            ));

            $spendId = "b-{$i}";
        }
    }

    /**
     * N sequential coinbase credits. Wall time should be ~linear in N once the
     * history repository stops copying its arrays on every apply; a quadratic
     * curve here flags the O(n²) regression (issue #2).
     *
     * @param array{count: int} $params
     */
    #[Bench\ParamProviders('provideScales')]
    #[Bench\Revs(1)]
    #[Bench\Iterations(3)]
    #[Bench\Subject]
    public function benchSequentialCredits(array $params): void
    {
        $ledger = Ledger::inMemory();

        for ($i = 0, $n = $params['count']; $i < $n; ++$i) {
            $ledger->credit("owner-{$i}", 100, "cb-{$i}");
        }
    }

    /**
     * N iterations of read-then-write. Reading via unspent() marks the set as
     * shared, so a naive copy-on-fork forces a full array copy on the next
     * write — this subject exposes that degradation (issue #3).
     *
     * @param array{count: int} $params
     */
    #[Bench\ParamProviders('provideScales')]
    #[Bench\Revs(1)]
    #[Bench\Iterations(3)]
    #[Bench\Subject]
    public function benchInterleavedReadWrite(array $params): void
    {
        $ledger = Ledger::inMemory();

        for ($i = 0, $n = $params['count']; $i < $n; ++$i) {
            $ledger->unspent();
            $ledger->credit("owner-{$i}", 100, "cb-{$i}");
        }
    }

    /**
     * @return Generator<string, array{count: int}>
     */
    public function provideScales(): Generator
    {
        yield '100' => ['count' => 100];
        yield '1000' => ['count' => 1000];
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
