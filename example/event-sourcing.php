<?php

declare(strict_types=1);

/**
 * Event Sourcing - Order Lifecycle
 *
 * Shows how state transitions work as spend/create operations.
 *
 * Run: php example/event-sourcing.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\Tx;

echo "Event Sourcing Example\n";
echo "======================\n\n";

// Order lifecycle: placed -> paid -> shipped -> delivered
// Each transition spends old state, creates new state

// 1. Order placed (genesis event)
$orders = Ledger::withGenesis(
    Output::open(1, 'order-1001_placed'),
);
echo "Order #1001: placed\n";

// 2. Payment received
$orders = $orders->apply(Tx::create(
    spendIds: ['order-1001_placed'],
    outputs: [Output::open(1, 'order-1001_paid')],
    id: 'evt_payment',
));
echo "Order #1001: paid\n";

// 3. Shipped
$orders = $orders->apply(Tx::create(
    spendIds: ['order-1001_paid'],
    outputs: [Output::open(1, 'order-1001_shipped')],
    id: 'evt_shipped',
));
echo "Order #1001: shipped\n";

// 4. Delivered
$orders = $orders->apply(Tx::create(
    spendIds: ['order-1001_shipped'],
    outputs: [Output::open(1, 'order-1001_delivered')],
    id: 'evt_delivered',
));
echo "Order #1001: delivered\n\n";

// 5. Reconstruct history
echo "Event chain:\n";
$states = ['order-1001_placed', 'order-1001_paid', 'order-1001_shipped', 'order-1001_delivered'];
foreach ($states as $state) {
    $id = new OutputId($state);
    $created = $orders->outputCreatedBy($id);
    $spent = $orders->outputSpentBy($id);
    $status = $spent ? "-> {$spent}" : '(current)';
    echo "  {$state}: {$created} {$status}\n";
}

// 6. Multiple orders at different stages
echo "\nMultiple orders:\n";
$multi = Ledger::withGenesis(
    Output::open(1, 'order-2001_placed'),
    Output::open(1, 'order-2002_placed'),
);

$multi = $multi->apply(Tx::create(
    spendIds: ['order-2001_placed'],
    outputs: [Output::open(1, 'order-2001_paid')],
    id: 'evt_2001_pay',
));

foreach ($multi->unspent() as $id => $output) {
    [$orderId, $state] = explode('_', $id);
    echo "  {$orderId}: {$state}\n";
}
