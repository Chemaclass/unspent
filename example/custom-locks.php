<?php

declare(strict_types=1);

/**
 * Custom Locks - Time-Locked Outputs
 *
 * Shows how to create and serialize custom lock types.
 *
 * Run: php example/custom-locks.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Chemaclass\Unspent\Exception\AuthorizationException;
use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Lock\LockFactory;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\OutputLock;
use Chemaclass\Unspent\Tx;

echo "Custom Locks Example\n";
echo "====================\n\n";

// 1. Define custom lock
final readonly class TimeLock implements OutputLock
{
    public function __construct(
        public int $unlockTime,
        public string $owner,
    ) {
    }

    public function validate(Tx $tx, int $inputIndex): void
    {
        if (time() < $this->unlockTime) {
            throw new RuntimeException('Still locked until ' . date('Y-m-d', $this->unlockTime));
        }
        if ($tx->signedBy !== $this->owner) {
            throw AuthorizationException::notOwner($this->owner, $tx->signedBy);
        }
    }

    public function toArray(): array
    {
        return [
            'type' => 'timelock',
            'unlockTime' => $this->unlockTime,
            'owner' => $this->owner,
        ];
    }
}

// 2. Register handler for deserialization
LockFactory::register('timelock', static fn ($data): TimeLock => new TimeLock(
    $data['unlockTime'],
    $data['owner'],
));
echo "Registered 'timelock' handler\n\n";

// 3. Create outputs with custom locks
$ledger = Ledger::withGenesis(
    Output::lockedWith(new TimeLock(strtotime('2020-01-01'), 'alice'), 1000, 'unlocked'),
    Output::lockedWith(new TimeLock(strtotime('+1 year'), 'bob'), 500, 'still-locked'),
);

// 4. Serialize and restore
$json = $ledger->toJson();
$restored = Ledger::fromJson($json);

$output = $restored->unspent()->get(new OutputId('unlocked'));
echo 'Restored lock type: ' . $output?->lock::class . "\n\n";

// 5. Spend unlocked output
$restored = $restored->apply(Tx::create(
    spendIds: ['unlocked'],
    outputs: [Output::ownedBy('charlie', 1000)],
    signedBy: 'alice',
));
echo "Alice spent unlocked funds\n";

// 6. Locked output blocked
echo 'Bob tries to spend locked output... ';
try {
    $restored->apply(Tx::create(
        spendIds: ['still-locked'],
        outputs: [Output::open(500)],
        signedBy: 'bob',
    ));
} catch (RuntimeException $e) {
    echo 'BLOCKED: ' . $e->getMessage() . "\n";
}

// 7. Wrong signer blocked
echo "Eve tries to spend Alice's output... ";
$ledger2 = Ledger::withGenesis(
    Output::lockedWith(new TimeLock(strtotime('2020-01-01'), 'alice'), 100, 'alice-funds'),
);
try {
    $ledger2->apply(Tx::create(
        spendIds: ['alice-funds'],
        outputs: [Output::open(100)],
        signedBy: 'eve',
    ));
} catch (AuthorizationException) {
    echo "BLOCKED\n";
}

LockFactory::reset();
