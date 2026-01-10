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
    private const string DEFAULT_PATH = 'data/ledger.db';
    private const string DEFAULT_LEDGER_ID = 'main';

    protected function configure(): void
    {
        $this
            ->addOption(
                'path',
                'p',
                InputOption::VALUE_REQUIRED,
                'Database path',
                self::DEFAULT_PATH,
            )
            ->addOption(
                'ledger-id',
                'l',
                InputOption::VALUE_REQUIRED,
                'Ledger identifier',
                self::DEFAULT_LEDGER_ID,
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Recreate database even if it exists',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dbPath = $input->getOption('path');
        $ledgerId = $input->getOption('ledger-id');
        $force = $input->getOption('force');

        $io->title('Ledger Database Initialization');

        if (file_exists($dbPath)) {
            if (!$force) {
                $io->warning("Database already exists at: {$dbPath}");
                $io->text('Use --force to recreate it.');

                return Command::SUCCESS;
            }

            $io->text('Removing existing database...');
            unlink($dbPath);
        }

        $parentDir = dirname($dbPath);
        if (!is_dir($parentDir)) {
            $io->text("Creating directory: {$parentDir}");
            if (!mkdir($parentDir, 0755, true) && !is_dir($parentDir)) {
                $io->error("Failed to create directory: {$parentDir}");

                return Command::FAILURE;
            }
        }

        try {
            $io->text("Creating database: {$dbPath}");
            $pdo = new PDO("sqlite:{$dbPath}");
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('PRAGMA foreign_keys = ON');

            $io->text('Initializing schema...');
            $schema = new SqliteSchema($pdo);
            $schema->create();

            $io->text("Creating ledger record: {$ledgerId}");
            $stmt = $pdo->prepare(
                'INSERT INTO ledgers (id, version, total_unspent, total_fees, total_minted) VALUES (?, 1, 0, 0, 0)',
            );
            $stmt->execute([$ledgerId]);

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
                "\$ledger = ScalableLedger::create(\$store, ...genesis);",
            ]);

            return Command::SUCCESS;
        } catch (PDOException $e) {
            $io->error('Database error: ' . $e->getMessage());

            return Command::FAILURE;
        }
    }
}
