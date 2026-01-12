<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Feature\Console;

use Chemaclass\Unspent\Console\InitLedgerDbCommand;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class InitLedgerDbCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/unspent_test_' . uniqid('', true);
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function creates_database_with_default_ledger_id(): void
    {
        $dbPath = $this->tempDir . '/ledger.db';
        $tester = $this->createCommandTester();

        $exitCode = $tester->execute(['--path' => $dbPath]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertFileExists($dbPath);

        $pdo = $this->createPdo($dbPath);
        $ledger = $this->fetchLedger($pdo, 'main');

        self::assertIsArray($ledger);
        self::assertSame('main', $ledger['id']);
        self::assertSame(1, (int) $ledger['version']);
        self::assertSame(0, (int) $ledger['total_unspent']);
        self::assertSame(0, (int) $ledger['total_fees']);
        self::assertSame(0, (int) $ledger['total_minted']);
    }

    #[Test]
    public function creates_database_with_custom_ledger_id(): void
    {
        $dbPath = $this->tempDir . '/ledger.db';
        $tester = $this->createCommandTester();

        $exitCode = $tester->execute([
            '--path' => $dbPath,
            '--ledger-id' => 'my-wallet',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $pdo = $this->createPdo($dbPath);
        $ledger = $this->fetchLedger($pdo, 'my-wallet');

        self::assertIsArray($ledger);
        self::assertSame('my-wallet', $ledger['id']);
    }

    #[Test]
    public function creates_required_tables(): void
    {
        $dbPath = $this->tempDir . '/ledger.db';
        $tester = $this->createCommandTester();

        $tester->execute(['--path' => $dbPath]);

        $pdo = $this->createPdo($dbPath);
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
        self::assertNotFalse($stmt);
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

        self::assertContains('ledgers', $tables);
        self::assertContains('outputs', $tables);
        self::assertContains('transactions', $tables);
    }

    #[Test]
    public function warns_when_database_already_exists_without_force(): void
    {
        $dbPath = $this->tempDir . '/ledger.db';
        touch($dbPath);

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute(['--path' => $dbPath]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Database already exists', $tester->getDisplay());
        self::assertStringContainsString('--force', $tester->getDisplay());
    }

    #[Test]
    public function recreates_database_with_force_option(): void
    {
        $dbPath = $this->tempDir . '/ledger.db';
        $tester = $this->createCommandTester();

        // First creation
        $tester->execute(['--path' => $dbPath, '--ledger-id' => 'first']);

        // Add some data to verify it gets wiped
        $pdo = $this->createPdo($dbPath);
        $pdo->exec("UPDATE ledgers SET total_unspent = 1000 WHERE id = 'first'");

        // Force recreate with different ledger-id
        $tester->execute(['--path' => $dbPath, '--ledger-id' => 'second', '--force' => true]);

        $pdo = $this->createPdo($dbPath);

        // Old ledger should be gone
        $old = $this->fetchLedger($pdo, 'first');
        self::assertFalse($old);

        // New ledger should exist with fresh state
        $new = $this->fetchLedger($pdo, 'second');
        self::assertIsArray($new);
        self::assertSame('second', $new['id']);
        self::assertSame(0, (int) $new['total_unspent']);
    }

    #[Test]
    public function creates_parent_directory_if_not_exists(): void
    {
        $dbPath = $this->tempDir . '/nested/deep/ledger.db';
        $tester = $this->createCommandTester();

        $exitCode = $tester->execute(['--path' => $dbPath]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertFileExists($dbPath);
        self::assertDirectoryExists($this->tempDir . '/nested/deep');
    }

    #[Test]
    public function displays_success_message_with_usage_instructions(): void
    {
        $dbPath = $this->tempDir . '/ledger.db';
        $tester = $this->createCommandTester();

        $tester->execute(['--path' => $dbPath]);
        $output = $tester->getDisplay();

        self::assertStringContainsString('Database initialized successfully', $output);
        self::assertStringContainsString('SqliteHistoryStore', $output);
        self::assertStringContainsString('Ledger::withStore', $output);
    }

    #[Test]
    public function outputs_database_path_in_success_message(): void
    {
        $dbPath = $this->tempDir . '/custom-path.db';
        $tester = $this->createCommandTester();

        $tester->execute(['--path' => $dbPath]);
        $output = $tester->getDisplay();

        self::assertStringContainsString($dbPath, $output);
    }

    #[Test]
    public function outputs_ledger_id_in_success_message(): void
    {
        $dbPath = $this->tempDir . '/ledger.db';
        $tester = $this->createCommandTester();

        $tester->execute(['--path' => $dbPath, '--ledger-id' => 'wallet-123']);
        $output = $tester->getDisplay();

        self::assertStringContainsString('wallet-123', $output);
    }

    #[Test]
    public function command_has_correct_name_and_description(): void
    {
        $command = new InitLedgerDbCommand();

        self::assertSame('ledger:init', $command->getName());
        self::assertSame('Initialize the ledger SQLite database', $command->getDescription());
    }

    #[Test]
    public function command_has_correct_option_defaults(): void
    {
        self::assertSame('data/ledger.db', InitLedgerDbCommand::DEFAULT_PATH);
        self::assertSame('main', InitLedgerDbCommand::DEFAULT_LEDGER_ID);
    }

    private function createCommandTester(): CommandTester
    {
        $application = new Application();
        $application->addCommand(new InitLedgerDbCommand());

        $command = $application->find('ledger:init');

        return new CommandTester($command);
    }

    private function createPdo(string $dbPath): PDO
    {
        $pdo = new PDO("sqlite:{$dbPath}");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    /**
     * @return array<string, mixed>|false
     */
    private function fetchLedger(PDO $pdo, string $ledgerId): array|false
    {
        $stmt = $pdo->prepare('SELECT * FROM ledgers WHERE id = ?');
        $stmt->execute([$ledgerId]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff((array) scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
