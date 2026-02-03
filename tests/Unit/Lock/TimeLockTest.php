<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit\Lock;

use Chemaclass\Unspent\Exception\AuthorizationException;
use Chemaclass\Unspent\Lock\LockFactory;
use Chemaclass\Unspent\Lock\Owner;
use Chemaclass\Unspent\Lock\TimeLock;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\OutputLock;
use Chemaclass\Unspent\Tx;
use Chemaclass\Unspent\TxId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TimeLockTest extends TestCase
{
    protected function tearDown(): void
    {
        LockFactory::reset();
    }

    public function test_implements_output_lock_interface(): void
    {
        $lock = new TimeLock(new Owner('alice'), time() + 3600);

        self::assertInstanceOf(OutputLock::class, $lock);
    }

    public function test_stores_inner_lock_and_unlock_time(): void
    {
        $innerLock = new Owner('alice');
        $unlockTime = time() + 3600;
        $lock = new TimeLock($innerLock, $unlockTime);

        self::assertSame($innerLock, $lock->innerLock);
        self::assertSame($unlockTime, $lock->unlockTime);
    }

    public function test_throws_on_past_unlock_time(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unlock time must be in the future');

        new TimeLock(new Owner('alice'), time() - 3600);
    }

    public function test_validate_throws_when_still_locked(): void
    {
        $lock = new TimeLock(new Owner('alice'), time() + 3600);
        $tx = new Tx(
            id: new TxId('tx1'),
            spends: [new OutputId('a')],
            outputs: [Output::open(100, 'b')],
            signedBy: 'alice',
        );

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Output is time-locked until');

        $lock->validate($tx, 0);
    }

    public function test_validate_passes_when_unlocked_and_inner_lock_satisfied(): void
    {
        // Create a lock that's already unlocked (using a time in the past via reflection)
        $innerLock = new Owner('alice');
        $lock = TimeLock::alreadyUnlocked($innerLock, time() - 1);

        $tx = new Tx(
            id: new TxId('tx1'),
            spends: [new OutputId('a')],
            outputs: [Output::open(100, 'b')],
            signedBy: 'alice',
        );

        $this->expectNotToPerformAssertions();

        $lock->validate($tx, 0);
    }

    public function test_validate_throws_when_unlocked_but_inner_lock_fails(): void
    {
        $innerLock = new Owner('alice');
        $lock = TimeLock::alreadyUnlocked($innerLock, time() - 1);

        $tx = new Tx(
            id: new TxId('tx1'),
            spends: [new OutputId('a')],
            outputs: [Output::open(100, 'b')],
            signedBy: 'bob', // Wrong signer
        );

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage("Output owned by 'alice', but spend signed by 'bob'");

        $lock->validate($tx, 0);
    }

    public function test_type_returns_timelock(): void
    {
        $lock = new TimeLock(new Owner('alice'), time() + 3600);

        self::assertSame('timelock', $lock->type());
    }

    public function test_to_array_returns_correct_format(): void
    {
        $unlockTime = time() + 3600;
        $lock = new TimeLock(new Owner('alice'), $unlockTime);

        $expected = [
            'type' => 'timelock',
            'unlockTime' => $unlockTime,
            'innerLock' => ['type' => 'owner', 'name' => 'alice'],
        ];

        self::assertSame($expected, $lock->toArray());
    }

    public function test_from_array_creates_lock(): void
    {
        LockFactory::registerFromClass(TimeLock::class);

        $unlockTime = time() + 3600;
        $data = [
            'type' => 'timelock',
            'unlockTime' => $unlockTime,
            'innerLock' => ['type' => 'owner', 'name' => 'alice'],
        ];

        $lock = LockFactory::fromArray($data);

        self::assertInstanceOf(TimeLock::class, $lock);
        self::assertSame($unlockTime, $lock->unlockTime);
        self::assertInstanceOf(Owner::class, $lock->innerLock);
    }

    public function test_is_locked_returns_true_when_locked(): void
    {
        $lock = new TimeLock(new Owner('alice'), time() + 3600);

        self::assertTrue($lock->isLocked());
    }

    public function test_is_locked_returns_false_when_unlocked(): void
    {
        $lock = TimeLock::alreadyUnlocked(new Owner('alice'), time() - 1);

        self::assertFalse($lock->isLocked());
    }

    public function test_remaining_time_returns_positive_when_locked(): void
    {
        $lock = new TimeLock(new Owner('alice'), time() + 3600);

        self::assertGreaterThan(0, $lock->remainingTime());
        self::assertLessThanOrEqual(3600, $lock->remainingTime());
    }

    public function test_remaining_time_returns_zero_when_unlocked(): void
    {
        $lock = TimeLock::alreadyUnlocked(new Owner('alice'), time() - 100);

        self::assertSame(0, $lock->remainingTime());
    }
}
