<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Lock;

use Chemaclass\Unspent\Exception\AuthorizationException;
use Chemaclass\Unspent\OutputLock;
use Chemaclass\Unspent\Tx;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionProperty;

/**
 * A lock that restricts spending until a specific timestamp.
 *
 * After the unlock time, the inner lock's conditions must also be satisfied.
 * Use for vesting schedules, delayed payments, or escrow timeouts.
 *
 * @example
 * // Lock until 30 days from now
 * $lock = new TimeLock(new Owner('alice'), strtotime('+30 days'));
 *
 * // Create output
 * Output::lockedWith($lock, 1000);
 */
#[LockTypeAttribute('timelock')]
final readonly class TimeLock implements OutputLock
{
    public function __construct(
        public OutputLock $innerLock,
        public int $unlockTime,
    ) {
        if ($unlockTime <= time()) {
            throw new InvalidArgumentException('Unlock time must be in the future');
        }
    }

    /**
     * Creates a TimeLock that is already past its unlock time.
     * Primarily for testing or loading from persistence.
     */
    public static function alreadyUnlocked(OutputLock $innerLock, int $unlockTime): self
    {
        $instance = new ReflectionClass(self::class)->newInstanceWithoutConstructor();

        $innerLockProp = new ReflectionProperty(self::class, 'innerLock');
        $innerLockProp->setValue($instance, $innerLock);

        $unlockTimeProp = new ReflectionProperty(self::class, 'unlockTime');
        $unlockTimeProp->setValue($instance, $unlockTime);

        return $instance;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $innerLock = LockFactory::fromArray($data['innerLock']);
        $unlockTime = (int) $data['unlockTime'];

        // Use alreadyUnlocked to avoid validation when loading from storage
        if ($unlockTime <= time()) {
            return self::alreadyUnlocked($innerLock, $unlockTime);
        }

        return new self($innerLock, $unlockTime);
    }

    public function validate(Tx $tx, int $spendIndex): void
    {
        if ($this->isLocked()) {
            throw new AuthorizationException(
                \sprintf(
                    'Output is time-locked until %s (%d seconds remaining)',
                    date('Y-m-d H:i:s', $this->unlockTime),
                    $this->remainingTime(),
                ),
                AuthorizationException::CODE_NOT_OWNER,
            );
        }

        // Time condition met, check inner lock
        $this->innerLock->validate($tx, $spendIndex);
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type(),
            'unlockTime' => $this->unlockTime,
            'innerLock' => $this->innerLock->toArray(),
        ];
    }

    public function type(): string
    {
        return 'timelock';
    }

    /**
     * Check if the lock is still active.
     */
    public function isLocked(): bool
    {
        return time() < $this->unlockTime;
    }

    /**
     * Get remaining time until unlock in seconds.
     */
    public function remainingTime(): int
    {
        return max(0, $this->unlockTime - time());
    }
}
