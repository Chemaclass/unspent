<?php

declare(strict_types=1);

/**
 * SQLite Persistence
 *
 * Shows how to save and query ledgers with the built-in SQLite backend.
 *
 * Run: php example/sqlite-persistence.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\Persistence\Sqlite\SqliteRepositoryFactory;
use Chemaclass\Unspent\Tx;

echo "SQLite Persistence Example\n";
echo "==========================\n\n";

// 1. Create repository
$repo = SqliteRepositoryFactory::createInMemory();
// For file: SqliteRepositoryFactory::createFromFile('ledger.db')

// 2. Create and save ledger
$ledger = Ledger::withGenesis(
    Output::ownedBy('alice', 1000, 'alice-funds'),
    Output::ownedBy('bob', 500, 'bob-funds'),
);
$repo->save('wallet-1', $ledger);
echo "Saved wallet-1 with 2 outputs\n";

// 3. Query by owner
$aliceOutputs = $repo->findUnspentByOwner('wallet-1', 'alice');
echo 'Alice has ' . \count($aliceOutputs) . " output(s)\n";
echo 'Alice balance: ' . $repo->sumUnspentByOwner('wallet-1', 'alice') . "\n\n";

// 4. Apply transaction and update
$ledger = $ledger->apply(Tx::create(
    spendIds: ['alice-funds'],
    outputs: [
        Output::ownedBy('charlie', 600, 'charlie-funds'),
        Output::ownedBy('alice', 390, 'alice-change'),
    ],
    signedBy: 'alice',
    id: 'tx-001',
));
$repo->save('wallet-1', $ledger);
echo "Applied tx-001: alice -> charlie (600), change (390), fee (10)\n";

// 5. Query by amount
$large = $repo->findUnspentByAmountRange('wallet-1', 500);
echo 'Outputs >= 500: ' . \count($large) . "\n";

// 6. History preserved after load
$loaded = $repo->find('wallet-1');
echo "\nHistory after reload:\n";
echo '  alice-funds created by: ' . $loaded?->outputCreatedBy(new OutputId('alice-funds')) . "\n";
echo '  alice-funds spent by: ' . $loaded?->outputSpentBy(new OutputId('alice-funds')) . "\n";

// 7. Multiple ledgers
echo "\nMultiple ledgers:\n";
echo '  wallet-1 exists: ' . ($repo->exists('wallet-1') ? 'yes' : 'no') . "\n";
echo '  wallet-2 exists: ' . ($repo->exists('wallet-2') ? 'yes' : 'no') . "\n";
