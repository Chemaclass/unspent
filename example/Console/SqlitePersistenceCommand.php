<?php

declare(strict_types=1);

namespace Example\Console;

use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\Persistence\Sqlite\SqliteHistoryStore;
use Chemaclass\Unspent\Persistence\Sqlite\SqliteLedgerRepository;
use Chemaclass\Unspent\ScalableLedger;
use Chemaclass\Unspent\Tx;
use PDO;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'sample:sqlite-persistence',
    description: 'SQLite Persistence - Database Storage Demo',
    aliases: ['sqlite'],
)]
final class SqlitePersistenceCommand extends Command
{
    private const string DB_PATH = __DIR__ . '/../../data/ledger.db';
    private const string LEDGER_ID = 'sqlite-persistence';

    private SymfonyStyle $io;
    private PDO $pdo;
    private SqliteHistoryStore $store;
    private SqliteLedgerRepository $repo;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);
        $this->io->title('SQLite Persistence Example');

        if (!$this->connect()) {
            return Command::FAILURE;
        }

        $ledger = $this->loadOrCreateLedger();
        $ledger = $this->applyTransaction($ledger);
        $this->showQueryExamples();
        $this->showHistoryTracking($ledger);
        $this->showLedgerSummary($ledger);
        $this->showDatabaseStats();

        $this->io->text('Run again to add more transactions.');

        return Command::SUCCESS;
    }

    private function connect(): bool
    {
        if (!file_exists(self::DB_PATH)) {
            $this->io->error("Database not found. Run 'composer init-db' first.");
            return false;
        }

        $this->pdo = new PDO('sqlite:' . self::DB_PATH);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->store = new SqliteHistoryStore($this->pdo, self::LEDGER_ID);
        $this->repo = new SqliteLedgerRepository($this->pdo);

        $this->io->text('Connected to: ' . self::DB_PATH);
        $this->io->text('Ledger ID: ' . self::LEDGER_ID);
        $this->io->newLine();

        return true;
    }

    private function loadOrCreateLedger(): Ledger
    {
        $existingData = $this->repo->findUnspentOnly(self::LEDGER_ID);

        if ($existingData !== null && $existingData['unspentSet']->count() > 0) {
            $this->io->text("<fg=yellow>Loaded existing ledger with {$existingData['unspentSet']->count()} outputs</>");
            $this->io->newLine();

            return ScalableLedger::fromUnspentSet(
                $existingData['unspentSet'],
                $this->store,
                $existingData['totalFees'],
                $existingData['totalMinted'],
            );
        }

        $this->io->text('<fg=green>Creating new ledger...</>');

        $stmt = $this->pdo->prepare(
            'INSERT OR IGNORE INTO ledgers (id, version, total_unspent, total_fees, total_minted) VALUES (?, 1, 0, 0, 0)',
        );
        $stmt->execute([self::LEDGER_ID]);

        $ledger = ScalableLedger::create(
            $this->store,
            Output::ownedBy('alice', 1000, 'alice-initial'),
            Output::ownedBy('bob', 500, 'bob-initial'),
        );

        $this->io->text('Created ledger with genesis outputs for alice (1000) and bob (500)');
        $this->io->newLine();

        return $ledger;
    }

    private function applyTransaction(Ledger $ledger): Ledger
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM transactions WHERE ledger_id = ?');
        $stmt->execute([self::LEDGER_ID]);
        $txNum = (int) $stmt->fetchColumn() + 1;
        $txId = "example-tx-{$txNum}";

        $aliceOutputs = $this->repo->findUnspentByOwner(self::LEDGER_ID, 'alice');

        if (empty($aliceOutputs)) {
            $this->io->text('No outputs owned by alice, skipping transaction.');
            $this->io->newLine();
            return $ledger;
        }

        $outputToSpend = $aliceOutputs[0];
        $amount = $outputToSpend->amount;

        if ($amount < 100) {
            $this->io->text("Alice's output too small to split, skipping transaction.");
            $this->io->newLine();
            return $ledger;
        }

        $fee = max(1, (int) ($amount * 0.01));
        $toCharlie = (int) (($amount - $fee) * 0.5);
        $toAlice = $amount - $fee - $toCharlie;

        $this->io->section("Transaction {$txId}");
        $this->io->text("Alice spends: {$outputToSpend->id->value} ({$amount})");

        $ledger = $ledger->apply(Tx::create(
            spendIds: [$outputToSpend->id->value],
            outputs: [
                Output::ownedBy('charlie', $toCharlie, "charlie-{$txNum}"),
                Output::ownedBy('alice', $toAlice, "alice-change-{$txNum}"),
            ],
            signedBy: 'alice',
            id: $txId,
        ));

        $this->io->listing([
            "Created: charlie-{$txNum} ({$toCharlie}), alice-change-{$txNum} ({$toAlice})",
            "Fee: {$fee}",
        ]);

        return $ledger;
    }

    private function showQueryExamples(): void
    {
        $this->io->section('Query Examples');

        $aliceBalance = $this->repo->sumUnspentByOwner(self::LEDGER_ID, 'alice');
        $bobBalance = $this->repo->sumUnspentByOwner(self::LEDGER_ID, 'bob');
        $charlieBalance = $this->repo->sumUnspentByOwner(self::LEDGER_ID, 'charlie');

        $this->io->text('Balances:');
        $this->io->listing([
            "alice: {$aliceBalance}",
            "bob: {$bobBalance}",
            "charlie: {$charlieBalance}",
        ]);

        $largeOutputs = $this->repo->findUnspentByAmountRange(self::LEDGER_ID, 100);
        $this->io->text('Outputs >= 100: ' . \count($largeOutputs));

        $ownerLocked = $this->repo->findUnspentByLockType(self::LEDGER_ID, 'owner');
        $this->io->text('Owner-locked outputs: ' . \count($ownerLocked));
    }

    private function showHistoryTracking(Ledger $ledger): void
    {
        $this->io->section('History Tracking');

        $aliceHistory = $ledger->outputHistory(new OutputId('alice-initial'));
        if ($aliceHistory !== null) {
            $this->io->text('alice-initial:');
            $this->io->listing([
                "Amount: {$aliceHistory->amount}",
                "Status: {$aliceHistory->status->value}",
                'Created by: ' . ($aliceHistory->createdBy ?? 'genesis'),
                'Spent by: ' . ($aliceHistory->spentBy ?? '-'),
            ]);
        }
    }

    private function showLedgerSummary(Ledger $ledger): void
    {
        $this->io->section('Ledger Summary');
        $this->io->listing([
            "Total unspent: {$ledger->totalUnspentAmount()}",
            "Total fees: {$ledger->totalFeesCollected()}",
            "Total minted: {$ledger->totalMinted()}",
            "Unspent count: {$ledger->unspent()->count()}",
        ]);
    }

    private function showDatabaseStats(): void
    {
        $this->io->section('Database Stats');

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM outputs WHERE ledger_id = ?');
        $stmt->execute([self::LEDGER_ID]);
        $outputCount = (int) $stmt->fetchColumn();

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM transactions WHERE ledger_id = ?');
        $stmt->execute([self::LEDGER_ID]);
        $txCount = (int) $stmt->fetchColumn();

        $this->io->listing([
            "Outputs in DB: {$outputCount}",
            "Transactions in DB: {$txCount}",
        ]);
    }
}
