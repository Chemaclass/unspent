<?php

declare(strict_types=1);

/**
 * Internal Accounting - Department Budgets
 *
 * Shows audit-ready budget tracking with ownership and provenance.
 *
 * Run: php example/internal-accounting.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Chemaclass\Unspent\Exception\AuthorizationException;
use Chemaclass\Unspent\Exception\InsufficientSpendsException;
use Chemaclass\Unspent\InMemoryLedger;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\Tx;
use Chemaclass\Unspent\TxId;

echo "Internal Accounting Example\n";
echo "===========================\n\n";

// 1. Allocate FY budget
$company = InMemoryLedger::withGenesis(
    Output::ownedBy('engineering', 100_000, 'eng-budget'),
    Output::ownedBy('marketing', 50_000, 'mkt-budget'),
    Output::ownedBy('operations', 30_000, 'ops-budget'),
);
echo "FY Budget: Eng=\$100k, Mkt=\$50k, Ops=\$30k\n";
echo "Total: \${$company->totalUnspentAmount()}\n\n";

// 2. Department controls own budget
$company = $company->apply(Tx::create(
    spendIds: ['eng-budget'],
    outputs: [
        Output::ownedBy('engineering', 60_000, 'eng-projects'),
        Output::ownedBy('engineering', 40_000, 'eng-infra'),
    ],
    signedBy: 'engineering',
    id: 'eng-split',
));
echo "Engineering splits: projects=\$60k, infra=\$40k\n";

// 3. Unauthorized access blocked
echo "\nFinance tries to reallocate engineering funds... ";
try {
    $company->apply(Tx::create(
        spendIds: ['eng-projects'],
        outputs: [Output::ownedBy('marketing', 60_000)],
        signedBy: 'finance',
    ));
} catch (AuthorizationException) {
    echo "BLOCKED\n";
}

// 4. Inter-department transfer with admin fee
$company = $company->apply(Tx::create(
    spendIds: ['ops-budget'],
    outputs: [
        Output::ownedBy('marketing', 15_000, 'mkt-campaign'),
        Output::ownedBy('operations', 14_400, 'ops-remaining'),
    ], // 600 fee = 2% overhead
    signedBy: 'operations',
    id: 'ops-to-mkt',
));
echo "\nOps transfers \$15k to Marketing (2% admin fee)\n";
echo "Fee: \${$company->feeForTx(new TxId('ops-to-mkt'))}\n";

// 5. Overspend blocked
echo "\nMarketing tries to overspend... ";
try {
    $company->apply(Tx::create(
        spendIds: ['mkt-budget', 'mkt-campaign'],
        outputs: [Output::ownedBy('vendor', 100_000)],
        signedBy: 'marketing',
    ));
} catch (InsufficientSpendsException) {
    echo "BLOCKED\n";
}

// 6. Audit trail
echo "\nAudit trail:\n";
$id = new OutputId('mkt-campaign');
echo "  mkt-campaign: created by {$company->outputCreatedBy($id)}\n";

// 7. Reconciliation
echo "\nReconciliation:\n";
$initial = 180_000;
$fees = $company->totalFeesCollected();
$remaining = $company->totalUnspentAmount();
echo "  Initial: \${$initial}\n";
echo "  Fees: \${$fees}\n";
echo "  Remaining: \${$remaining}\n";
echo '  Check: ' . ($fees + $remaining === $initial ? 'BALANCED' : 'DISCREPANCY') . "\n";

// 8. Current state
echo "\nBudget by department:\n";
$budgets = [];
foreach ($company->unspent() as $output) {
    $dept = $output->lock->toArray()['name'] ?? 'unassigned';
    $budgets[$dept] = ($budgets[$dept] ?? 0) + $output->amount;
}
foreach ($budgets as $dept => $amount) {
    echo "  {$dept}: \$" . number_format($amount) . "\n";
}
