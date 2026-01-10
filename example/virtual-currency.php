<?php

declare(strict_types=1);

/**
 * Virtual Currency - In-Game Economy
 *
 * Shows how to track in-game gold with ownership, trades, and fees.
 *
 * Run: php example/virtual-currency.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Chemaclass\Unspent\Exception\AuthorizationException;
use Chemaclass\Unspent\Exception\OutputAlreadySpentException;
use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\Tx;
use Chemaclass\Unspent\TxId;

echo "Virtual Currency Example\n";
echo "========================\n\n";

// 1. Create game with starting gold
$game = Ledger::withGenesis(
    Output::ownedBy('alice', 1000, 'alice-gold'),
    Output::ownedBy('bob', 500, 'bob-gold'),
);
echo "Game started: Alice=1000g, Bob=500g\n";

// 2. Alice buys sword from shop
$game = $game->apply(Tx::create(
    spendIds: ['alice-gold'],
    outputs: [
        Output::ownedBy('shop', 200, 'shop-payment'),
        Output::ownedBy('alice', 800, 'alice-change'),
    ],
    signedBy: 'alice',
    id: 'buy-sword',
));
echo "Alice bought sword (-200g), now has 800g\n";

// 3. Theft attempt blocked
echo "\nMallory tries to steal Bob's gold... ";
try {
    $game->apply(Tx::create(
        spendIds: ['bob-gold'],
        outputs: [Output::ownedBy('mallory', 500)],
        signedBy: 'mallory',
    ));
} catch (AuthorizationException) {
    echo "BLOCKED\n";
}

// 4. Double-spend blocked
echo "Alice tries to spend already-spent gold... ";
try {
    $game->apply(Tx::create(
        spendIds: ['alice-gold'], // already spent!
        outputs: [Output::ownedBy('alice', 1000)],
        signedBy: 'alice',
    ));
} catch (OutputAlreadySpentException) {
    echo "BLOCKED\n";
}

// 5. Fees (gold sink)
$game = $game->apply(Tx::create(
    spendIds: ['bob-gold'],
    outputs: [Output::ownedBy('alice', 450)], // 50g fee
    signedBy: 'bob',
    id: 'bob-pays-alice',
));
echo "\nBob paid Alice 450g (50g fee/tax)\n";
echo "Fee collected: {$game->feeForTx(new TxId('bob-pays-alice'))}g\n";

// 6. History tracking
echo "\nAudit trail:\n";
$history = $game->outputHistory(new OutputId('alice-gold'));
echo "  alice-gold: created at genesis, spent in buy-sword\n";

// 7. Final state
echo "\nFinal balances:\n";
foreach ($game->unspent() as $id => $output) {
    $owner = $output->lock->toArray()['name'] ?? 'open';
    echo "  {$owner}: {$output->amount}g ({$id})\n";
}
echo "Total in circulation: {$game->totalUnspentAmount()}g\n";
echo "Total fees (burned): {$game->totalFeesCollected()}g\n";
