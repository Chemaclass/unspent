<?php

declare(strict_types=1);

/**
 * Event Sourcing Example - Domain Events with UTXO
 *
 * Demonstrates how UTXO naturally fits event-sourced domains where
 * state is reconstructed from immutable events.
 *
 * Run with: php example/event-sourcing.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\Spend;

echo "==========================================================\n";
echo " Event Sourcing Example - Order Lifecycle as UTXO\n";
echo "==========================================================\n\n";

// ============================================================================
// CONCEPT: Order states as outputs, transitions as spends
// ============================================================================
//
// Order lifecycle:
//   [placed] -> [paid] -> [shipped] -> [delivered]
//
// Each state transition consumes the old state and creates a new one.
// The full history is preserved and queryable.

// ============================================================================
// 1. ORDER PLACED - Initial Event
// ============================================================================

echo "1. ORDER PLACED - Customer submits order\n";
echo "-----------------------------------------\n";

$orderSystem = Ledger::empty()->addGenesis(
    Output::open(1, 'order-1001:placed'),
);

echo "Order #1001 created.\n";
echo "  State: placed\n";
echo "  Output ID: order-1001:placed\n\n";

// Store the version at this point
$v1_placed = $orderSystem;

// ============================================================================
// 2. PAYMENT RECEIVED - State Transition
// ============================================================================

echo "2. PAYMENT RECEIVED - Order paid\n";
echo "---------------------------------\n";

$orderSystem = $orderSystem->apply(Spend::create(
    inputIds: ['order-1001:placed'],
    outputs: [Output::open(1, 'order-1001:paid')],
    id: 'event:order-1001:payment-received',
));

echo "Payment received for Order #1001.\n";
echo "  Previous state: placed (consumed)\n";
echo "  New state: paid\n";
echo "  Event ID: event:order-1001:payment-received\n\n";

$v2_paid = $orderSystem;

// ============================================================================
// 3. ORDER SHIPPED - Another Transition
// ============================================================================

echo "3. ORDER SHIPPED - Fulfillment complete\n";
echo "----------------------------------------\n";

$orderSystem = $orderSystem->apply(Spend::create(
    inputIds: ['order-1001:paid'],
    outputs: [Output::open(1, 'order-1001:shipped')],
    id: 'event:order-1001:shipped',
));

echo "Order #1001 shipped.\n";
echo "  Previous state: paid (consumed)\n";
echo "  New state: shipped\n";
echo "  Event ID: event:order-1001:shipped\n\n";

$v3_shipped = $orderSystem;

// ============================================================================
// 4. ORDER DELIVERED - Final State
// ============================================================================

echo "4. ORDER DELIVERED - Complete\n";
echo "------------------------------\n";

$orderSystem = $orderSystem->apply(Spend::create(
    inputIds: ['order-1001:shipped'],
    outputs: [Output::open(1, 'order-1001:delivered')],
    id: 'event:order-1001:delivered',
));

echo "Order #1001 delivered.\n";
echo "  Previous state: shipped (consumed)\n";
echo "  New state: delivered (final)\n";
echo "  Event ID: event:order-1001:delivered\n\n";

$v4_delivered = $orderSystem;

// ============================================================================
// 5. IMMUTABILITY - Each Ledger Version is a Snapshot
// ============================================================================

echo "5. IMMUTABILITY - Point-in-Time Snapshots\n";
echo "------------------------------------------\n";

echo "Each ledger version represents state at a point in time:\n\n";

$versions = [
    'v1 (placed)' => $v1_placed,
    'v2 (paid)' => $v2_paid,
    'v3 (shipped)' => $v3_shipped,
    'v4 (delivered)' => $v4_delivered,
];

foreach ($versions as $name => $ledger) {
    $outputs = [];
    foreach ($ledger->unspent() as $id => $output) {
        $outputs[] = $id;
    }
    echo "  {$name}:\n";
    echo '    Active outputs: ' . implode(', ', $outputs) . "\n";
}
echo "\n";

// ============================================================================
// 6. EVENT REPLAY - Reconstruct State from History
// ============================================================================

echo "6. EVENT REPLAY - Query Full Event Chain\n";
echo "-----------------------------------------\n";

echo "Reconstructing order #1001 history from delivered state:\n\n";

// Start from current state and trace back
$states = ['order-1001:delivered', 'order-1001:shipped', 'order-1001:paid', 'order-1001:placed'];

foreach ($states as $stateId) {
    $id = new OutputId($stateId);
    $createdBy = $v4_delivered->outputCreatedBy($id);
    $spentBy = $v4_delivered->outputSpentBy($id);

    $status = $spentBy !== null ? "-> {$spentBy}" : '(current)';
    $source = $createdBy === 'genesis' ? 'order created' : $createdBy;

    echo "  {$stateId}\n";
    echo "    Created by: {$source}\n";
    echo "    Transition: {$status}\n";
}
echo "\n";

// ============================================================================
// 7. VERSION HISTORY - Compare Different Points in Time
// ============================================================================

echo "7. VERSION HISTORY - Time Travel Queries\n";
echo "-----------------------------------------\n";

echo "What was order #1001's state at each version?\n\n";

$stateChecks = [
    'order-1001:placed' => ['Check' => 'Was placed?'],
    'order-1001:paid' => ['Check' => 'Was paid?'],
    'order-1001:shipped' => ['Check' => 'Was shipped?'],
    'order-1001:delivered' => ['Check' => 'Was delivered?'],
];

echo "State existence at each version:\n";
echo str_repeat(' ', 20) . "v1    v2    v3    v4\n";

foreach ($stateChecks as $stateId => $info) {
    $label = str_pad(substr($stateId, 11), 12); // Extract state name
    $v1Has = $v1_placed->unspent()->contains(new OutputId($stateId)) ? 'YES' : 'no';
    $v2Has = $v2_paid->unspent()->contains(new OutputId($stateId)) ? 'YES' : 'no';
    $v3Has = $v3_shipped->unspent()->contains(new OutputId($stateId)) ? 'YES' : 'no';
    $v4Has = $v4_delivered->unspent()->contains(new OutputId($stateId)) ? 'YES' : 'no';

    echo "  {$label}      {$v1Has}   {$v2Has}   {$v3Has}   {$v4Has}\n";
}
echo "\n";

// ============================================================================
// 8. MULTIPLE ORDERS - Parallel Workflows
// ============================================================================

echo "8. MULTIPLE ORDERS - Parallel Workflows\n";
echo "----------------------------------------\n";

// Add more orders at different states
$multiOrder = Ledger::empty()->addGenesis(
    Output::open(1, 'order-2001:placed'),
    Output::open(1, 'order-2002:placed'),
    Output::open(1, 'order-2003:placed'),
);

// Progress each order differently
$multiOrder = $multiOrder->apply(Spend::create(
    inputIds: ['order-2001:placed'],
    outputs: [Output::open(1, 'order-2001:paid')],
    id: 'event:order-2001:payment',
));

$multiOrder = $multiOrder->apply(Spend::create(
    inputIds: ['order-2002:placed'],
    outputs: [Output::open(1, 'order-2002:paid')],
    id: 'event:order-2002:payment',
));

$multiOrder = $multiOrder->apply(Spend::create(
    inputIds: ['order-2002:paid'],
    outputs: [Output::open(1, 'order-2002:shipped')],
    id: 'event:order-2002:shipped',
));

echo "Current order states:\n";
foreach ($multiOrder->unspent() as $id => $output) {
    [$orderId, $state] = explode(':', $id);
    echo "  {$orderId}: {$state}\n";
}
echo "\n";

// ============================================================================
// 9. SERIALIZATION - Event Store Persistence
// ============================================================================

echo "9. SERIALIZATION - Event Store Persistence\n";
echo "-------------------------------------------\n";

// Save to "event store"
$eventStore = $v4_delivered->toJson();
echo 'Ledger serialized to event store (' . \strlen($eventStore) . " bytes)\n";

// Restore from event store
$restored = Ledger::fromJson($eventStore);
echo "Ledger restored from event store.\n";

// Verify history is intact
$restoredHistory = $restored->outputCreatedBy(new OutputId('order-1001:shipped'));
echo "  History preserved: order-1001:shipped created by {$restoredHistory}\n\n";

// ============================================================================
// 10. PROVING STATE TRANSITIONS
// ============================================================================

echo "10. PROVING STATE TRANSITIONS\n";
echo "------------------------------\n";

echo "Proving order #1001 went through all required stages:\n\n";

$requiredTransitions = [
    'placed -> paid' => ['from' => 'order-1001:placed', 'event' => 'event:order-1001:payment-received'],
    'paid -> shipped' => ['from' => 'order-1001:paid', 'event' => 'event:order-1001:shipped'],
    'shipped -> delivered' => ['from' => 'order-1001:shipped', 'event' => 'event:order-1001:delivered'],
];

$allValid = true;
foreach ($requiredTransitions as $transition => $check) {
    $actualEvent = $restored->outputSpentBy(new OutputId($check['from']));
    $valid = $actualEvent === $check['event'];
    $status = $valid ? 'VERIFIED' : 'FAILED';
    $allValid = $allValid && $valid;

    echo "  {$transition}: {$status}\n";
    echo "    Expected: {$check['event']}\n";
    echo "    Actual:   {$actualEvent}\n";
}

echo "\n  Order lifecycle: " . ($allValid ? 'FULLY VERIFIED' : 'VERIFICATION FAILED') . "\n";

// ============================================================================
// SUMMARY
// ============================================================================

echo "\n";
echo "==========================================================\n";
echo " Summary\n";
echo "==========================================================\n\n";

echo "Order #1001 event chain:\n";
echo "  1. genesis           -> order-1001:placed\n";
echo "  2. payment-received  -> order-1001:paid\n";
echo "  3. shipped           -> order-1001:shipped\n";
echo "  4. delivered         -> order-1001:delivered\n";

echo "\nFeatures demonstrated:\n";
echo "  - State as outputs, transitions as spends\n";
echo "  - Immutable ledger versions (snapshots)\n";
echo "  - Event replay from history\n";
echo "  - Point-in-time queries\n";
echo "  - Parallel workflows (multiple aggregates)\n";
echo "  - Event store serialization\n";
echo "  - Transition verification/proofs\n";

echo "\n";
