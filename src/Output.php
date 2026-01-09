<?php

declare(strict_types=1);

namespace Chemaclass\Unspent;

use Chemaclass\Unspent\Lock\NoLock;
use Chemaclass\Unspent\Lock\Owner;
use Chemaclass\Unspent\Lock\PublicKey;
use InvalidArgumentException;

final readonly class Output
{
    /**
     * Maximum amount supported. Limited by PHP's signed 64-bit integer.
     * For larger amounts, consider using string-based arbitrary precision.
     */
    public const MAX_AMOUNT = PHP_INT_MAX;

    /**
     * @param int $amount Positive integer amount (max: PHP_INT_MAX = 9,223,372,036,854,775,807)
     */
    public function __construct(
        public OutputId $id,
        public int $amount,
        public OutputLock $lock,
    ) {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Amount must be positive');
        }
    }

    /**
     * Creates an output owned by a named entity.
     * Use for server-side authentication where the app verifies identity.
     */
    public static function ownedBy(string $owner, int $amount, ?string $id = null): self
    {
        return new self(
            new OutputId($id ?? IdGenerator::forOutput($amount)),
            $amount,
            new Owner($owner),
        );
    }

    /**
     * Creates an output locked by an Ed25519 public key.
     * Requires cryptographic signature to spend (trustless).
     */
    public static function signedBy(string $publicKey, int $amount, ?string $id = null): self
    {
        return new self(
            new OutputId($id ?? IdGenerator::forOutput($amount)),
            $amount,
            new PublicKey($publicKey),
        );
    }

    /**
     * Creates an open output that anyone can spend.
     * Use with caution - explicit intent required.
     */
    public static function open(int $amount, ?string $id = null): self
    {
        return new self(
            new OutputId($id ?? IdGenerator::forOutput($amount)),
            $amount,
            new NoLock(),
        );
    }

    /**
     * Creates an output with a custom lock implementation.
     * Use for advanced scenarios like multisig, timelocks, etc.
     */
    public static function lockedWith(OutputLock $lock, int $amount, ?string $id = null): self
    {
        return new self(
            new OutputId($id ?? IdGenerator::forOutput($amount)),
            $amount,
            $lock,
        );
    }
}
