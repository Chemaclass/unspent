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
use Chemaclass\Unspent\Exception\AuthorizationException;
use Chemaclass\Unspent\Exception\DuplicateOutputIdException;
use Chemaclass\Unspent\Exception\DuplicateSpendException;
use Chemaclass\Unspent\Exception\GenesisNotAllowedException;
use Chemaclass\Unspent\Exception\OutputAlreadySpentException;
use Chemaclass\Unspent\Exception\InsufficientInputsException;
use Chemaclass\Unspent\Exception\UnspentException;
use Chemaclass\Unspent\Id;
use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Lock\NoLock;
use Chemaclass\Unspent\Lock\Owner;
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
    echo "  i {$message}\n";
}

function success(string $message): void
{
    echo "  v {$message}\n";
}

function error(string $message): void
{
    echo "  x {$message}\n";
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
    Output::open(1000, 'genesis-alice'),
    Output::open(500, 'genesis-bob'),
    Output::open(300, 'genesis-charlie'),
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
    inputIds: ['genesis-alice'],
    outputs: [
        Output::open(600, 'bob-from-alice'),
        Output::open(400, 'alice-change'),
    ],
    id: 'tx-001',
));
success("Transaction tx-001 applied. Total unspent: {$ledger->totalUnspentAmount()}");

info('Bob and Charlie combine their funds to create a shared account...');
$ledger = $ledger->apply(Spend::create(
    inputIds: ['genesis-bob', 'genesis-charlie'],
    outputs: [Output::open(800, 'bob-charlie-shared')],
    id: 'tx-002',
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
    Output::open(100, 'a'),
    Output::open(200, 'b'),
    Output::open(300, 'c'),
);
success("Created set with {$set->count()} outputs, total: {$set->totalAmount()}");

info('Adding outputs with addAll...');
$set = $set->addAll(
    Output::open(400, 'd'),
    Output::open(500, 'e'),
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
    $ledger->addGenesis(Output::open(100, 'invalid'));
    error('Should have thrown exception!');
} catch (GenesisNotAllowedException $e) {
    success("Caught GenesisNotAllowedException: {$e->getMessage()}");
}

// 6.2 Duplicate output IDs
info('Trying to create spend with duplicate output IDs...');
try {
    Spend::create(
        inputIds: ['alice-change'],
        outputs: [
            Output::open(200, 'same-id'),
            Output::open(200, 'same-id'),
        ],
        id: 'bad-tx',
    );
    error('Should have thrown exception!');
} catch (DuplicateOutputIdException $e) {
    success("Caught DuplicateOutputIdException: {$e->getMessage()}");
}

// 6.3 Spending non-existent output
info('Trying to spend a non-existent output...');
try {
    $ledger->apply(Spend::create(
        inputIds: ['nonexistent-output'],
        outputs: [Output::open(100, 'x')],
        id: 'bad-tx-2',
    ));
    error('Should have thrown exception!');
} catch (OutputAlreadySpentException $e) {
    success("Caught OutputAlreadySpentException: {$e->getMessage()}");
}

// 6.4 Insufficient inputs (outputs exceed inputs)
info('Trying to spend more than available (outputs > inputs)...');
try {
    $ledger->apply(Spend::create(
        inputIds: ['alice-change'], // 400 units
        outputs: [Output::open(500, 'y')], // 500 units - more than inputs!
        id: 'bad-tx-3',
    ));
    error('Should have thrown exception!');
} catch (InsufficientInputsException $e) {
    success("Caught InsufficientInputsException: {$e->getMessage()}");
}

// 6.5 Duplicate spend ID
info('Trying to apply the same spend twice...');
try {
    $ledger->apply(Spend::create(
        inputIds: ['alice-change'],
        outputs: [Output::open(400, 'z')],
        id: 'tx-001', // Already applied!
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
    fn() => $ledger->addGenesis(Output::open(1, 'x')),
    fn() => $ledger->apply(Spend::create(
        inputIds: ['a'],
        outputs: [Output::open(1, 'b')],
        id: 'tx-001',
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
    Output::open(1000, 'immutable-test'),
);
$originalTotal = $originalLedger->totalUnspentAmount();

$newLedger = $originalLedger->apply(Spend::create(
    inputIds: ['immutable-test'],
    outputs: [
        Output::open(600, 'new-output-1'),
        Output::open(400, 'new-output-2'),
    ],
    id: 'immutable-tx',
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
    ->addGenesis(Output::open(1000, 'fee-genesis'));

success("Genesis output: 1000 units, total fees: {$feeLedger->totalFeesCollected()}");

info('Applying spend with 10 unit fee (1000 -> 990)...');
$feeLedger = $feeLedger->apply(Spend::create(
    inputIds: ['fee-genesis'],
    outputs: [Output::open(990, 'fee-out-1')],
    id: 'fee-tx-1',
));
success("Fee for tx-1: {$feeLedger->feeForSpend(new SpendId('fee-tx-1'))} units");
success("Total fees collected: {$feeLedger->totalFeesCollected()} units");
success("Unspent amount: {$feeLedger->totalUnspentAmount()} units");

info('Applying spend with 5 unit fee (990 -> 985)...');
$feeLedger = $feeLedger->apply(Spend::create(
    inputIds: ['fee-out-1'],
    outputs: [Output::open(985, 'fee-out-2')],
    id: 'fee-tx-2',
));
success("Fee for tx-2: {$feeLedger->feeForSpend(new SpendId('fee-tx-2'))} units");
success("Total fees collected: {$feeLedger->totalFeesCollected()} units");

info('Zero-fee spend is still allowed...');
$feeLedger = $feeLedger->apply(Spend::create(
    inputIds: ['fee-out-2'],
    outputs: [Output::open(985, 'fee-out-3')],
    id: 'fee-tx-3',
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
    ->applyCoinbase(Coinbase::create([
        Output::open(50, 'miner-reward-1'),
    ], 'block-1'));

success("Block 1 mined! Total minted: {$mintLedger->totalMinted()} units");
success("Unspent: {$mintLedger->totalUnspentAmount()} units");

info('Mining another block...');
$mintLedger = $mintLedger->applyCoinbase(Coinbase::create([
    Output::open(50, 'miner-reward-2'),
    Output::open(5, 'tx-fees-2'),
], 'block-2'));

success("Block 2 mined! Total minted: {$mintLedger->totalMinted()} units");
success("Unspent: {$mintLedger->totalUnspentAmount()} units");

info('Spending minted coins...');
$mintLedger = $mintLedger->apply(Spend::create(
    inputIds: ['miner-reward-1'],
    outputs: [
        Output::open(40, 'alice'),
        Output::open(5, 'change'),
    ],
    id: 'tx-001',
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
    $largeSet = $largeSet->add(Output::open($i + 1, "perf-{$i}"));
}

$start = hrtime(true);
for ($i = 0; $i < 10000; $i++) {
    $_ = $largeSet->totalAmount();
}
$elapsed = (hrtime(true) - $start) / 1_000_000;
success(sprintf("10,000 calls to totalAmount() on 1,000 outputs: %.2fms", $elapsed));

info('Batch operations reduce object creation...');
$outputs = [];
for ($i = 0; $i < 100; $i++) {
    $outputs[] = Output::open(10, "batch-{$i}");
}

$start = hrtime(true);
$set = UnspentSet::fromOutputs(...$outputs);
$elapsed = (hrtime(true) - $start) / 1_000_000;
success(sprintf("Created set with 100 outputs using fromOutputs: %.2fms", $elapsed));

// ============================================================================
// 11. OWNERSHIP (LOCKS)
// ============================================================================

section('11. Ownership (Locks)');

info('Creating outputs with ownership locks...');
$ownerLedger = Ledger::empty()->addGenesis(
    Output::ownedBy('alice', 1000, 'alice-funds'),
    Output::ownedBy('bob', 500, 'bob-funds'),
    Output::open(300, 'open-funds'),
);
success("Created 3 outputs: alice (1000), bob (500), open (300)");

info('Alice spending her own output...');
$ownerLedger = $ownerLedger->apply(Spend::create(
    inputIds: ['alice-funds'],
    outputs: [
        Output::ownedBy('bob', 600, 'bob-payment'),
        Output::ownedBy('alice', 400, 'alice-change'),
    ],
    signedBy: 'alice',
    id: 'alice-tx',
));
success("Alice sent 600 to Bob, kept 400 as change");

info('Anyone can spend unlocked outputs...');
$ownerLedger = $ownerLedger->apply(Spend::create(
    inputIds: ['open-funds'],
    outputs: [Output::open(300, 'claimed')],
    id: 'open-tx',
));
success("Open funds claimed without authorization");

info('Attempting unauthorized spend (should fail)...');
try {
    $ownerLedger->apply(Spend::create(
        inputIds: ['bob-funds'],
        outputs: [Output::open(500, 'stolen')],
        signedBy: 'mallory',
        id: 'theft-attempt',
    ));
    error('Should have thrown exception!');
} catch (AuthorizationException $e) {
    success("Caught AuthorizationException: {$e->getMessage()}");
}

info('Bob spends his combined funds...');
$ownerLedger = $ownerLedger->apply(Spend::create(
    inputIds: ['bob-funds', 'bob-payment'],
    outputs: [Output::ownedBy('bob', 1100, 'bob-total')],
    signedBy: 'bob',
    id: 'bob-combine',
));
success("Bob combined his outputs: {$ownerLedger->totalUnspentAmount()} total unspent");

// ============================================================================
// 12. PERSISTENCE (SERIALIZATION)
// ============================================================================

section('12. Persistence (Serialization)');

info('Creating a ledger to persist...');
$persistLedger = Ledger::empty()
    ->addGenesis(Output::ownedBy('alice', 1000, 'persist-alice'))
    ->apply(Spend::create(
        inputIds: ['persist-alice'],
        outputs: [
            Output::ownedBy('bob', 600, 'persist-bob'),
            Output::ownedBy('alice', 390, 'persist-alice-change'),
        ],
        signedBy: 'alice',
        id: 'persist-tx',
    ));
success("Created ledger with 2 outputs, 10 units fee");

info('Converting to JSON...');
$json = $persistLedger->toJson(JSON_PRETTY_PRINT);
echo "  JSON preview:\n";
$preview = substr($json, 0, 200) . "...\n";
echo "    " . str_replace("\n", "\n    ", $preview);

info('Restoring from JSON...');
$restored = Ledger::fromJson($json);
success("Restored! Unspent: {$restored->totalUnspentAmount()}, Fees: {$restored->totalFeesCollected()}");

info('Verifying ownership survives serialization...');
try {
    $restored->apply(Spend::create(
        inputIds: ['persist-bob'],
        outputs: [Output::open(600, 'stolen')],
        signedBy: 'alice',
    ));
    error('Should have thrown exception!');
} catch (AuthorizationException $e) {
    success("Ownership preserved! Bob's output still protected");
}

info('Array serialization for database storage...');
$array = $persistLedger->toArray();
$fromArray = Ledger::fromArray($array);
success("Array round-trip: {$fromArray->totalUnspentAmount()} unspent");

// ============================================================================
// SUMMARY
// ============================================================================

section('Summary');

echo "  Final ledger state:\n";
echo "    - Total unspent amount: {$ledger->totalUnspentAmount()}\n";
echo "    - Number of unspent outputs: {$ledger->unspent()->count()}\n";
echo "    - Spends applied: tx-001, tx-002\n";

echo "\n  Library capabilities demonstrated:\n";
echo "    v Genesis output creation\n";
echo "    v Single and multi-input spends\n";
echo "    v Output querying and iteration\n";
echo "    v Spend tracking\n";
echo "    v Id interface for generic handling\n";
echo "    v UnspentSet batch operations\n";
echo "    v All invariant enforcement\n";
echo "    v Unified exception handling\n";
echo "    v Immutability guarantees\n";
echo "    v O(1) performance for totals\n";
echo "    v Bitcoin-style implicit fees\n";
echo "    v Coinbase transactions (minting)\n";
echo "    v Output ownership (locks)\n";
echo "    v JSON/Array persistence\n";

echo "\n" . str_repeat('=', 60) . "\n";
echo " Demo completed successfully!\n";
echo str_repeat('=', 60) . "\n\n";
