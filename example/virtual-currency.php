<?php

declare(strict_types=1);

/**
 * Virtual Currency Example - In-Game Economy
 *
 * Demonstrates UTXO-based tracking for virtual currencies like
 * in-game gold, tokens, or credits.
 *
 * Run with: php example/virtual-currency.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Chemaclass\Unspent\Exception\AuthorizationException;
use Chemaclass\Unspent\Exception\OutputAlreadySpentException;
use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\Tx;
use Chemaclass\Unspent\TxId;

echo "==========================================================\n";
echo " Virtual Currency Example - In-Game Economy\n";
echo "==========================================================\n\n";

// ============================================================================
// 1. GENESIS - Initial Gold Distribution
// ============================================================================

echo "1. GENESIS - Initial Gold Distribution\n";
echo "--------------------------------------\n";

$game = Ledger::withGenesis(
    Output::ownedBy('alice', 1000, 'alice-starting-gold'),
    Output::ownedBy('bob', 500, 'bob-starting-gold'),
    Output::ownedBy('shop', 5000, 'shop-inventory'),
);

echo "New players join the game:\n";
echo "  - Alice: 1000 gold\n";
echo "  - Bob: 500 gold\n";
echo "  - Shop: 5000 gold (inventory)\n";
echo "Total gold in circulation: {$game->totalUnspentAmount()}\n\n";

// ============================================================================
// 2. OWNERSHIP - Players Control Their Gold
// ============================================================================

echo "2. OWNERSHIP - Players Control Their Gold\n";
echo "-----------------------------------------\n";

// Alice can spend her gold
$game = $game->apply(Tx::create(
    inputIds: ['alice-starting-gold'],
    outputs: [
        Output::ownedBy('shop', 200, 'payment-for-sword'),
        Output::ownedBy('alice', 800, 'alice-after-sword'),
    ],
    signedBy: 'alice',
    id: 'buy-sword',
));

echo "Alice buys a sword for 200 gold.\n";
echo "  Alice now has: 800 gold\n";

// Mallory tries to steal Bob's gold - FAILS
echo "\nMallory tries to steal Bob's gold...\n";
try {
    $game->apply(Tx::create(
        inputIds: ['bob-starting-gold'],
        outputs: [Output::ownedBy('mallory', 500, 'stolen')],
        signedBy: 'mallory',
        id: 'theft-attempt',
    ));
} catch (AuthorizationException $e) {
    echo "  BLOCKED: {$e->getMessage()}\n";
}
echo "\n";

// ============================================================================
// 3. PLAYER-TO-PLAYER TRADES
// ============================================================================

echo "3. PLAYER-TO-PLAYER TRADES\n";
echo "--------------------------\n";

// Bob pays Alice 100 gold for quest help
$game = $game->apply(Tx::create(
    inputIds: ['bob-starting-gold'],
    outputs: [
        Output::ownedBy('alice', 100, 'quest-reward'),
        Output::ownedBy('bob', 400, 'bob-after-trade'),
    ],
    signedBy: 'bob',
    id: 'quest-payment',
));

echo "Bob pays Alice 100 gold for quest help.\n";
echo "  Bob: 500 -> 400 gold\n";
echo "  Alice: 800 -> 900 gold (800 + 100 reward)\n\n";

// ============================================================================
// 4. MULTI-INPUT - Combining Gold for Large Purchases
// ============================================================================

echo "4. MULTI-INPUT - Combining Gold for Large Purchases\n";
echo "----------------------------------------------------\n";

// Alice combines her gold piles to buy expensive armor
$game = $game->apply(Tx::create(
    inputIds: ['alice-after-sword', 'quest-reward'],
    outputs: [
        Output::ownedBy('shop', 850, 'payment-for-armor'),
        Output::ownedBy('alice', 50, 'alice-after-armor'),
    ],
    signedBy: 'alice',
    id: 'buy-armor',
));

echo "Alice combines 800 + 100 gold to buy armor (850g).\n";
echo "  Alice now has: 50 gold (change)\n";
echo "  Shop received: 850 gold\n\n";

// ============================================================================
// 5. FEES - Game Tax / Gold Sink
// ============================================================================

echo "5. FEES - Game Tax (Gold Sink)\n";
echo "------------------------------\n";

// Shop sells item with 5% tax (implicit fee)
$shopBalance = 5000 + 200 + 850; // Initial + sword + armor
$game = $game->apply(Tx::create(
    inputIds: ['shop-inventory', 'payment-for-sword', 'payment-for-armor'],
    outputs: [
        Output::ownedBy('bob', 100, 'bob-potion'),
        Output::ownedBy('shop', 5645, 'shop-after-sale'), // 6050 - 100 - 5% tax
    ],
    signedBy: 'shop',
    id: 'shop-sale-with-tax',
));

$fee = $game->feeForTx(new TxId('shop-sale-with-tax'));
echo "Shop sells potion to Bob (100g) with 305g tax/sink.\n";
echo "  Transaction fee (gold removed from game): {$fee} gold\n";
echo "  Total fees collected (gold sink): {$game->totalFeesCollected()} gold\n";
echo "  Gold in circulation: {$game->totalUnspentAmount()} gold\n\n";

// ============================================================================
// 6. DOUBLE-SPEND PREVENTION
// ============================================================================

echo "6. DOUBLE-SPEND PREVENTION\n";
echo "--------------------------\n";

echo "Alice tries to spend her starting gold again (already spent)...\n";
try {
    $game->apply(Tx::create(
        inputIds: ['alice-starting-gold'], // Already spent!
        outputs: [Output::ownedBy('alice', 1000, 'double-spend')],
        signedBy: 'alice',
        id: 'cheat-attempt',
    ));
} catch (OutputAlreadySpentException $e) {
    echo "  BLOCKED: {$e->getMessage()}\n";
}
echo "\n";

// ============================================================================
// 7. HISTORY - Track Gold Provenance
// ============================================================================

echo "7. HISTORY - Track Gold Provenance\n";
echo "-----------------------------------\n";

// Where did Bob's potion gold originally come from?
echo "Tracing Bob's potion output back to genesis:\n";

$outputId = new OutputId('bob-potion');
$history = $game->outputHistory($outputId);
\assert($history !== null);
echo "  Output: bob-potion (100 gold)\n";
echo "  Created by: {$history->createdBy}\n";
echo "  Status: {$history->status->value}\n";

// Trace the chain
echo "\nFull chain of custody for Alice's gold:\n";
$aliceOutputs = ['alice-starting-gold', 'alice-after-sword', 'alice-after-armor'];
foreach ($aliceOutputs as $id) {
    $created = $game->outputCreatedBy(new OutputId($id));
    $spent = $game->outputSpentBy(new OutputId($id));
    $status = $spent !== null ? "spent in {$spent}" : 'unspent';
    echo "  {$id}: created by {$created}, {$status}\n";
}
echo "\n";

// ============================================================================
// 8. SERIALIZATION - Save/Load Game State
// ============================================================================

echo "8. SERIALIZATION - Save/Load Game State\n";
echo "----------------------------------------\n";

// Save game state
$savedState = $game->toJson();
echo 'Game state saved to JSON (' . \strlen($savedState) . " bytes)\n";

// Simulate game restart
$restoredGame = Ledger::fromJson($savedState);
echo "Game state restored!\n";
echo "  Total gold: {$restoredGame->totalUnspentAmount()}\n";
echo "  Total fees: {$restoredGame->totalFeesCollected()}\n";

// Verify ownership still works after restore
echo "\nVerifying ownership survives save/load...\n";
try {
    $restoredGame->apply(Tx::create(
        inputIds: ['bob-after-trade'],
        outputs: [Output::ownedBy('hacker', 400, 'hack')],
        signedBy: 'hacker',
        id: 'post-restore-hack',
    ));
} catch (AuthorizationException) {
    echo "  Ownership still enforced after restore!\n";
}

// ============================================================================
// SUMMARY
// ============================================================================

echo "\n";
echo "==========================================================\n";
echo " Summary\n";
echo "==========================================================\n\n";

echo "Final game state:\n";
foreach ($restoredGame->unspent() as $id => $output) {
    $lock = $output->lock->toArray();
    /** @phpstan-ignore isset.offset */
    $owner = isset($lock['name']) ? (string) $lock['name'] : 'open';
    echo "  {$id}: {$output->amount} gold (owned by: {$owner})\n";
}

echo "\nFeatures demonstrated:\n";
echo "  - Genesis distribution\n";
echo "  - Ownership (authorization)\n";
echo "  - Player-to-player trades\n";
echo "  - Multi-input transactions\n";
echo "  - Change outputs\n";
echo "  - Fees (gold sink)\n";
echo "  - Double-spend prevention\n";
echo "  - History/provenance tracking\n";
echo "  - JSON serialization\n";

echo "\n";
