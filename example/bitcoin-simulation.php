<?php

declare(strict_types=1);

/**
 * Bitcoin Simulation Example
 *
 * This example simulates a simplified Bitcoin-like system to demonstrate
 * how the unspent library works in practice. We'll follow a small network
 * through several blocks of activity.
 *
 * Run with: php example/bitcoin-simulation.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Chemaclass\Unspent\CoinbaseTx;
use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\Tx;
use Chemaclass\Unspent\TxId;

// =============================================================================
// HELPER FUNCTIONS
// =============================================================================

function btc(int $satoshis): string
{
    $btcAmount = $satoshis / 100_000_000;
    $satsFormatted = number_format($satoshis, 0, '', '.');

    if ($btcAmount == floor($btcAmount)) {
        $btcFormatted = number_format($btcAmount, 0);
    } else {
        $btcFormatted = rtrim(number_format($btcAmount, 8, '.', ''), '0');
    }

    return "{$btcFormatted} BTC ({$satsFormatted} sats)";
}

function printHeader(string $title): void
{
    echo "\n" . str_repeat('━', 60) . "\n";
    echo "  {$title}\n";
    echo str_repeat('━', 60) . "\n\n";
}

function printLedgerState(Ledger $ledger): void
{
    echo "  📊 Ledger State:\n";
    echo '     Total minted:    ' . btc($ledger->totalMinted()) . "\n";
    echo '     Total fees:      ' . btc($ledger->totalFeesCollected()) . "\n";
    echo '     In circulation:  ' . btc($ledger->totalUnspentAmount()) . "\n";
    echo "     UTXOs:           {$ledger->unspent()->count()}\n\n";
}

function printUTXOs(Ledger $ledger): void
{
    echo "  💰 Unspent Outputs (UTXOs):\n";
    foreach ($ledger->unspent() as $id => $output) {
        echo "     [{$id}] → " . btc($output->amount) . "\n";
    }
    echo "\n";
}

// =============================================================================
// THE SIMULATION BEGINS
// =============================================================================

echo "\n";
echo "  ╔══════════════════════════════════════════════════════════╗\n";
echo "  ║          BITCOIN SIMULATION WITH UNSPENT LIBRARY         ║\n";
echo "  ║                                                          ║\n";
echo "  ║  Following a small network through blocks and txs        ║\n";
echo "  ╚══════════════════════════════════════════════════════════╝\n";

// =============================================================================
// BLOCK 0: GENESIS
// =============================================================================

printHeader('BLOCK 0: Genesis');

echo "  In the beginning, there was nothing. Then Satoshi mined the\n";
echo "  first block, creating 50 BTC out of thin air.\n\n";

$ledger = Ledger::empty()
    ->applyCoinbase(CoinbaseTx::create([
        Output::open(5_000_000_000, 'satoshi-reward-0'), // 50 BTC in satoshis
    ], 'block-0-coinbase'));

echo "  ⛏️  Satoshi mined block 0!\n";
echo "     Reward: 50 BTC (5.000.000.000 satoshis)\n\n";

printLedgerState($ledger);

// =============================================================================
// BLOCK 1: MORE MINING
// =============================================================================

printHeader('BLOCK 1: The Network Grows');

echo "  Satoshi mines another block. The network now has 100 BTC.\n\n";

$ledger = $ledger->applyCoinbase(CoinbaseTx::create([
    Output::open(5_000_000_000, 'satoshi-reward-1'),
], 'block-1-coinbase'));

echo "  ⛏️  Satoshi mined block 1!\n";
echo "     Reward: 50 BTC\n\n";

printLedgerState($ledger);

// =============================================================================
// BLOCK 2: FIRST TRANSACTION
// =============================================================================

printHeader('BLOCK 2: First Transaction Ever');

echo "  Satoshi wants to send 10 BTC to Hal Finney (the first Bitcoin\n";
echo "  transaction ever). But UTXOs work differently than bank accounts:\n\n";

echo "  → Satoshi has a 50 BTC UTXO (satoshi-reward-0)\n";
echo "  → To send 10 BTC, he must spend the ENTIRE 50 BTC UTXO\n";
echo "  → He creates two new outputs:\n";
echo "       • 10 BTC to Hal\n";
echo "       • 39.99 BTC back to himself (change)\n";
echo "       • 0.01 BTC goes to the miner as fee\n\n";

// Transaction: Satoshi -> Hal (10 BTC)
$ledger = $ledger->apply(Tx::create(
    spendIds: ['satoshi-reward-0'],
    outputs: [
        Output::open(1_000_000_000, 'hal-10btc'),      // 10 BTC to Hal
        Output::open(3_999_000_000, 'satoshi-change-1'), // 39.99 BTC change
    ],
    id: 'tx-satoshi-to-hal',
));

// Miner (Satoshi again) mines block 2, collecting the fee
$ledger = $ledger->applyCoinbase(CoinbaseTx::create([
    Output::open(5_000_000_000, 'satoshi-reward-2'),
], 'block-2-coinbase'));

echo "  ✅ Transaction confirmed in block 2!\n";
echo '     Fee paid: ' . btc($ledger->feeForTx(new TxId('tx-satoshi-to-hal')) ?? 0) . "\n\n";

printLedgerState($ledger);
printUTXOs($ledger);

// =============================================================================
// BLOCK 3: HAL MAKES A PURCHASE
// =============================================================================

printHeader('BLOCK 3: Hal Buys Pizza');

echo "  Hal wants to buy pizza from Laszlo for 5 BTC (a bargain!).\n";
echo "  He has exactly 10 BTC, so he'll pay and get change.\n\n";

$ledger = $ledger->apply(Tx::create(
    spendIds: ['hal-10btc'],
    outputs: [
        Output::open(500_000_000, 'laszlo-pizza-payment'), // 5 BTC
        Output::open(499_500_000, 'hal-change'),           // 4.995 BTC change
    ],
    id: 'tx-hal-pizza',
));

// Block 3 mined
$ledger = $ledger->applyCoinbase(CoinbaseTx::create([
    Output::open(5_000_000_000, 'miner-reward-3'),
], 'block-3-coinbase'));

echo "  🍕 Hal bought pizza for 5 BTC!\n";
echo "     Fee: 0.005 BTC\n\n";

printLedgerState($ledger);

// =============================================================================
// BLOCK 4: CONSOLIDATING UTXOS
// =============================================================================

printHeader('BLOCK 4: Satoshi Consolidates UTXOs');

echo "  Satoshi has multiple UTXOs scattered around. This is inefficient.\n";
echo "  He consolidates them into a single UTXO (common practice).\n\n";

echo "  Before consolidation, Satoshi has:\n";
echo "     • satoshi-reward-1:  50 BTC\n";
echo "     • satoshi-change-1:  39.99 BTC\n";
echo "     • satoshi-reward-2:  50 BTC\n";
echo "     Total: 139.99 BTC in 3 UTXOs\n\n";

$ledger = $ledger->apply(Tx::create(
    spendIds: ['satoshi-reward-1', 'satoshi-change-1', 'satoshi-reward-2'],
    outputs: [
        Output::open(13_998_000_000, 'satoshi-consolidated'), // ~139.98 BTC
    ],
    id: 'tx-satoshi-consolidate',
));

// Block 4 mined by a new miner
$ledger = $ledger->applyCoinbase(CoinbaseTx::create([
    Output::open(5_000_000_000, 'miner-reward-4'),
], 'block-4-coinbase'));

echo "  ✅ Consolidation complete!\n";
echo "     3 UTXOs → 1 UTXO\n";
echo "     Fee paid: 0.01 BTC\n\n";

echo "  After consolidation:\n";
echo "     • satoshi-consolidated: 139.98 BTC (single UTXO)\n\n";

printLedgerState($ledger);

// =============================================================================
// BLOCK 5: SPLITTING OUTPUTS
// =============================================================================

printHeader('BLOCK 5: Creating Multiple Outputs');

echo "  Laszlo wants to pay three people at once from his pizza money.\n";
echo "  Bitcoin allows multiple outputs in a single transaction!\n\n";

$ledger = $ledger->apply(Tx::create(
    spendIds: ['laszlo-pizza-payment'],
    outputs: [
        Output::open(150_000_000, 'alice-payment'),  // 1.5 BTC
        Output::open(150_000_000, 'bob-payment'),    // 1.5 BTC
        Output::open(150_000_000, 'charlie-payment'), // 1.5 BTC
        Output::open(49_000_000, 'laszlo-change'),   // 0.49 BTC change
    ],
    id: 'tx-laszlo-pays-three',
));

$ledger = $ledger->applyCoinbase(CoinbaseTx::create([
    Output::open(5_000_000_000, 'miner-reward-5'),
], 'block-5-coinbase'));

echo "  ✅ Laszlo paid 3 people in one transaction!\n";
echo "     Alice:   1.5 BTC\n";
echo "     Bob:     1.5 BTC\n";
echo "     Charlie: 1.5 BTC\n";
echo "     Fee:     0.01 BTC\n\n";

printLedgerState($ledger);

// =============================================================================
// FINAL STATE
// =============================================================================

printHeader('FINAL STATE: The UTXO Set');

echo "  After 5 blocks, here's who owns what:\n\n";

printUTXOs($ledger);

echo "  📈 Network Statistics:\n";
echo "     Blocks mined:     6 (0-5)\n";
echo '     Total minted:     ' . btc($ledger->totalMinted()) . "\n";
echo '     Total fees:       ' . btc($ledger->totalFeesCollected()) . "\n";
echo '     In circulation:   ' . btc($ledger->totalUnspentAmount()) . "\n";
echo "     Active UTXOs:     {$ledger->unspent()->count()}\n\n";

// =============================================================================
// KEY CONCEPTS SUMMARY
// =============================================================================

printHeader('KEY CONCEPTS DEMONSTRATED');

echo "  1. COINBASE TRANSACTIONS\n";
echo "     Miners create new coins with applyCoinbase(). No inputs needed.\n\n";

echo "  2. UTXO MODEL\n";
echo "     You don't have a 'balance' - you have unspent outputs.\n";
echo "     To spend, you consume entire UTXOs and create new ones.\n\n";

echo "  3. CHANGE\n";
echo "     If you spend a 50 BTC UTXO but only need 10 BTC,\n";
echo "     you create a change output back to yourself.\n\n";

echo "  4. FEES\n";
echo "     Fees = sum(inputs) - sum(outputs)\n";
echo "     Miners collect fees by NOT including them in outputs.\n\n";

echo "  5. MULTIPLE INPUTS/OUTPUTS\n";
echo "     Combine UTXOs (consolidation) or split to multiple recipients.\n\n";

echo "  6. IMMUTABILITY\n";
echo "     Once spent, a UTXO is gone forever. Can't double-spend.\n\n";

echo str_repeat('━', 60) . "\n";
echo "  Simulation complete!\n";
echo str_repeat('━', 60) . "\n\n";
