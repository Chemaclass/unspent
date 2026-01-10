<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Console;

use Chemaclass\Unspent\Persistence\Sqlite\SqliteSchema;
use PDO;
use PDOException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'ledger:init',
    description: 'Initialize the ledger SQLite database',
)]
final class InitLedgerDbCommand extends Command
{
    public const string DEFAULT_PATH = 'data/ledger.db';
    public const string DEFAULT_LEDGER_ID = 'main';

    protected function configure(): void
    {
        $this
            ->addOption('path', 'p', InputOption::VALUE_REQUIRED, 'Database path', self::DEFAULT_PATH)
            ->addOption('ledger-id', 'l', InputOption::VALUE_REQUIRED, 'Ledger identifier', self::DEFAULT_LEDGER_ID)
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Recreate database even if it exists');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dbPath = $input->getOption('path');
        $ledgerId = $input->getOption('ledger-id');
        $force = $input->getOption('force');

        $io->title('Ledger Database Initialization');

        if (!$this->handleExistingDatabase($io, $dbPath, $force)) {
            return Command::SUCCESS;
        }

        if (!$this->ensureDirectoryExists($io, \dirname($dbPath))) {
            return Command::FAILURE;
        }

        return $this->initializeDatabase($io, $dbPath, $ledgerId);
    }

    private function handleExistingDatabase(SymfonyStyle $io, string $dbPath, bool $force): bool
    {
        if (!file_exists($dbPath)) {
            return true;
        }

        if (!$force) {
            $io->warning("Database already exists at: {$dbPath}");
            $io->text('Use --force to recreate it.');

            return false;
        }

        $io->text('Removing existing database...');
        unlink($dbPath);

        return true;
    }

    private function ensureDirectoryExists(SymfonyStyle $io, string $directory): bool
    {
        if (is_dir($directory)) {
            return true;
        }

        $io->text("Creating directory: {$directory}");

        if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
            $io->error("Failed to create directory: {$directory}");

            return false;
        }

        return true;
    }

    private function initializeDatabase(SymfonyStyle $io, string $dbPath, string $ledgerId): int
    {
        try {
            $pdo = $this->createDatabase($io, $dbPath);
            $this->createSchema($io, $pdo);
            $this->createLedgerRecord($io, $pdo, $ledgerId);
            $this->displaySuccess($io, $dbPath, $ledgerId);

            return Command::SUCCESS;
        } catch (PDOException $e) {
            $io->error('Database error: ' . $e->getMessage());

            return Command::FAILURE;
        }
    }

    private function createDatabase(SymfonyStyle $io, string $dbPath): PDO
    {
        $io->text("Creating database: {$dbPath}");

        $pdo = new PDO("sqlite:{$dbPath}");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }

    private function createSchema(SymfonyStyle $io, PDO $pdo): void
    {
        $io->text('Initializing schema...');

        $schema = new SqliteSchema($pdo);
        $schema->create();
    }

    private function createLedgerRecord(SymfonyStyle $io, PDO $pdo, string $ledgerId): void
    {
        $io->text("Creating ledger record: {$ledgerId}");

        $stmt = $pdo->prepare(
            'INSERT INTO ledgers (id, version, total_unspent, total_fees, total_minted) VALUES (?, 1, 0, 0, 0)',
        );
        $stmt->execute([$ledgerId]);
    }

    private function displaySuccess(SymfonyStyle $io, string $dbPath, string $ledgerId): void
    {
        $io->success('Database initialized successfully!');
        $io->definitionList(
            ['Database' => $dbPath],
            ['Ledger ID' => $ledgerId],
            ['Tables' => 'ledgers, outputs, transactions'],
        );

        $io->section('Usage');
        $io->text([
            "\$pdo = new PDO('sqlite:{$dbPath}');",
            "\$store = new SqliteHistoryStore(\$pdo, '{$ledgerId}');",
            '$ledger = ScalableLedger::create($store, ...genesis);',
        ]);
    }
}
