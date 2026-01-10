<?php

declare(strict_types=1);

namespace Example\Console;

use Chemaclass\Unspent\InMemoryLedger;
use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\Persistence\Sqlite\SqliteHistoryStore;
use Chemaclass\Unspent\Persistence\Sqlite\SqliteLedgerRepository;
use Chemaclass\Unspent\ScalableLedger;
use PDO;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

abstract class AbstractExampleCommand extends Command
{
    private const string MODE_MEMORY = 'memory';
    private const string MODE_DB = 'db';
    private const string DEFAULT_DB_PATH = __DIR__ . '/../../data/ledger.db';

    protected SymfonyStyle $io;
    protected string $mode;
    protected ?PDO $pdo = null;
    protected ?SqliteHistoryStore $store = null;
    protected ?SqliteLedgerRepository $repo = null;
    protected int $runNumber = 1;

    abstract protected function runMemoryDemo(): int;

    abstract protected function runDatabaseDemo(): int;

    protected function configure(): void
    {
        $this->addOption(
            'run-on',
            null,
            InputOption::VALUE_REQUIRED,
            'Run mode: "memory" or "db"',
            self::MODE_MEMORY,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);
        $this->mode = $input->getOption('run-on');

        $this->displayHeader();

        if ($this->isDatabase()) {
            if (!$this->initDatabase()) {
                return Command::FAILURE;
            }
            return $this->runDatabaseDemo();
        }

        return $this->runMemoryDemo();
    }

    protected function displayHeader(): void
    {
        $this->io->title($this->getDescription());
        $modeLabel = $this->isDatabase() ? '<fg=cyan>database</>' : '<fg=yellow>memory</>';
        $this->io->text("Mode: {$modeLabel}");

        if ($this->isDatabase()) {
            $this->io->text("Ledger ID: <fg=cyan>{$this->getLedgerId()}</> (Run #{$this->runNumber})");
        }

        $this->io->newLine();
    }

    protected function isDatabase(): bool
    {
        return $this->mode === self::MODE_DB;
    }

    protected function getLedgerId(): string
    {
        return $this->getName() ?? 'example';
    }

    /**
     * @param callable(): list<Output> $genesisFactory
     */
    protected function loadOrCreate(callable $genesisFactory): Ledger
    {
        if (!$this->isDatabase()) {
            return InMemoryLedger::withGenesis(...$genesisFactory());
        }

        $existingData = $this->repo->findUnspentOnly($this->getLedgerId());

        if ($existingData !== null && $existingData['unspentSet']->count() > 0) {
            $this->io->text("<fg=yellow>[Loaded existing ledger with {$existingData['unspentSet']->count()} outputs]</>");
            $this->io->newLine();

            return ScalableLedger::fromUnspentSet(
                $existingData['unspentSet'],
                $this->store,
                $existingData['totalFees'],
                $existingData['totalMinted'],
            );
        }

        $this->createLedgerRecord();
        $outputs = $genesisFactory();
        $this->io->text('<fg=green>[Created new ledger with ' . \count($outputs) . ' genesis outputs]</>');
        $this->io->newLine();

        return ScalableLedger::create($this->store, ...$outputs);
    }

    protected function loadOrCreateEmpty(): Ledger
    {
        if (!$this->isDatabase()) {
            return InMemoryLedger::empty();
        }

        $existingData = $this->repo->findUnspentOnly($this->getLedgerId());

        if ($existingData !== null && $existingData['unspentSet']->count() > 0) {
            $this->io->text("<fg=yellow>[Loaded existing ledger with {$existingData['unspentSet']->count()} outputs]</>");
            $this->io->newLine();

            return ScalableLedger::fromUnspentSet(
                $existingData['unspentSet'],
                $this->store,
                $existingData['totalFees'],
                $existingData['totalMinted'],
            );
        }

        $this->createLedgerRecord();
        $this->io->text('<fg=green>[Created new empty ledger]</>');
        $this->io->newLine();

        return ScalableLedger::create($this->store);
    }

    protected function showStats(Ledger $ledger): void
    {
        if (!$this->isDatabase()) {
            return;
        }

        $this->io->section('Database Stats');

        $ledgerId = $this->getLedgerId();
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM outputs WHERE ledger_id = ?');
        $stmt->execute([$ledgerId]);
        $outputCount = (int) $stmt->fetchColumn();

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM transactions WHERE ledger_id = ?');
        $stmt->execute([$ledgerId]);
        $txCount = (int) $stmt->fetchColumn();

        $this->io->listing([
            "Ledger: {$ledgerId}",
            "Outputs: {$outputCount}",
            "Transactions: {$txCount}",
            "Run #: {$this->runNumber}",
        ]);

        $this->io->text("<fg=green>Run again to continue.</> Use 'composer init-db -- --force' to reset.");
    }

    private function initDatabase(): bool
    {
        $dbPath = self::DEFAULT_DB_PATH;

        if (!file_exists($dbPath)) {
            $this->io->error("Database not found. Run 'composer init-db' first.");
            return false;
        }

        $this->pdo = new PDO("sqlite:{$dbPath}");
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $ledgerId = $this->getLedgerId();
        $this->store = new SqliteHistoryStore($this->pdo, $ledgerId);
        $this->repo = new SqliteLedgerRepository($this->pdo);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM transactions WHERE ledger_id = ?');
        $stmt->execute([$ledgerId]);
        $this->runNumber = (int) $stmt->fetchColumn() + 1;

        return true;
    }

    private function createLedgerRecord(): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT OR IGNORE INTO ledgers (id, version, total_unspent, total_fees, total_minted) VALUES (?, 1, 0, 0, 0)',
        );
        $stmt->execute([$this->getLedgerId()]);
    }
}
