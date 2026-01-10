<?php

declare(strict_types=1);

/**
 * SQLite Persistence Example - Database Storage with Queries
 *
 * Demonstrates the built-in SQLite persistence layer with
 * normalized column storage and efficient query capabilities.
 *
 * Run with: php example/sqlite-persistence.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Chemaclass\Unspent\CoinbaseTx;
use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\Persistence\Sqlite\SqliteRepositoryFactory;
use Chemaclass\Unspent\Tx;

echo "==========================================================\n";
echo " SQLite Persistence Example\n";
echo "==========================================================\n\n";

// ============================================================================
// 1. CREATE REPOSITORY
// ============================================================================

echo "1. CREATE REPOSITORY\n";
echo "--------------------\n";

// In-memory for this demo (use createFromFile for persistent storage)
$repo = SqliteRepositoryFactory::createInMemory();

echo "Created in-memory SQLite repository.\n";
echo "  For persistent storage: SqliteRepositoryFactory::createFromFile('ledger.db')\n\n";

// ============================================================================
// 2. CREATE AND SAVE LEDGER
// ============================================================================

echo "2. CREATE AND SAVE LEDGER\n";
echo "-------------------------\n";

$ledger = Ledger::withGenesis(
    Output::ownedBy('alice', 1000, 'alice-initial'),
    Output::ownedBy('bob', 500, 'bob-initial'),
    Output::ownedBy('alice', 300, 'alice-savings'),
);

$repo->save('wallet-1', $ledger);

echo "Saved ledger 'wallet-1' with 3 genesis outputs:\n";
echo "  alice-initial: 1000\n";
echo "  bob-initial: 500\n";
echo "  alice-savings: 300\n";
echo '  Total: ' . $ledger->totalUnspentAmount() . "\n\n";

// ============================================================================
// 3. QUERY BY OWNER
// ============================================================================

echo "3. QUERY BY OWNER\n";
echo "-----------------\n";

$aliceOutputs = $repo->findUnspentByOwner('wallet-1', 'alice');
echo 'Alice has ' . \count($aliceOutputs) . " unspent outputs:\n";
foreach ($aliceOutputs as $output) {
    echo '  ' . $output->id->value . ': ' . $output->amount . "\n";
}
echo '  Total balance: ' . $repo->sumUnspentByOwner('wallet-1', 'alice') . "\n\n";

// ============================================================================
// 4. APPLY TRANSACTIONS
// ============================================================================

echo "4. APPLY TRANSACTIONS\n";
echo "---------------------\n";

// Alice sends 600 to charlie, keeps 390 as change (10 fee)
$ledger = $ledger->apply(Tx::create(
    inputIds: ['alice-initial'],
    outputs: [
        Output::ownedBy('charlie', 600, 'charlie-funds'),
        Output::ownedBy('alice', 390, 'alice-change'),
    ],
    signedBy: 'alice',
    id: 'tx-alice-to-charlie',
));

// Add a coinbase (mining reward)
$ledger = $ledger->applyCoinbase(CoinbaseTx::create(
    outputs: [Output::ownedBy('miner', 50, 'block-reward')],
    id: 'coinbase-1',
));

// Save updated state
$repo->save('wallet-1', $ledger);

echo "Applied transactions:\n";
echo "  tx-alice-to-charlie: alice -> charlie (600), alice change (390), fee (10)\n";
echo "  coinbase-1: miner reward (50)\n\n";

// ============================================================================
// 5. QUERY BY AMOUNT RANGE
// ============================================================================

echo "5. QUERY BY AMOUNT RANGE\n";
echo "------------------------\n";

$largeOutputs = $repo->findUnspentByAmountRange('wallet-1', 400);
echo "Outputs >= 400:\n";
foreach ($largeOutputs as $output) {
    echo '  ' . $output->id->value . ': ' . $output->amount . "\n";
}

$smallOutputs = $repo->findUnspentByAmountRange('wallet-1', 1, 100);
echo "Outputs 1-100:\n";
foreach ($smallOutputs as $output) {
    echo '  ' . $output->id->value . ': ' . $output->amount . "\n";
}
echo "\n";

// ============================================================================
// 6. QUERY BY LOCK TYPE
// ============================================================================

echo "6. QUERY BY LOCK TYPE\n";
echo "---------------------\n";

$ownerLocked = $repo->findUnspentByLockType('wallet-1', 'owner');
echo 'Outputs with owner lock: ' . \count($ownerLocked) . "\n";

// Create a ledger with open outputs
$openLedger = Ledger::withGenesis(
    Output::open(100, 'open-funds'),
    Output::ownedBy('dave', 200, 'dave-funds'),
);
$repo->save('wallet-2', $openLedger);

$openOutputs = $repo->findUnspentByLockType('wallet-2', 'none');
echo "Open outputs in wallet-2: {$openOutputs[0]->id->value} ({$openOutputs[0]->amount})\n\n";

// ============================================================================
// 7. TRANSACTION QUERIES
// ============================================================================

echo "7. TRANSACTION QUERIES\n";
echo "----------------------\n";

$coinbases = $repo->findCoinbaseTransactions('wallet-1');
echo 'Coinbase transactions: ' . implode(', ', $coinbases) . "\n";

$feeTxs = $repo->findTransactionsByFeeRange('wallet-1', 1);
echo "Transactions with fees:\n";
foreach ($feeTxs as $tx) {
    echo '  ' . $tx->id . ': fee=' . $tx->fee . "\n";
}
echo "\n";

// ============================================================================
// 8. HISTORY PRESERVATION
// ============================================================================

echo "8. HISTORY PRESERVATION\n";
echo "-----------------------\n";

$loaded = $repo->find('wallet-1');
\assert($loaded !== null);

echo "History preserved after save/load:\n";
echo '  alice-initial created by: ' . $loaded->outputCreatedBy(new Chemaclass\Unspent\OutputId('alice-initial')) . "\n";
echo '  alice-initial spent by: ' . $loaded->outputSpentBy(new Chemaclass\Unspent\OutputId('alice-initial')) . "\n";
echo '  charlie-funds created by: ' . $loaded->outputCreatedBy(new Chemaclass\Unspent\OutputId('charlie-funds')) . "\n";
echo '  Total fees: ' . $loaded->totalFeesCollected() . "\n";
echo '  Total minted: ' . $loaded->totalMinted() . "\n\n";

// ============================================================================
// 9. AGGREGATIONS
// ============================================================================

echo "9. AGGREGATIONS\n";
echo "---------------\n";

echo 'Total unspent outputs: ' . $repo->countUnspent('wallet-1') . "\n";
echo 'Alice balance: ' . $repo->sumUnspentByOwner('wallet-1', 'alice') . "\n";
echo 'Bob balance: ' . $repo->sumUnspentByOwner('wallet-1', 'bob') . "\n";
echo 'Charlie balance: ' . $repo->sumUnspentByOwner('wallet-1', 'charlie') . "\n";
echo 'Miner balance: ' . $repo->sumUnspentByOwner('wallet-1', 'miner') . "\n\n";

// ============================================================================
// 10. MULTIPLE LEDGERS
// ============================================================================

echo "10. MULTIPLE LEDGERS\n";
echo "--------------------\n";

echo 'wallet-1 exists: ' . ($repo->exists('wallet-1') ? 'true' : 'false') . "\n";
echo 'wallet-2 exists: ' . ($repo->exists('wallet-2') ? 'true' : 'false') . "\n";
echo 'wallet-3 exists: ' . ($repo->exists('wallet-3') ? 'true' : 'false') . "\n\n";

// Delete wallet-2
$repo->delete('wallet-2');
echo "Deleted wallet-2.\n";
echo 'wallet-2 exists: ' . ($repo->exists('wallet-2') ? 'true' : 'false') . "\n\n";

// ============================================================================
// SUMMARY
// ============================================================================

echo "==========================================================\n";
echo " Summary\n";
echo "==========================================================\n\n";

echo "SQLite persistence features:\n";
echo "  - Normalized column storage (not JSON blobs)\n";
echo "  - Indexed queries for efficient lookups\n";
echo "  - Query by owner, amount range, lock type\n";
echo "  - Transaction fee and coinbase queries\n";
echo "  - History preservation (created_by, spent_by)\n";
echo "  - Multiple isolated ledgers in one database\n\n";

echo "Factory methods:\n";
echo "  SqliteRepositoryFactory::createInMemory()      - Testing\n";
echo "  SqliteRepositoryFactory::createFromFile(path)  - Production\n";
echo "  SqliteRepositoryFactory::createFromPdo(pdo)    - Custom PDO\n\n";
