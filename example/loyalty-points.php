<?php

declare(strict_types=1);

/**
 * Loyalty Points - Customer Rewards Program
 *
 * Shows how to mint, track, and redeem loyalty points.
 *
 * Run: php example/loyalty-points.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Chemaclass\Unspent\CoinbaseTx;
use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\Tx;

echo "Loyalty Points Example\n";
echo "======================\n\n";

// 1. Earn points from purchases (minting via coinbase)
$rewards = Ledger::empty();

$rewards = $rewards->applyCoinbase(CoinbaseTx::create(
    outputs: [Output::ownedBy('alice', 50, 'purchase-001')],
    id: 'earn-50',
));
echo "Alice bought \$50 -> earned 50 pts\n";

$rewards = $rewards->applyCoinbase(CoinbaseTx::create(
    outputs: [Output::ownedBy('alice', 30, 'purchase-002')],
    id: 'earn-30',
));
echo "Alice bought \$30 -> earned 30 pts\n";

echo "Total points minted: {$rewards->totalMinted()}\n";
echo "Alice's balance: {$rewards->totalUnspentAmount()} pts\n\n";

// 2. Redeem points (combine outputs, burn value)
$rewards = $rewards->apply(Tx::create(
    spendIds: ['purchase-001', 'purchase-002'], // 50 + 30 = 80 pts
    outputs: [
        Output::open(60, 'coffee-voucher'),    // 60 pts redeemed
        Output::ownedBy('alice', 20, 'change'), // 20 pts change
    ],
    signedBy: 'alice',
    id: 'redeem-coffee',
));
echo "Alice redeemed 60 pts for coffee voucher\n";
echo "Remaining: {$rewards->totalUnspentAmount()} pts\n\n";

// 3. Audit trail - trace where points came from
echo "Audit trail:\n";
$history = $rewards->outputHistory(new OutputId('purchase-001'));
echo "  purchase-001: minted in earn-50, spent in redeem-coffee\n";

echo "\nFinal state:\n";
foreach ($rewards->unspent() as $id => $output) {
    $lockType = $output->lock->toArray()['type'];
    echo "  {$id}: {$output->amount} pts ({$lockType})\n";
}
