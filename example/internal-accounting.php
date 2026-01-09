<?php

declare(strict_types=1);

/**
 * Internal Accounting Example - Department Budgets
 *
 * Demonstrates UTXO-based tracking for internal accounting where
 * every dollar is audit-ready with no hidden mutations.
 *
 * Run with: php example/internal-accounting.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Chemaclass\Unspent\Exception\AuthorizationException;
use Chemaclass\Unspent\Exception\InsufficientInputsException;
use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\Tx;
use Chemaclass\Unspent\TxId;

echo "==========================================================\n";
echo " Internal Accounting Example - Department Budgets\n";
echo "==========================================================\n\n";

// ============================================================================
// 1. GENESIS - FY2024 Budget Allocation
// ============================================================================

echo "1. GENESIS - FY2024 Budget Allocation\n";
echo "--------------------------------------\n";

$company = Ledger::empty()->addGenesis(
    Output::ownedBy('engineering', 100_000, 'eng-fy24-budget'),
    Output::ownedBy('marketing', 50_000, 'mkt-fy24-budget'),
    Output::ownedBy('operations', 30_000, 'ops-fy24-budget'),
    Output::ownedBy('hr', 20_000, 'hr-fy24-budget'),
);

echo "FY2024 Budget allocated:\n";
echo "  Engineering: \$100,000\n";
echo "  Marketing:   \$50,000\n";
echo "  Operations:  \$30,000\n";
echo "  HR:          \$20,000\n";
echo "  Total:       \${$company->totalUnspentAmount()}\n\n";

// ============================================================================
// 2. OWNERSHIP - Departments Control Their Budgets
// ============================================================================

echo "2. OWNERSHIP - Departments Control Their Budgets\n";
echo "-------------------------------------------------\n";

// Engineering allocates to sub-projects
$company = $company->apply(Tx::create(
    inputIds: ['eng-fy24-budget'],
    outputs: [
        Output::ownedBy('engineering', 40_000, 'eng-backend-q1'),
        Output::ownedBy('engineering', 35_000, 'eng-frontend-q1'),
        Output::ownedBy('engineering', 25_000, 'eng-devops-q1'),
    ],
    signedBy: 'engineering',
    id: 'eng-q1-allocation',
));

echo "Engineering splits budget into projects:\n";
echo "  Backend:  \$40,000\n";
echo "  Frontend: \$35,000\n";
echo "  DevOps:   \$25,000\n";

// Finance (unauthorized) tries to reallocate engineering funds
echo "\nFinance tries to reallocate engineering funds...\n";
try {
    $company->apply(Tx::create(
        inputIds: ['eng-backend-q1'],
        outputs: [Output::ownedBy('marketing', 40_000, 'unauthorized')],
        signedBy: 'finance',
        id: 'unauthorized-transfer',
    ));
} catch (AuthorizationException $e) {
    echo "  BLOCKED: {$e->getMessage()}\n";
}
echo "\n";

// ============================================================================
// 3. TRANSFERS - Move Funds Between Departments
// ============================================================================

echo "3. TRANSFERS - Inter-Department Transfers\n";
echo "------------------------------------------\n";

// Engineering transfers $10k to Marketing for a joint campaign
$company = $company->apply(Tx::create(
    inputIds: ['eng-backend-q1'],
    outputs: [
        Output::ownedBy('marketing', 10_000, 'mkt-joint-campaign'),
        Output::ownedBy('engineering', 30_000, 'eng-backend-q1-remaining'),
    ],
    signedBy: 'engineering',
    id: 'eng-to-mkt-transfer',
));

echo "Engineering transfers \$10,000 to Marketing for joint campaign.\n";
echo "  Engineering backend remaining: \$30,000\n";
echo "  Marketing received: \$10,000\n\n";

// ============================================================================
// 4. FEES - Administrative Overhead
// ============================================================================

echo "4. FEES - Administrative Overhead on Transfers\n";
echo "-----------------------------------------------\n";

// Operations transfers with 2% admin fee
$company = $company->apply(Tx::create(
    inputIds: ['ops-fy24-budget'],
    outputs: [
        Output::ownedBy('hr', 14_700, 'hr-recruitment-budget'),
        Output::ownedBy('operations', 14_700, 'ops-remaining'),
        // 600 implicit fee = 2% overhead
    ],
    signedBy: 'operations',
    id: 'ops-to-hr-transfer',
));

$fee = $company->feeForTx(new TxId('ops-to-hr-transfer'));
echo "Operations transfers \$14,700 to HR with admin overhead.\n";
echo "  Admin fee (overhead): \${$fee}\n";
echo "  Total overhead collected: \${$company->totalFeesCollected()}\n\n";

// ============================================================================
// 5. MULTI-INPUT - Consolidate Budget Lines
// ============================================================================

echo "5. MULTI-INPUT - Consolidate Budget Lines\n";
echo "------------------------------------------\n";

// Marketing consolidates all their budget lines
$company = $company->apply(Tx::create(
    inputIds: ['mkt-fy24-budget', 'mkt-joint-campaign'],
    outputs: [
        Output::ownedBy('marketing', 60_000, 'mkt-consolidated'),
    ],
    signedBy: 'marketing',
    id: 'mkt-consolidation',
));

echo "Marketing consolidates \$50,000 + \$10,000 = \$60,000\n\n";

// ============================================================================
// 6. RECONCILIATION - Verify Totals
// ============================================================================

echo "6. RECONCILIATION - Quarter-End Verification\n";
echo "----------------------------------------------\n";

$initialBudget = 200_000;
$overhead = $company->totalFeesCollected();
$remaining = $company->totalUnspentAmount();

echo "Reconciliation:\n";
echo "  Initial FY24 budget:    \${$initialBudget}\n";
echo "  Admin overhead:         \${$overhead}\n";
echo "  Remaining in accounts:  \${$remaining}\n";
echo "  Check: {$overhead} + {$remaining} = " . ($overhead + $remaining) . "\n";

if ($overhead + $remaining === $initialBudget) {
    echo "  STATUS: RECONCILED - All funds accounted for\n";
} else {
    echo "  STATUS: DISCREPANCY DETECTED\n";
}
echo "\n";

// ============================================================================
// 7. AUDIT TRAIL - Track Fund Flow
// ============================================================================

echo "7. AUDIT TRAIL - Track Fund Flow\n";
echo "---------------------------------\n";

// Trace the joint campaign funding back to source
echo "Tracing 'mkt-joint-campaign' funding:\n";
$id = new OutputId('mkt-joint-campaign');
$created = $company->outputCreatedBy($id);
$spent = $company->outputSpentBy($id);
echo "  Created by transaction: {$created}\n";
echo "  Spent in transaction: {$spent}\n";

// What was the source of the transfer?
echo "\nTransaction {$created} consumed:\n";
echo "  eng-backend-q1 (originally from eng-fy24-budget)\n";
echo "  -> Proving marketing's \$10k came from engineering\n";

// Full history of HR recruitment budget
echo "\nHR recruitment budget history:\n";
$history = $company->outputHistory(new OutputId('hr-recruitment-budget'));
\assert($history !== null);
echo "  Amount: \${$history['amount']}\n";
echo "  Created by: {$history['createdBy']}\n";
echo "  Status: {$history['status']}\n";
echo "  -> Originated from Operations (ops-fy24-budget via ops-to-hr-transfer)\n\n";

// ============================================================================
// 8. QUERY - Check Remaining Budget Per Department
// ============================================================================

echo "8. QUERY - Department Budget Status\n";
echo "------------------------------------\n";

$departmentBudgets = [];
foreach ($company->unspent() as $id => $output) {
    $lock = $output->lock->toArray();
    /** @phpstan-ignore isset.offset */
    $dept = isset($lock['name']) ? (string) $lock['name'] : 'unassigned';
    $departmentBudgets[$dept] = ($departmentBudgets[$dept] ?? 0) + $output->amount;
}

echo "Current budget by department:\n";
foreach ($departmentBudgets as $dept => $amount) {
    echo "  {$dept}: \$" . number_format($amount) . "\n";
}
echo '  TOTAL: $' . number_format(array_sum($departmentBudgets)) . "\n\n";

// ============================================================================
// 9. BUDGET CONTROL - Prevent Overspending
// ============================================================================

echo "9. BUDGET CONTROL - Prevent Overspending\n";
echo "-----------------------------------------\n";

echo "HR tries to spend more than their budget...\n";
try {
    // HR has 20,000 + 14,700 = 34,700
    $hrTotal = 20_000 + 14_700;
    $company->apply(Tx::create(
        inputIds: ['hr-fy24-budget', 'hr-recruitment-budget'],
        outputs: [Output::ownedBy('vendor', 50_000, 'over-budget')],
        signedBy: 'hr',
        id: 'overspend-attempt',
    ));
} catch (InsufficientInputsException $e) {
    echo "  BLOCKED: {$e->getMessage()}\n";
}
echo "\n";

// ============================================================================
// 10. SERIALIZATION - End-of-Period Snapshot
// ============================================================================

echo "10. SERIALIZATION - End-of-Period Snapshot\n";
echo "-------------------------------------------\n";

// Save Q1 snapshot
$q1Snapshot = $company->toJson(JSON_PRETTY_PRINT);
echo 'Q1 snapshot saved (' . \strlen($q1Snapshot) . " bytes)\n";

// Continue with Q2 operations
$q2Company = Ledger::fromJson($q1Snapshot);
echo "Q2 ledger initialized from Q1 snapshot.\n";
echo "  Starting balance: \${$q2Company->totalUnspentAmount()}\n";

// Verify all history is preserved
$preserved = $q2Company->outputCreatedBy(new OutputId('mkt-consolidated'));
echo "  History preserved: mkt-consolidated created by {$preserved}\n";

// ============================================================================
// SUMMARY
// ============================================================================

echo "\n";
echo "==========================================================\n";
echo " Summary\n";
echo "==========================================================\n\n";

echo "Final budget state:\n";
foreach ($q2Company->unspent() as $id => $output) {
    $lock = $output->lock->toArray();
    /** @phpstan-ignore isset.offset */
    $dept = isset($lock['name']) ? (string) $lock['name'] : 'unassigned';
    echo "  {$id}: \$" . number_format($output->amount) . " ({$dept})\n";
}

echo "\nAll transactions:\n";
$allFees = $q2Company->allTxFees();
foreach ($allFees as $txId => $fee) {
    echo "  {$txId}: overhead = \${$fee}\n";
}

echo "\nFeatures demonstrated:\n";
echo "  - Genesis budget allocation\n";
echo "  - Department ownership/control\n";
echo "  - Inter-department transfers\n";
echo "  - Administrative overhead (fees)\n";
echo "  - Budget line consolidation\n";
echo "  - Reconciliation\n";
echo "  - Audit trail queries\n";
echo "  - Budget queries per department\n";
echo "  - Overspend prevention\n";
echo "  - Period-end snapshots\n";

echo "\n";
