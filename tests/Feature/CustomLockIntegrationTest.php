<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Feature;

use Chemaclass\Unspent\Exception\AuthorizationException;
use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Lock\LockFactory;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\OutputLock;
use Chemaclass\Unspent\Tx;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Example custom lock for testing.
 */
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
            throw new RuntimeException('Output is time-locked until ' . date('Y-m-d H:i:s', $this->unlockTimestamp));
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

final class CustomLockIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        LockFactory::register('timelock', static fn (array $data): TimeLock => new TimeLock(
            $data['unlockTimestamp'],
            $data['owner'],
        ));
    }

    protected function tearDown(): void
    {
        LockFactory::reset();
    }

    public function test_ledger_round_trip_with_custom_lock(): void
    {
        $original = Ledger::withGenesis(
            Output::lockedWith(
                new TimeLock(strtotime('2020-01-01'), 'alice'),
                1000,
                'locked-funds',
            ),
        );

        $json = $original->toJson();
        $restored = Ledger::fromJson($json);

        $output = $restored->unspent()->get(new OutputId('locked-funds'));
        self::assertNotNull($output);
        self::assertInstanceOf(TimeLock::class, $output->lock);
        self::assertSame('alice', $output->lock->owner);
        self::assertSame(strtotime('2020-01-01'), $output->lock->unlockTimestamp);
    }

    public function test_custom_lock_validation_works_after_deserialization(): void
    {
        $ledger = Ledger::withGenesis(
            Output::lockedWith(
                new TimeLock(strtotime('2020-01-01'), 'alice'),
                1000,
                'unlocked',
            ),
        );

        $restored = Ledger::fromJson($ledger->toJson());

        $newLedger = $restored->apply(Tx::create(
            inputIds: ['unlocked'],
            outputs: [Output::open(1000, 'spent')],
            signedBy: 'alice',
        ));

        self::assertSame(1000, $newLedger->totalUnspentAmount());
    }

    public function test_custom_lock_rejects_wrong_signer(): void
    {
        $ledger = Ledger::withGenesis(
            Output::lockedWith(
                new TimeLock(strtotime('2020-01-01'), 'alice'),
                1000,
                'funds',
            ),
        );

        $restored = Ledger::fromJson($ledger->toJson());

        $this->expectException(AuthorizationException::class);

        $restored->apply(Tx::create(
            inputIds: ['funds'],
            outputs: [Output::open(1000)],
            signedBy: 'bob',
        ));
    }

    public function test_spent_outputs_with_custom_locks_retrievable(): void
    {
        $ledger = Ledger::withGenesis(
            Output::lockedWith(
                new TimeLock(strtotime('2020-01-01'), 'alice'),
                1000,
                'original',
            ),
        )->apply(Tx::create(
            inputIds: ['original'],
            outputs: [Output::open(900, 'new')],
            signedBy: 'alice',
            id: 'tx-1',
        ));

        $restored = Ledger::fromJson($ledger->toJson());

        $spentOutput = $restored->getOutput(new OutputId('original'));

        self::assertNotNull($spentOutput);
        self::assertInstanceOf(TimeLock::class, $spentOutput->lock);
        self::assertSame('alice', $spentOutput->lock->owner);
    }

    public function test_multiple_custom_locks_in_same_ledger(): void
    {
        LockFactory::register('hashlock', static fn (array $data): OutputLock => new class($data['hash']) implements OutputLock {
            public function __construct(public string $hash)
            {
            }

            public function validate(Tx $tx, int $inputIndex): void
            {
            }

            public function toArray(): array
            {
                return ['type' => 'hashlock', 'hash' => $this->hash];
            }
        });

        $ledger = Ledger::withGenesis(
            Output::lockedWith(new TimeLock(strtotime('2020-01-01'), 'alice'), 500, 'time-locked'),
            Output::lockedWith(
                new class('abc123') implements OutputLock {
                    public function __construct(public string $hash)
                    {
                    }

                    public function validate(Tx $tx, int $inputIndex): void
                    {
                    }

                    public function toArray(): array
                    {
                        return ['type' => 'hashlock', 'hash' => $this->hash];
                    }
                },
                500,
                'hash-locked',
            ),
        );

        $restored = Ledger::fromJson($ledger->toJson());

        $timeLocked = $restored->unspent()->get(new OutputId('time-locked'));
        $hashLocked = $restored->unspent()->get(new OutputId('hash-locked'));

        self::assertNotNull($timeLocked);
        self::assertNotNull($hashLocked);
        self::assertInstanceOf(TimeLock::class, $timeLocked->lock);
        // Verify hashlock was restored by checking its serialized form
        self::assertSame(['type' => 'hashlock', 'hash' => 'abc123'], $hashLocked->lock->toArray());
    }
}
