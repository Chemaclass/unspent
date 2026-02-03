<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Persistence;

use Chemaclass\Unspent\Lock\LockType;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputLock;
use RuntimeException;

/**
 * Represents normalized lock data for database storage.
 *
 * Converts an output's lock into separate columns for efficient
 * database queries and indexing.
 */
final readonly class LockData
{
    public function __construct(
        public string $type,
        public ?string $owner,
        public ?string $pubkey,
        public ?string $custom,
    ) {
    }

    /**
     * Extract lock data from an Output.
     */
    public static function fromOutput(Output $output): self
    {
        return self::fromLock($output->lock);
    }

    /**
     * Extract lock data from an OutputLock.
     */
    public static function fromLock(OutputLock $lock): self
    {
        /** @var array<string, mixed> $lockArray */
        $lockArray = $lock->toArray();
        $type = (string) $lockArray['type'];

        $owner = null;
        $pubkey = null;
        $custom = null;

        // Simple locks use dedicated columns for efficient querying
        if ($type === LockType::OWNER->value && \array_key_exists('name', $lockArray)) {
            $owner = (string) $lockArray['name'];
        } elseif ($type === LockType::PUBLIC_KEY->value && \array_key_exists('key', $lockArray)) {
            $pubkey = (string) $lockArray['key'];
        } elseif ($type !== LockType::NONE->value) {
            // Complex locks (timelock, multisig, hashlock, custom) use JSON storage
            $custom = json_encode($lockArray, JSON_THROW_ON_ERROR);
        }

        return new self(
            type: $type,
            owner: $owner,
            pubkey: $pubkey,
            custom: $custom,
        );
    }

    public function isOwnerLock(): bool
    {
        return $this->type === LockType::OWNER->value;
    }

    public function isPublicKeyLock(): bool
    {
        return $this->type === LockType::PUBLIC_KEY->value;
    }

    public function isNoLock(): bool
    {
        return $this->type === LockType::NONE->value;
    }

    public function isCustomLock(): bool
    {
        return !LockType::isBuiltIn($this->type);
    }

    /**
     * Convert a database row to a lock array for LockFactory::fromArray().
     *
     * @param array<string, mixed> $row Database row with lock_type, lock_owner, lock_pubkey, lock_custom_data
     *
     * @return array<string, mixed> Lock array for LockFactory::fromArray()
     */
    public static function toArrayFromRow(array $row): array
    {
        $type = $row['lock_type'];

        // Complex locks (timelock, multisig, hashlock, custom) are stored in custom_data
        if ($row['lock_custom_data'] !== null) {
            return json_decode($row['lock_custom_data'], true, 512, JSON_THROW_ON_ERROR);
        }

        // Simple locks use dedicated columns
        return match (LockType::tryFrom($type)) {
            LockType::NONE => ['type' => LockType::NONE->value],
            LockType::OWNER => ['type' => LockType::OWNER->value, 'name' => $row['lock_owner']],
            LockType::PUBLIC_KEY => ['type' => LockType::PUBLIC_KEY->value, 'key' => $row['lock_pubkey']],
            // Complex built-in locks should have custom_data, but fallback just in case
            LockType::TIMELOCK, LockType::MULTISIG, LockType::HASHLOCK => throw new RuntimeException(
                "Lock type '{$type}' requires custom_data but none was found",
            ),
            null => throw new RuntimeException("Unknown lock type: {$type}"),
        };
    }

    public function isTimeLock(): bool
    {
        return $this->type === LockType::TIMELOCK->value;
    }

    public function isMultisigLock(): bool
    {
        return $this->type === LockType::MULTISIG->value;
    }

    public function isHashLock(): bool
    {
        return $this->type === LockType::HASHLOCK->value;
    }

    /**
     * Check if this is a complex lock type (stored in custom_data).
     */
    public function isComplexLock(): bool
    {
        return $this->custom !== null;
    }
}
