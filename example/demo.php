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

use Chemaclass\Unspent\Exception\DuplicateOutputIdException;
use Chemaclass\Unspent\Exception\DuplicateSpendException;
use Chemaclass\Unspent\Exception\GenesisNotAllowedException;
use Chemaclass\Unspent\Exception\OutputAlreadySpentException;
use Chemaclass\Unspent\Exception\UnbalancedSpendException;
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
    new Output(new OutputId('genesis-alice'), 1000),
    new Output(new OutputId('genesis-bob'), 500),
    new Output(new OutputId('genesis-charlie'), 300),
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
$ledger = $ledger->apply(new Spend(
    id: new SpendId('tx-001'),
    inputs: [new OutputId('genesis-alice')],
    outputs: [
        new Output(new OutputId('bob-from-alice'), 600),
        new Output(new OutputId('alice-change'), 400),
    ],
));
success("Transaction tx-001 applied. Total unspent: {$ledger->totalUnspentAmount()}");

info('Bob and Charlie combine their funds to create a shared account...');
$ledger = $ledger->apply(new Spend(
    id: new SpendId('tx-002'),
    inputs: [
        new OutputId('genesis-bob'),
        new OutputId('genesis-charlie'),
    ],
    outputs: [
        new Output(new OutputId('bob-charlie-shared'), 800),
    ],
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
    new Output(new OutputId('a'), 100),
    new Output(new OutputId('b'), 200),
    new Output(new OutputId('c'), 300),
);
success("Created set with {$set->count()} outputs, total: {$set->totalAmount()}");

info('Adding outputs with addAll...');
$set = $set->addAll(
    new Output(new OutputId('d'), 400),
    new Output(new OutputId('e'), 500),
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
    $ledger->addGenesis(new Output(new OutputId('invalid'), 100));
    error('Should have thrown exception!');
} catch (GenesisNotAllowedException $e) {
    success("Caught GenesisNotAllowedException: {$e->getMessage()}");
}

// 6.2 Duplicate output IDs
info('Trying to create spend with duplicate output IDs...');
try {
    new Spend(
        id: new SpendId('bad-tx'),
        inputs: [new OutputId('alice-change')],
        outputs: [
            new Output(new OutputId('same-id'), 200),
            new Output(new OutputId('same-id'), 200),
        ],
    );
    error('Should have thrown exception!');
} catch (DuplicateOutputIdException $e) {
    success("Caught DuplicateOutputIdException: {$e->getMessage()}");
}

// 6.3 Spending non-existent output
info('Trying to spend a non-existent output...');
try {
    $ledger->apply(new Spend(
        id: new SpendId('bad-tx-2'),
        inputs: [new OutputId('nonexistent-output')],
        outputs: [new Output(new OutputId('x'), 100)],
    ));
    error('Should have thrown exception!');
} catch (OutputAlreadySpentException $e) {
    success("Caught OutputAlreadySpentException: {$e->getMessage()}");
}

// 6.4 Unbalanced spend
info('Trying to create unbalanced spend (input != output)...');
try {
    $ledger->apply(new Spend(
        id: new SpendId('bad-tx-3'),
        inputs: [new OutputId('alice-change')], // 400 units
        outputs: [new Output(new OutputId('y'), 100)], // Only 100 units!
    ));
    error('Should have thrown exception!');
} catch (UnbalancedSpendException $e) {
    success("Caught UnbalancedSpendException: {$e->getMessage()}");
}

// 6.5 Duplicate spend ID
info('Trying to apply the same spend twice...');
try {
    $ledger->apply(new Spend(
        id: new SpendId('tx-001'), // Already applied!
        inputs: [new OutputId('alice-change')],
        outputs: [new Output(new OutputId('z'), 400)],
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
    fn() => $ledger->addGenesis(new Output(new OutputId('x'), 1)),
    fn() => $ledger->apply(new Spend(
        id: new SpendId('tx-001'),
        inputs: [new OutputId('a')],
        outputs: [new Output(new OutputId('b'), 1)],
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
    new Output(new OutputId('immutable-test'), 1000),
);
$originalTotal = $originalLedger->totalUnspentAmount();

$newLedger = $originalLedger->apply(new Spend(
    id: new SpendId('immutable-tx'),
    inputs: [new OutputId('immutable-test')],
    outputs: [
        new Output(new OutputId('new-output-1'), 600),
        new Output(new OutputId('new-output-2'), 400),
    ],
));

echo "  Original ledger total: {$originalLedger->totalUnspentAmount()}\n";
echo "  Original ledger outputs: {$originalLedger->unspent()->count()}\n";
echo "  New ledger total: {$newLedger->totalUnspentAmount()}\n";
echo "  New ledger outputs: {$newLedger->unspent()->count()}\n";
success('Original ledger unchanged after apply()');

// ============================================================================
// 8. PERFORMANCE CHARACTERISTICS
// ============================================================================

section('8. Performance Characteristics');

info('O(1) total amount (cached)...');
$largeSet = UnspentSet::empty();
for ($i = 0; $i < 1000; $i++) {
    $largeSet = $largeSet->add(new Output(new OutputId("perf-{$i}"), $i + 1));
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
    $outputs[] = new Output(new OutputId("batch-{$i}"), 10);
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

echo "\n" . str_repeat('=', 60) . "\n";
echo " Demo completed successfully!\n";
echo str_repeat('=', 60) . "\n\n";
