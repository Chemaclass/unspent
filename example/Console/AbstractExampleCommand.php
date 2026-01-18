<?php

declare(strict_types=1);

namespace Example\Console;

use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\LedgerInterface;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\Persistence\QueryableLedgerRepository;
use Chemaclass\Unspent\Persistence\Sqlite\SqliteRepositoryFactory;
use PDO;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

abstract class AbstractExampleCommand extends Command
{
    protected SymfonyStyle $io;
    protected PDO $pdo;
    protected QueryableLedgerRepository $repository;
    protected string $ledgerId;

    abstract protected function runDemo(): int;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);
        $this->ledgerId = $this->getName() ?? 'example';
        $this->initDatabase();

        $this->displayHeader();

        return $this->runDemo();
    }

    protected function displayHeader(): void
    {
        $this->io->title($this->getDescription());
    }

    protected function getDbPath(): string
    {
        return __DIR__ . '/../../data/' . $this->ledgerId . '.db';
    }

    /**
     * @param callable(): list<Output> $genesisFactory
     */
    protected function loadOrCreate(callable $genesisFactory): LedgerInterface
    {
        $existing = $this->repository->find($this->ledgerId);

        if ($existing !== null && $existing->unspent()->count() > 0) {
            $this->io->text("<fg=yellow>[Loaded existing ledger with {$existing->unspent()->count()} outputs]</>");
            $this->io->newLine();

            return $existing;
        }

        $outputs = $genesisFactory();
        $ledger = Ledger::withGenesis(...$outputs);
        $this->repository->save($this->ledgerId, $ledger);
        $this->io->text('<fg=green>[Created new ledger with ' . \count($outputs) . ' genesis outputs]</>');
        $this->io->newLine();

        return $ledger;
    }

    protected function loadOrCreateEmpty(): LedgerInterface
    {
        $existing = $this->repository->find($this->ledgerId);

        if ($existing !== null && $existing->unspent()->count() > 0) {
            $this->io->text("<fg=yellow>[Loaded existing ledger with {$existing->unspent()->count()} outputs]</>");
            $this->io->newLine();

            return $existing;
        }

        $this->io->text('<fg=green>[Created new empty ledger]</>');
        $this->io->newLine();

        return Ledger::inMemory();
    }

    protected function save(LedgerInterface $ledger): void
    {
        $this->repository->save($this->ledgerId, $ledger);
    }

    protected function showStats(LedgerInterface $ledger): void
    {
        $this->io->section('Database Stats');

        $dbPath = $this->getDbPath();
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM outputs WHERE ledger_id = ?');
        $stmt->execute([$this->ledgerId]);
        $outputCount = (int) $stmt->fetchColumn();

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM transactions WHERE ledger_id = ?');
        $stmt->execute([$this->ledgerId]);
        $txCount = (int) $stmt->fetchColumn();

        $this->io->listing([
            "Database: {$dbPath}",
            "Ledger: {$this->ledgerId}",
            "Outputs: {$outputCount}",
            "Transactions: {$txCount}",
        ]);

        $this->io->text('<fg=green>Run again to continue.</> Delete the DB file to reset.');
    }

    private function initDatabase(): void
    {
        $dbPath = $this->getDbPath();

        // Ensure data directory exists
        $dir = \dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->pdo = new PDO("sqlite:{$dbPath}");
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Use factory to ensure schema exists
        $this->repository = SqliteRepositoryFactory::createFromPdo($this->pdo);
    }
}
