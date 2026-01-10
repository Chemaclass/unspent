<?php

declare(strict_types=1);

/**
 * Custom Locks Example - Extensible Lock Types
 *
 * Demonstrates how to create custom lock types and register them
 * with LockFactory for transparent serialization/deserialization.
 *
 * Run with: php example/custom-locks.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Chemaclass\Unspent\Exception\AuthorizationException;
use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Lock\LockFactory;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\OutputLock;
use Chemaclass\Unspent\Tx;

echo "==========================================================\n";
echo " Custom Locks Example - Extensible Lock Types\n";
echo "==========================================================\n\n";

// ============================================================================
// 1. DEFINE CUSTOM LOCK: TimeLock
// ============================================================================
//
// A TimeLock prevents spending until a specific timestamp has passed.
// After that, only the designated owner can spend it.

final readonly class TimeLock implements OutputLock
{
    public function __construct(
        public int $unlockTimestamp,
        public string $owner,
    ) {
    }

    public function validate(Tx $tx, int $inputIndex): void
    {
        if (time() < $this->unlockTimestamp) {
            throw new RuntimeException(
                'Output is time-locked until ' . date('Y-m-d H:i:s', $this->unlockTimestamp),
            );
        }

        if ($tx->signedBy !== $this->owner) {
            throw AuthorizationException::notOwner($this->owner, $tx->signedBy);
        }
    }

    public function toArray(): array
    {
        return [
            'type' => 'timelock',
            'unlockTimestamp' => $this->unlockTimestamp,
            'owner' => $this->owner,
        ];
    }
}

echo "1. DEFINE CUSTOM LOCK\n";
echo "---------------------\n";
echo "Created TimeLock class implementing OutputLock interface.\n";
echo "  - Prevents spending until unlockTimestamp\n";
echo "  - After unlock, only owner can spend\n\n";

// ============================================================================
// 2. REGISTER CUSTOM LOCK HANDLER
// ============================================================================
//
// Register with LockFactory BEFORE any deserialization.
// This allows Ledger::fromJson() to restore custom locks.

LockFactory::register('timelock', static fn (array $data): TimeLock => new TimeLock(
    $data['unlockTimestamp'],
    $data['owner'],
));

echo "2. REGISTER HANDLER\n";
echo "-------------------\n";
echo "Registered 'timelock' handler with LockFactory.\n";
echo "  LockFactory::hasHandler('timelock'): " . (LockFactory::hasHandler('timelock') ? 'true' : 'false') . "\n";
echo "  LockFactory::registeredTypes(): ['" . implode("', '", LockFactory::registeredTypes()) . "']\n\n";

// ============================================================================
// 3. CREATE LEDGER WITH CUSTOM LOCK
// ============================================================================

$unlockTime = strtotime('2020-01-01'); // Already unlocked (in the past)
$futureUnlock = strtotime('+1 year');   // Still locked

$ledger = Ledger::withGenesis(
    Output::lockedWith(
        new TimeLock($unlockTime, 'alice'),
        1000,
        'alice-unlocked',
    ),
    Output::lockedWith(
        new TimeLock($futureUnlock, 'bob'),
        500,
        'bob-locked',
    ),
);

echo "3. CREATE LEDGER\n";
echo "----------------\n";
echo "Created ledger with two time-locked outputs:\n";
echo "  - alice-unlocked: 1000 (unlocks 2020-01-01) - UNLOCKED\n";
echo '  - bob-locked: 500 (unlocks ' . date('Y-m-d', $futureUnlock) . ") - STILL LOCKED\n\n";

// ============================================================================
// 4. SERIALIZE AND DESERIALIZE
// ============================================================================

$json = $ledger->toJson(JSON_PRETTY_PRINT);

echo "4. SERIALIZATION\n";
echo "----------------\n";
echo "Ledger serialized to JSON:\n";
echo $json . "\n\n";

// Deserialize - custom locks restored transparently!
$restored = Ledger::fromJson($json);

echo "Ledger restored from JSON.\n";

$aliceOutput = $restored->unspent()->get(new OutputId('alice-unlocked'));
$bobOutput = $restored->unspent()->get(new OutputId('bob-locked'));
\assert($aliceOutput !== null && $bobOutput !== null);

echo '  alice-unlocked lock type: ' . $aliceOutput->lock::class . "\n";
echo '  bob-locked lock type: ' . $bobOutput->lock::class . "\n\n";

// ============================================================================
// 5. SPEND UNLOCKED OUTPUT
// ============================================================================

echo "5. SPEND UNLOCKED OUTPUT\n";
echo "------------------------\n";

$restored = $restored->apply(Tx::create(
    spendIds: ['alice-unlocked'],
    outputs: [Output::ownedBy('charlie', 1000, 'charlie-funds')],
    signedBy: 'alice',
    id: 'tx-alice-spends',
));

echo "Alice successfully spent her unlocked funds.\n";
$charlieFunds = $restored->unspent()->get(new OutputId('charlie-funds'));
\assert($charlieFunds !== null);
echo '  charlie-funds: ' . $charlieFunds->amount . "\n\n";

// ============================================================================
// 6. ATTEMPT TO SPEND LOCKED OUTPUT (FAILS)
// ============================================================================

echo "6. ATTEMPT LOCKED SPEND\n";
echo "-----------------------\n";

try {
    $restored->apply(Tx::create(
        spendIds: ['bob-locked'],
        outputs: [Output::open(500)],
        signedBy: 'bob',
    ));
    echo "ERROR: Should have thrown!\n";
} catch (RuntimeException $e) {
    echo 'Correctly rejected: ' . $e->getMessage() . "\n\n";
}

// ============================================================================
// 7. WRONG SIGNER (FAILS)
// ============================================================================

echo "7. WRONG SIGNER\n";
echo "---------------\n";

// Create a new ledger with an already-unlocked output
$ledger2 = Ledger::withGenesis(
    Output::lockedWith(
        new TimeLock(strtotime('2020-01-01'), 'alice'),
        100,
        'alice-funds',
    ),
);

try {
    $ledger2->apply(Tx::create(
        spendIds: ['alice-funds'],
        outputs: [Output::open(100)],
        signedBy: 'eve', // Wrong signer!
    ));
    echo "ERROR: Should have thrown!\n";
} catch (AuthorizationException $e) {
    echo 'Correctly rejected: ' . $e->getMessage() . "\n\n";
}

// ============================================================================
// 8. VERIFY HISTORY PRESERVED
// ============================================================================

echo "8. HISTORY PRESERVED\n";
echo "--------------------\n";

echo "Output history after deserialization:\n";
echo '  alice-unlocked created by: ' . $restored->outputCreatedBy(new OutputId('alice-unlocked')) . "\n";
echo '  alice-unlocked spent by: ' . $restored->outputSpentBy(new OutputId('alice-unlocked')) . "\n";
echo '  charlie-funds created by: ' . $restored->outputCreatedBy(new OutputId('charlie-funds')) . "\n\n";

// ============================================================================
// SUMMARY
// ============================================================================

echo "==========================================================\n";
echo " Summary\n";
echo "==========================================================\n\n";

echo "Custom lock workflow:\n";
echo "  1. Implement OutputLock interface with validate() and toArray()\n";
echo "  2. Register handler: LockFactory::register('type', fn(\$data) => ...)\n";
echo "  3. Use Output::lockedWith(new CustomLock(...), amount, id)\n";
echo "  4. Serialize/deserialize works transparently\n\n";

echo "Key methods:\n";
echo "  LockFactory::register(type, handler)  - Register custom handler\n";
echo "  LockFactory::hasHandler(type)         - Check if registered\n";
echo "  LockFactory::registeredTypes()        - List registered types\n";
echo "  LockFactory::reset()                  - Clear handlers (testing)\n\n";

// Clean up for other tests
LockFactory::reset();
