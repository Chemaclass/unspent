<?php

declare(strict_types=1);

/**
 * SQLite Persistence Example
 *
 * Demonstrates persistent storage with SQLite using data/ledger.db.
 * Run `composer init-db` first to create the database.
 *
 * Usage:
 *   composer init-db              # Create database (first time)
 *   php example/sqlite-persistence.php
 *   composer init-db -- --force   # Reset database
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Chemaclass\Unspent\CoinbaseTx;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\Persistence\Sqlite\SqliteHistoryStore;
use Chemaclass\Unspent\Persistence\Sqlite\SqliteLedgerRepository;
use Chemaclass\Unspent\ScalableLedger;
use Chemaclass\Unspent\Tx;
use Chemaclass\Unspent\TxId;

echo "SQLite Persistence Example\n";
echo "==========================\n\n";

// =============================================================================
// 1. Connect to Database
// =============================================================================

$dbPath = __DIR__ . '/../data/ledger.db';
$ledgerId = 'example';

if (!file_exists($dbPath)) {
    echo "Database not found. Run 'composer init-db' first.\n";
    exit(1);
}

$pdo = new PDO("sqlite:{$dbPath}");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$store = new SqliteHistoryStore($pdo, $ledgerId);
$repo = new SqliteLedgerRepository($pdo);

echo "Connected to: {$dbPath}\n";
echo "Ledger ID: {$ledgerId}\n\n";

// =============================================================================
// 2. Load or Create Ledger
// =============================================================================

$existingData = $repo->findUnspentOnly($ledgerId);

if ($existingData !== null && $existingData['unspentSet']->count() > 0) {
    // Load existing ledger
    $ledger = ScalableLedger::fromUnspentSet(
        $existingData['unspentSet'],
        $store,
        $existingData['totalFees'],
        $existingData['totalMinted'],
    );
    echo "Loaded existing ledger with {$ledger->unspent()->count()} outputs\n\n";
} else {
    // Create new ledger with genesis
    echo "Creating new ledger...\n";

    // Insert ledger record if not exists
    $pdo->exec("INSERT OR IGNORE INTO ledgers (id, version, total_unspent, total_fees, total_minted)
                VALUES ('{$ledgerId}', 1, 0, 0, 0)");

    $ledger = ScalableLedger::create(
        $store,
        Output::ownedBy('alice', 1000, 'alice-initial'),
        Output::ownedBy('bob', 500, 'bob-initial'),
    );
    echo "Created ledger with genesis outputs for alice (1000) and bob (500)\n\n";
}

// =============================================================================
// 3. Apply Transactions
// =============================================================================

// Generate unique transaction ID based on count
$txCount = (int) $pdo->query("SELECT COUNT(*) FROM transactions WHERE ledger_id = '{$ledgerId}'")->fetchColumn();
$txNum = $txCount + 1;
$txId = "example-tx-{$txNum}";

// Find an output owned by alice to spend
$aliceOutputs = $repo->findUnspentByOwner($ledgerId, 'alice');

if (!empty($aliceOutputs)) {
    $outputToSpend = $aliceOutputs[0];
    $amount = $outputToSpend->amount;

    if ($amount >= 100) {
        $fee = max(1, (int) ($amount * 0.01));
        $toCharlie = (int) (($amount - $fee) * 0.5);
        $toAlice = $amount - $fee - $toCharlie;

        echo "Transaction {$txId}:\n";
        echo "  Alice spends: {$outputToSpend->id->value} ({$amount})\n";

        $ledger = $ledger->apply(Tx::create(
            spendIds: [$outputToSpend->id->value],
            outputs: [
                Output::ownedBy('charlie', $toCharlie, "charlie-{$txNum}"),
                Output::ownedBy('alice', $toAlice, "alice-change-{$txNum}"),
            ],
            signedBy: 'alice',
            id: $txId,
        ));

        echo "  Created: charlie-{$txNum} ({$toCharlie}), alice-change-{$txNum} ({$toAlice})\n";
        echo "  Fee: {$fee}\n\n";
    } else {
        echo "Alice's output too small to split, skipping transaction.\n\n";
    }
} else {
    echo "No outputs owned by alice, skipping transaction.\n\n";
}

// =============================================================================
// 4. Query Capabilities
// =============================================================================

echo "Query Examples:\n";
echo "---------------\n";

// Query by owner
$aliceBalance = $repo->sumUnspentByOwner($ledgerId, 'alice');
$bobBalance = $repo->sumUnspentByOwner($ledgerId, 'bob');
$charlieBalance = $repo->sumUnspentByOwner($ledgerId, 'charlie');

echo "Balances:\n";
echo "  alice: {$aliceBalance}\n";
echo "  bob: {$bobBalance}\n";
echo "  charlie: {$charlieBalance}\n\n";

// Query by amount range
$largeOutputs = $repo->findUnspentByAmountRange($ledgerId, 100);
echo "Outputs >= 100: " . count($largeOutputs) . "\n";

// Query by lock type
$ownerLocked = $repo->findUnspentByLockType($ledgerId, 'owner');
echo "Owner-locked outputs: " . count($ownerLocked) . "\n\n";

// =============================================================================
// 5. History Tracking
// =============================================================================

echo "History Tracking:\n";
echo "-----------------\n";

// Check history for alice-initial if it exists
$aliceHistory = $ledger->outputHistory(new OutputId('alice-initial'));
if ($aliceHistory !== null) {
    echo "alice-initial:\n";
    echo "  Amount: {$aliceHistory->amount}\n";
    echo "  Status: {$aliceHistory->status->value}\n";
    echo "  Created by: " . ($aliceHistory->createdBy ?? 'genesis') . "\n";
    echo "  Spent by: " . ($aliceHistory->spentBy ?? '-') . "\n";
}

// =============================================================================
// 6. Ledger State Summary
// =============================================================================

echo "\nLedger Summary:\n";
echo "---------------\n";
echo "Total unspent: {$ledger->totalUnspentAmount()}\n";
echo "Total fees: {$ledger->totalFeesCollected()}\n";
echo "Total minted: {$ledger->totalMinted()}\n";
echo "Unspent count: {$ledger->unspent()->count()}\n";

// =============================================================================
// 7. Database Stats
// =============================================================================

echo "\nDatabase Stats:\n";
echo "---------------\n";
$outputCount = (int) $pdo->query("SELECT COUNT(*) FROM outputs WHERE ledger_id = '{$ledgerId}'")->fetchColumn();
$newTxCount = (int) $pdo->query("SELECT COUNT(*) FROM transactions WHERE ledger_id = '{$ledgerId}'")->fetchColumn();
echo "Outputs in DB: {$outputCount}\n";
echo "Transactions in DB: {$newTxCount}\n";

echo "\nRun again to add more transactions.\n";
