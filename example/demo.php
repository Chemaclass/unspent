<?php

declare(strict_types=1);

/**
 * Unspent Library Demo
 *
 * This example demonstrates all capabilities of the unspent library,
 * a UTXO-like bookkeeping system for PHP.
 *
 * Run with: php example/demo.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Chemaclass\Unspent\Coinbase;
use Chemaclass\Unspent\Exception\DuplicateOutputIdException;
use Chemaclass\Unspent\Exception\DuplicateSpendException;
use Chemaclass\Unspent\Exception\GenesisNotAllowedException;
use Chemaclass\Unspent\Exception\OutputAlreadySpentException;
use Chemaclass\Unspent\Exception\InsufficientInputsException;
use Chemaclass\Unspent\Exception\UnspentException;
use Chemaclass\Unspent\Id;
use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\Spend;
use Chemaclass\Unspent\SpendId;
use Chemaclass\Unspent\UnspentSet;

// Helper function for formatted output
function section(string $title): void
{
    echo "\n" . str_repeat('=', 60) . "\n";
    echo " {$title}\n";
    echo str_repeat('=', 60) . "\n\n";
}

function info(string $message): void
{
    echo "  ℹ {$message}\n";
}

function success(string $message): void
{
    echo "  ✓ {$message}\n";
}

function error(string $message): void
{
    echo "  ✗ {$message}\n";
}

// ============================================================================
// 1. CREATING A LEDGER WITH GENESIS OUTPUTS
// ============================================================================

section('1. Creating a Ledger with Genesis Outputs');

info('Creating an empty ledger...');
$ledger = Ledger::empty();
success("Empty ledger created. Total unspent: {$ledger->totalUnspentAmount()}");

info('Adding genesis outputs (initial coin distribution)...');
$ledger = $ledger->addGenesis(
    Output::create('genesis-alice', 1000),
    Output::create('genesis-bob', 500),
    Output::create('genesis-charlie', 300),
);
success("Genesis outputs added. Total unspent: {$ledger->totalUnspentAmount()}");

echo "\n  Current unspent outputs:\n";
foreach ($ledger->unspent() as $id => $output) {
    echo "    - {$id}: {$output->amount} units\n";
}

// ============================================================================
// 2. APPLYING SPENDS (TRANSACTIONS)
// ============================================================================

section('2. Applying Spends (Transactions)');

info('Alice sends 600 to Bob and keeps 400 as change...');
$ledger = $ledger->apply(Spend::create(
    id: 'tx-001',
    inputIds: ['genesis-alice'],
    outputs: [
        Output::create('bob-from-alice', 600),
        Output::create('alice-change', 400),
    ],
));
success("Transaction tx-001 applied. Total unspent: {$ledger->totalUnspentAmount()}");

info('Bob and Charlie combine their funds to create a shared account...');
$ledger = $ledger->apply(Spend::create(
    id: 'tx-002',
    inputIds: ['genesis-bob', 'genesis-charlie'],
    outputs: [Output::create('bob-charlie-shared', 800)],
));
success("Transaction tx-002 applied. Total unspent: {$ledger->totalUnspentAmount()}");

echo "\n  Current unspent outputs:\n";
foreach ($ledger->unspent() as $id => $output) {
    echo "    - {$id}: {$output->amount} units\n";
}

// ============================================================================
// 3. QUERYING THE LEDGER
// ============================================================================

section('3. Querying the Ledger');

info('Checking specific outputs...');
$unspent = $ledger->unspent();

$checkIds = ['genesis-alice', 'alice-change', 'bob-from-alice', 'nonexistent'];
foreach ($checkIds as $idStr) {
    $id = new OutputId($idStr);
    $contains = $unspent->contains($id) ? 'YES' : 'NO';
    echo "    Contains '{$idStr}': {$contains}\n";
}

info('Getting output details...');
$bobOutput = $unspent->get(new OutputId('bob-from-alice'));
if ($bobOutput !== null) {
    success("Bob's output: ID={$bobOutput->id->value}, Amount={$bobOutput->amount}");
}

info('Checking if spends have been applied...');
$spendChecks = ['tx-001', 'tx-002', 'tx-999'];
foreach ($spendChecks as $spendIdStr) {
    $applied = $ledger->hasSpendBeenApplied(new SpendId($spendIdStr)) ? 'YES' : 'NO';
    echo "    Spend '{$spendIdStr}' applied: {$applied}\n";
}

// ============================================================================
// 4. USING THE ID INTERFACE
// ============================================================================

section('4. Using the Id Interface');

info('Both OutputId and SpendId implement the Id interface...');

/** @var list<Id> $allIds */
$allIds = [
    new OutputId('output-1'),
    new SpendId('spend-1'),
    new OutputId('output-2'),
    new SpendId('spend-2'),
];

echo "  Processing IDs generically:\n";
foreach ($allIds as $id) {
    $type = $id instanceof OutputId ? 'OutputId' : 'SpendId';
    echo "    - {$type}: value='{$id->value}', string='{$id}'\n";
}

// ============================================================================
// 5. UNSPENT SET OPERATIONS
// ============================================================================

section('5. UnspentSet Operations');

info('Creating UnspentSet from outputs...');
$set = UnspentSet::fromOutputs(
    Output::create('a', 100),
    Output::create('b', 200),
    Output::create('c', 300),
);
success("Created set with {$set->count()} outputs, total: {$set->totalAmount()}");

info('Adding outputs with addAll...');
$set = $set->addAll(
    Output::create('d', 400),
    Output::create('e', 500),
);
success("After addAll: {$set->count()} outputs, total: {$set->totalAmount()}");

info('Removing outputs with removeAll...');
$set = $set->removeAll(
    new OutputId('a'),
    new OutputId('c'),
);
success("After removeAll: {$set->count()} outputs, total: {$set->totalAmount()}");

info('Getting all output IDs...');
$ids = $set->outputIds();
echo "  Remaining IDs: " . implode(', ', array_map(fn($id) => $id->value, $ids)) . "\n";

// ============================================================================
// 6. INVARIANT ENFORCEMENT (ERROR HANDLING)
// ============================================================================

section('6. Invariant Enforcement (Error Handling)');

// 6.1 Genesis only on empty ledger
info('Trying to add genesis to non-empty ledger...');
try {
    $ledger->addGenesis(Output::create('invalid', 100));
    error('Should have thrown exception!');
} catch (GenesisNotAllowedException $e) {
    success("Caught GenesisNotAllowedException: {$e->getMessage()}");
}

// 6.2 Duplicate output IDs
info('Trying to create spend with duplicate output IDs...');
try {
    Spend::create(
        id: 'bad-tx',
        inputIds: ['alice-change'],
        outputs: [
            Output::create('same-id', 200),
            Output::create('same-id', 200),
        ],
    );
    error('Should have thrown exception!');
} catch (DuplicateOutputIdException $e) {
    success("Caught DuplicateOutputIdException: {$e->getMessage()}");
}

// 6.3 Spending non-existent output
info('Trying to spend a non-existent output...');
try {
    $ledger->apply(Spend::create(
        id: 'bad-tx-2',
        inputIds: ['nonexistent-output'],
        outputs: [Output::create('x', 100)],
    ));
    error('Should have thrown exception!');
} catch (OutputAlreadySpentException $e) {
    success("Caught OutputAlreadySpentException: {$e->getMessage()}");
}

// 6.4 Insufficient inputs (outputs exceed inputs)
info('Trying to spend more than available (outputs > inputs)...');
try {
    $ledger->apply(Spend::create(
        id: 'bad-tx-3',
        inputIds: ['alice-change'], // 400 units
        outputs: [Output::create('y', 500)], // 500 units - more than inputs!
    ));
    error('Should have thrown exception!');
} catch (InsufficientInputsException $e) {
    success("Caught InsufficientInputsException: {$e->getMessage()}");
}

// 6.5 Duplicate spend ID
info('Trying to apply the same spend twice...');
try {
    $ledger->apply(Spend::create(
        id: 'tx-001', // Already applied!
        inputIds: ['alice-change'],
        outputs: [Output::create('z', 400)],
    ));
    error('Should have thrown exception!');
} catch (DuplicateSpendException $e) {
    success("Caught DuplicateSpendException: {$e->getMessage()}");
}

// 6.6 Empty ID validation
info('Trying to create an empty OutputId...');
try {
    new OutputId('');
    error('Should have thrown exception!');
} catch (InvalidArgumentException $e) {
    success("Caught InvalidArgumentException: {$e->getMessage()}");
}

// 6.7 Catching all domain exceptions with UnspentException
info('Using UnspentException to catch all domain errors...');
$errorCount = 0;
$badOperations = [
    fn() => $ledger->addGenesis(Output::create('x', 1)),
    fn() => $ledger->apply(Spend::create(
        id: 'tx-001',
        inputIds: ['a'],
        outputs: [Output::create('b', 1)],
    )),
];
foreach ($badOperations as $operation) {
    try {
        $operation();
    } catch (UnspentException) {
        $errorCount++;
    }
}
success("Caught {$errorCount} errors using base UnspentException type");

// ============================================================================
// 7. IMMUTABILITY DEMONSTRATION
// ============================================================================

section('7. Immutability Demonstration');

info('All operations return new instances, original is unchanged...');

$originalLedger = Ledger::empty()->addGenesis(
    Output::create('immutable-test', 1000),
);
$originalTotal = $originalLedger->totalUnspentAmount();

$newLedger = $originalLedger->apply(Spend::create(
    id: 'immutable-tx',
    inputIds: ['immutable-test'],
    outputs: [
        Output::create('new-output-1', 600),
        Output::create('new-output-2', 400),
    ],
));

echo "  Original ledger total: {$originalLedger->totalUnspentAmount()}\n";
echo "  Original ledger outputs: {$originalLedger->unspent()->count()}\n";
echo "  New ledger total: {$newLedger->totalUnspentAmount()}\n";
echo "  New ledger outputs: {$newLedger->unspent()->count()}\n";
success('Original ledger unchanged after apply()');

// ============================================================================
// 8. IMPLICIT FEES (BITCOIN-STYLE)
// ============================================================================

section('8. Implicit Fees (Bitcoin-Style)');

info('Creating a ledger with fee-bearing spends...');
$feeLedger = Ledger::empty()
    ->addGenesis(Output::create('fee-genesis', 1000));

success("Genesis output: 1000 units, total fees: {$feeLedger->totalFeesCollected()}");

info('Applying spend with 10 unit fee (1000 -> 990)...');
$feeLedger = $feeLedger->apply(Spend::create(
    id: 'fee-tx-1',
    inputIds: ['fee-genesis'],
    outputs: [Output::create('fee-out-1', 990)],
));
success("Fee for tx-1: {$feeLedger->feeForSpend(new SpendId('fee-tx-1'))} units");
success("Total fees collected: {$feeLedger->totalFeesCollected()} units");
success("Unspent amount: {$feeLedger->totalUnspentAmount()} units");

info('Applying spend with 5 unit fee (990 -> 985)...');
$feeLedger = $feeLedger->apply(Spend::create(
    id: 'fee-tx-2',
    inputIds: ['fee-out-1'],
    outputs: [Output::create('fee-out-2', 985)],
));
success("Fee for tx-2: {$feeLedger->feeForSpend(new SpendId('fee-tx-2'))} units");
success("Total fees collected: {$feeLedger->totalFeesCollected()} units");

info('Zero-fee spend is still allowed (backward compatible)...');
$feeLedger = $feeLedger->apply(Spend::create(
    id: 'fee-tx-3',
    inputIds: ['fee-out-2'],
    outputs: [Output::create('fee-out-3', 985)],
));
success("Fee for tx-3: {$feeLedger->feeForSpend(new SpendId('fee-tx-3'))} units (zero fee)");
success("Total fees collected: {$feeLedger->totalFeesCollected()} units");

info('Querying all spend fees...');
$allFees = $feeLedger->allSpendFees();
echo "  All spend fees:\n";
foreach ($allFees as $spendId => $fee) {
    echo "    - {$spendId}: {$fee} units\n";
}

info('Unknown spend returns null...');
$unknownFee = $feeLedger->feeForSpend(new SpendId('nonexistent'));
success('Fee for unknown spend: ' . ($unknownFee === null ? 'null' : $unknownFee));

// ============================================================================
// 9. COINBASE TRANSACTIONS (MINTING)
// ============================================================================

section('9. Coinbase Transactions (Minting)');

info('Creating new value with coinbase transactions (like miner rewards)...');
$mintLedger = Ledger::empty()
    ->applyCoinbase(Coinbase::create('block-1', [
        Output::create('miner-reward-1', 50),
    ]));

success("Block 1 mined! Total minted: {$mintLedger->totalMinted()} units");
success("Unspent: {$mintLedger->totalUnspentAmount()} units");

info('Mining another block...');
$mintLedger = $mintLedger->applyCoinbase(Coinbase::create('block-2', [
    Output::create('miner-reward-2', 50),
    Output::create('tx-fees-2', 5),
]));

success("Block 2 mined! Total minted: {$mintLedger->totalMinted()} units");
success("Unspent: {$mintLedger->totalUnspentAmount()} units");

info('Spending minted coins...');
$mintLedger = $mintLedger->apply(Spend::create(
    id: 'tx-001',
    inputIds: ['miner-reward-1'],
    outputs: [
        Output::create('alice', 40),
        Output::create('change', 5),
    ],
));

success("Spent miner reward. Fee collected: {$mintLedger->feeForSpend(new SpendId('tx-001'))} units");
success("Total fees: {$mintLedger->totalFeesCollected()} units");
success("Unspent: {$mintLedger->totalUnspentAmount()} units");

info('Querying coinbase transactions...');
$isCoinbase1 = $mintLedger->isCoinbase(new SpendId('block-1')) ? 'YES' : 'NO';
$isCoinbase2 = $mintLedger->isCoinbase(new SpendId('tx-001')) ? 'YES' : 'NO';
echo "  Is 'block-1' a coinbase: {$isCoinbase1}\n";
echo "  Is 'tx-001' a coinbase: {$isCoinbase2}\n";

$coinbaseAmount = $mintLedger->coinbaseAmount(new SpendId('block-2'));
success("Block-2 minted: {$coinbaseAmount} units");

// ============================================================================
// 10. PERFORMANCE CHARACTERISTICS
// ============================================================================

section('10. Performance Characteristics');

info('O(1) total amount (cached)...');
$largeSet = UnspentSet::empty();
for ($i = 0; $i < 1000; $i++) {
    $largeSet = $largeSet->add(Output::create("perf-{$i}", $i + 1));
}

$start = hrtime(true);
for ($i = 0; $i < 10000; $i++) {
    $largeSet->totalAmount();
}
$elapsed = (hrtime(true) - $start) / 1_000_000;
success(sprintf("10,000 calls to totalAmount() on 1,000 outputs: %.2fms", $elapsed));

info('Batch operations reduce object creation...');
$outputs = [];
for ($i = 0; $i < 100; $i++) {
    $outputs[] = Output::create("batch-{$i}", 10);
}

$start = hrtime(true);
$set = UnspentSet::fromOutputs(...$outputs);
$elapsed = (hrtime(true) - $start) / 1_000_000;
success(sprintf("Created set with 100 outputs using fromOutputs: %.2fms", $elapsed));

// ============================================================================
// SUMMARY
// ============================================================================

section('Summary');

echo "  Final ledger state:\n";
echo "    - Total unspent amount: {$ledger->totalUnspentAmount()}\n";
echo "    - Number of unspent outputs: {$ledger->unspent()->count()}\n";
echo "    - Spends applied: tx-001, tx-002\n";

echo "\n  Library capabilities demonstrated:\n";
echo "    ✓ Genesis output creation\n";
echo "    ✓ Single and multi-input spends\n";
echo "    ✓ Output querying and iteration\n";
echo "    ✓ Spend tracking\n";
echo "    ✓ Id interface for generic handling\n";
echo "    ✓ UnspentSet batch operations\n";
echo "    ✓ All invariant enforcement\n";
echo "    ✓ Unified exception handling\n";
echo "    ✓ Immutability guarantees\n";
echo "    ✓ O(1) performance for totals\n";
echo "    ✓ Bitcoin-style implicit fees\n";
echo "    ✓ Coinbase transactions (minting)\n";

echo "\n" . str_repeat('=', 60) . "\n";
echo " Demo completed successfully!\n";
echo str_repeat('=', 60) . "\n\n";
