<?php

declare(strict_types=1);

/**
 * Loyalty Points Example - Customer Rewards Program
 *
 * Demonstrates UTXO-based tracking for loyalty points where
 * every point is traceable from earn to burn.
 *
 * Run with: php example/loyalty-points.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Chemaclass\Unspent\Coinbase;
use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\Spend;
use Chemaclass\Unspent\SpendId;

echo "==========================================================\n";
echo " Loyalty Points Example - Customer Rewards Program\n";
echo "==========================================================\n\n";

// ============================================================================
// 1. MINTING POINTS - Coinbase for Purchases
// ============================================================================

echo "1. MINTING POINTS - Points Earned from Purchases\n";
echo "------------------------------------------------\n";

$rewards = Ledger::empty();

// Customer alice makes a purchase and earns points (1 point per dollar)
$rewards = $rewards->applyCoinbase(Coinbase::create(
    outputs: [Output::ownedBy('alice', 50, 'purchase-001-points')],
    id: 'purchase-001',
));

echo "Alice buys \$50 worth of goods.\n";
echo "  Earned: 50 points (purchase-001)\n";
echo "  Total points minted: {$rewards->totalMinted()}\n\n";

// Second purchase
$rewards = $rewards->applyCoinbase(Coinbase::create(
    outputs: [Output::ownedBy('alice', 30, 'purchase-002-points')],
    id: 'purchase-002',
));

echo "Alice buys \$30 more.\n";
echo "  Earned: 30 points (purchase-002)\n";
echo "  Total points minted: {$rewards->totalMinted()}\n\n";

// Third purchase with bonus multiplier
$rewards = $rewards->applyCoinbase(Coinbase::create(
    outputs: [Output::ownedBy('alice', 40, 'purchase-003-points')], // $20 x2 bonus
    id: 'purchase-003',
));

echo "Alice buys \$20 during 2x points event.\n";
echo "  Earned: 40 points (purchase-003)\n";
echo "  Total points minted: {$rewards->totalMinted()}\n";
echo "  Alice's total points: {$rewards->totalUnspentAmount()}\n\n";

// ============================================================================
// 2. OWNERSHIP - Points Belong to Customers
// ============================================================================

echo "2. OWNERSHIP - Points Belong to Specific Customers\n";
echo "---------------------------------------------------\n";

// Bob also earns points
$rewards = $rewards->applyCoinbase(Coinbase::create(
    outputs: [Output::ownedBy('bob', 100, 'bob-purchase-points')],
    id: 'bob-purchase-001',
));

echo "Bob earns 100 points from his purchase.\n";
echo "  Total points in system: {$rewards->totalUnspentAmount()}\n\n";

// Show all customers' points
echo "Points by customer:\n";
$customerPoints = [];
foreach ($rewards->unspent() as $id => $output) {
    $lock = $output->lock->toArray();
    /** @phpstan-ignore isset.offset */
    $owner = isset($lock['name']) ? (string) $lock['name'] : 'system';
    $customerPoints[$owner] = ($customerPoints[$owner] ?? 0) + $output->amount;
}
foreach ($customerPoints as $customer => $points) {
    echo "  {$customer}: {$points} points\n";
}
echo "\n";

// ============================================================================
// 3. PROVENANCE - Track Which Purchase Earned Which Points
// ============================================================================

echo "3. PROVENANCE - Track Point Origins\n";
echo "------------------------------------\n";

echo "Alice's point batches:\n";
$aliceOutputs = ['purchase-001-points', 'purchase-002-points', 'purchase-003-points'];
foreach ($aliceOutputs as $outputId) {
    $history = $rewards->outputHistory(new OutputId($outputId));
    \assert($history !== null);
    $coinbaseId = $history['createdBy'] ?? 'unknown';
    $isCoinbase = $rewards->isCoinbase(new SpendId($coinbaseId)) ? 'Yes' : 'No';
    echo "  {$outputId}:\n";
    echo "    Amount: {$history['amount']} points\n";
    echo "    From purchase: {$coinbaseId}\n";
    echo "    Is minted (coinbase): {$isCoinbase}\n";
}
echo "\n";

// ============================================================================
// 4. REDEMPTION - Burn Points for Rewards
// ============================================================================

echo "4. REDEMPTION - Burn Points for Rewards\n";
echo "----------------------------------------\n";

// Alice redeems 60 points for a reward (combines two batches)
$rewards = $rewards->apply(Spend::create(
    inputIds: ['purchase-001-points', 'purchase-002-points'], // 50 + 30 = 80
    outputs: [
        Output::open(60, 'reward-coffee-voucher'), // Redeemed (burned to system)
        Output::ownedBy('alice', 20, 'alice-remaining'), // Change back
    ],
    signedBy: 'alice',
    id: 'redemption-001',
));

echo "Alice redeems 60 points for a coffee voucher.\n";
echo "  Used: purchase-001-points (50) + purchase-002-points (30)\n";
echo "  Redeemed: 60 points\n";
echo "  Change: 20 points returned to Alice\n";
echo "  Alice's remaining points: " . (20 + 40) . " (20 change + 40 from purchase-003)\n\n";

// ============================================================================
// 5. PARTIAL REDEMPTION - Use Some, Keep Rest
// ============================================================================

echo "5. PARTIAL REDEMPTION - Use Some, Keep Rest\n";
echo "--------------------------------------------\n";

// Alice uses some of her remaining points
$rewards = $rewards->apply(Spend::create(
    inputIds: ['alice-remaining', 'purchase-003-points'], // 20 + 40 = 60
    outputs: [
        Output::open(25, 'reward-discount-code'),
        Output::ownedBy('alice', 35, 'alice-final-balance'),
    ],
    signedBy: 'alice',
    id: 'redemption-002',
));

echo "Alice redeems 25 more points for a discount code.\n";
echo "  Used: 20 + 40 = 60 points\n";
echo "  Redeemed: 25 points\n";
echo "  Remaining: 35 points\n\n";

// ============================================================================
// 6. AUDIT TRAIL - Full Traceability
// ============================================================================

echo "6. AUDIT TRAIL - Full Point Lifecycle\n";
echo "--------------------------------------\n";

// Trace what happened to points from purchase-001
echo "Audit trail for purchase-001-points (originally 50 points):\n";
$outputId = new OutputId('purchase-001-points');
$created = $rewards->outputCreatedBy($outputId);
$spent = $rewards->outputSpentBy($outputId);
echo "  1. Created by: {$created} (coinbase minting)\n";
echo "  2. Spent in: {$spent}\n";

// What did redemption-001 create?
echo "\nredemption-001 created:\n";
$redemptionOutputs = ['reward-coffee-voucher', 'alice-remaining'];
foreach ($redemptionOutputs as $id) {
    $output = $rewards->getOutput(new OutputId($id));
    if ($output !== null) {
        $lockType = $output->lock->toArray()['type'];
        $status = $rewards->outputSpentBy(new OutputId($id)) !== null ? 'spent' : 'unspent';
        echo "  - {$id}: {$output->amount} points ({$lockType}, {$status})\n";
    }
}

// Track alice-remaining through its lifecycle
echo "\nFull chain for alice-remaining:\n";
$id = new OutputId('alice-remaining');
echo "  Created by: {$rewards->outputCreatedBy($id)}\n";
echo "  Spent by: {$rewards->outputSpentBy($id)}\n";
echo "\n";

// ============================================================================
// 7. REPORTING - Business Intelligence
// ============================================================================

echo "7. REPORTING - Business Intelligence\n";
echo "-------------------------------------\n";

echo "Program statistics:\n";
echo "  Total points ever minted: {$rewards->totalMinted()}\n";
echo "  Points currently in circulation: {$rewards->totalUnspentAmount()}\n";
echo '  Points burned/redeemed: ' . ($rewards->totalMinted() - $rewards->totalUnspentAmount()) . "\n";

// Count redemptions
$allFees = $rewards->allSpendFees();
echo '  Number of redemptions: ' . \count($allFees) . "\n";

// Calculate total redeemed
$totalRedeemed = 0;
$redemptionIds = ['reward-coffee-voucher', 'reward-discount-code'];
foreach ($redemptionIds as $id) {
    $output = $rewards->getOutput(new OutputId($id));
    if ($output !== null) {
        $totalRedeemed += $output->amount;
    }
}
echo "  Total points redeemed for rewards: {$totalRedeemed}\n\n";

// ============================================================================
// 8. SERIALIZATION - Persist Customer Points
// ============================================================================

echo "8. SERIALIZATION - Persist Customer Points\n";
echo "-------------------------------------------\n";

// Save state
$savedState = $rewards->toJson(JSON_PRETTY_PRINT);
echo "Program state saved.\n";

// Restore and verify
$restored = Ledger::fromJson($savedState);
echo "Program state restored!\n";
echo "  Points in circulation: {$restored->totalUnspentAmount()}\n";
echo "  Total minted: {$restored->totalMinted()}\n";

// Verify history survives serialization
$historyCheck = $restored->outputCreatedBy(new OutputId('alice-final-balance'));
echo "  History preserved: alice-final-balance created by {$historyCheck}\n";

// ============================================================================
// SUMMARY
// ============================================================================

echo "\n";
echo "==========================================================\n";
echo " Summary\n";
echo "==========================================================\n\n";

echo "Current point balances:\n";
foreach ($restored->unspent() as $id => $output) {
    $lock = $output->lock->toArray();
    /** @phpstan-ignore isset.offset */
    $owner = isset($lock['name']) ? (string) $lock['name'] : 'system';
    echo "  {$id}: {$output->amount} points ({$owner})\n";
}

echo "\nFeatures demonstrated:\n";
echo "  - Coinbase minting (points from purchases)\n";
echo "  - Customer ownership\n";
echo "  - Provenance tracking (which purchase = which points)\n";
echo "  - Redemption (burning points)\n";
echo "  - Partial redemption with change\n";
echo "  - Full audit trail\n";
echo "  - Business reporting\n";
echo "  - JSON serialization\n";

echo "\n";
