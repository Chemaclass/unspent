<?php

declare(strict_types=1);

/**
 * Bitcoin Simulation - Multi-Block Mining
 *
 * Shows coinbase rewards, fees, and block mining simulation.
 *
 * Run: php example/bitcoin-simulation.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Chemaclass\Unspent\CoinbaseTx;
use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\Tx;
use Chemaclass\Unspent\TxId;

echo "Bitcoin Simulation\n";
echo "==================\n\n";

// Block 0: Genesis (Satoshi mines first block)
$ledger = Ledger::empty()->applyCoinbase(CoinbaseTx::create(
    [Output::open(50_00000000, 'satoshi-0')], // 50 BTC in sats
    'block-0',
));
echo "Block 0: Satoshi mines 50 BTC\n";

// Block 1: More mining
$ledger = $ledger->applyCoinbase(CoinbaseTx::create(
    [Output::open(50_00000000, 'satoshi-1')],
    'block-1',
));
echo "Block 1: Satoshi mines 50 BTC (total: 100 BTC)\n\n";

// Block 2: First transaction (Satoshi -> Hal)
$ledger = $ledger->apply(Tx::create(
    spendIds: ['satoshi-0'],
    outputs: [
        Output::open(10_00000000, 'hal-funds'),      // 10 BTC
        Output::open(39_99000000, 'satoshi-change'), // 39.99 BTC change
    ], // 0.01 BTC fee
    id: 'tx-to-hal',
));

$ledger = $ledger->applyCoinbase(CoinbaseTx::create(
    [Output::open(50_00000000, 'miner-2')],
    'block-2',
));
echo "Block 2: Satoshi sends 10 BTC to Hal\n";
echo '  Fee: ' . ($ledger->feeForTx(new TxId('tx-to-hal')) / 100000000) . " BTC\n\n";

// Block 3: Hal buys pizza
$ledger = $ledger->apply(Tx::create(
    spendIds: ['hal-funds'],
    outputs: [
        Output::open(5_00000000, 'laszlo-pizza'), // 5 BTC
        Output::open(4_99500000, 'hal-change'),   // 4.995 BTC
    ],
    id: 'tx-pizza',
));

$ledger = $ledger->applyCoinbase(CoinbaseTx::create(
    [Output::open(50_00000000, 'miner-3')],
    'block-3',
));
echo "Block 3: Hal buys pizza for 5 BTC\n\n";

// Block 4: Consolidate UTXOs
$ledger = $ledger->apply(Tx::create(
    spendIds: ['satoshi-1', 'satoshi-change', 'miner-2'],
    outputs: [Output::open(139_98000000, 'satoshi-consolidated')],
    id: 'tx-consolidate',
));

$ledger = $ledger->applyCoinbase(CoinbaseTx::create(
    [Output::open(50_00000000, 'miner-4')],
    'block-4',
));
echo "Block 4: Satoshi consolidates 3 UTXOs into 1\n\n";

// Final state
echo "Final State:\n";
echo "  Blocks mined: 5\n";
echo '  Total minted: ' . ($ledger->totalMinted() / 100000000) . " BTC\n";
echo '  Total fees: ' . ($ledger->totalFeesCollected() / 100000000) . " BTC\n";
echo '  In circulation: ' . ($ledger->totalUnspentAmount() / 100000000) . " BTC\n";
echo '  UTXOs: ' . $ledger->unspent()->count() . "\n\n";

echo "UTXOs:\n";
foreach ($ledger->unspent() as $id => $output) {
    $btc = $output->amount / 100000000;
    echo "  {$id}: {$btc} BTC\n";
}
