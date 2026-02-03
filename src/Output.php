<?php

declare(strict_types=1);

namespace Chemaclass\Unspent;

use Chemaclass\Unspent\Lock\HashLock;
use Chemaclass\Unspent\Lock\MultisigLock;
use Chemaclass\Unspent\Lock\NoLock;
use Chemaclass\Unspent\Lock\Owner;
use Chemaclass\Unspent\Lock\PublicKey;
use Chemaclass\Unspent\Lock\TimeLock;
use InvalidArgumentException;

final readonly class Output
{
    /**
     * Maximum amount supported. Limited by PHP's signed 64-bit integer.
     * For larger amounts, consider using string-based arbitrary precision.
     */
    public const int MAX_AMOUNT = PHP_INT_MAX;

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
     *
     * @throws InvalidArgumentException If amount is not positive or owner is empty
     */
    public static function ownedBy(string $owner, int $amount, ?string $id = null): self
    {
        return new self(
            id: new OutputId($id ?? IdGenerator::forOutput($amount)),
            amount: $amount,
            lock: new Owner($owner),
        );
    }

    /**
     * Creates an output locked by an Ed25519 public key.
     * Requires cryptographic signature to spend (trustless).
     *
     * @throws InvalidArgumentException If amount is not positive or public key is invalid
     */
    public static function signedBy(string $publicKey, int $amount, ?string $id = null): self
    {
        return new self(
            id: new OutputId($id ?? IdGenerator::forOutput($amount)),
            amount: $amount,
            lock: new PublicKey($publicKey),
        );
    }

    /**
     * Creates an open output that anyone can spend.
     * Use with caution - explicit intent required.
     *
     * @throws InvalidArgumentException If amount is not positive
     */
    public static function open(int $amount, ?string $id = null): self
    {
        return new self(
            id: new OutputId($id ?? IdGenerator::forOutput($amount)),
            amount: $amount,
            lock: new NoLock(),
        );
    }

    /**
     * Creates an output with a custom lock implementation.
     * Use for advanced scenarios like multisig, timelocks, etc.
     *
     * @throws InvalidArgumentException If amount is not positive
     */
    public static function lockedWith(OutputLock $lock, int $amount, ?string $id = null): self
    {
        return new self(
            id: new OutputId($id ?? IdGenerator::forOutput($amount)),
            amount: $amount,
            lock: $lock,
        );
    }

    /**
     * Creates an output that can only be spent after a specific timestamp.
     *
     * @param int $unlockTime Unix timestamp when the output becomes spendable
     *
     * @throws InvalidArgumentException If amount is not positive or unlock time is in the past
     */
    public static function timelocked(string $owner, int $amount, int $unlockTime, ?string $id = null): self
    {
        return new self(
            id: new OutputId($id ?? IdGenerator::forOutput($amount)),
            amount: $amount,
            lock: new TimeLock(new Owner($owner), $unlockTime),
        );
    }

    /**
     * Creates an output requiring M-of-N signatures to spend.
     *
     * @param int          $threshold Minimum signatures required
     * @param list<string> $signers   Authorized signer names
     *
     * @throws InvalidArgumentException If amount is not positive or multisig config is invalid
     */
    public static function multisig(int $threshold, array $signers, int $amount, ?string $id = null): self
    {
        return new self(
            id: new OutputId($id ?? IdGenerator::forOutput($amount)),
            amount: $amount,
            lock: new MultisigLock($threshold, $signers),
        );
    }

    /**
     * Creates an output requiring knowledge of a hash preimage to spend.
     *
     * @param string $hash      The hash that the preimage must match
     * @param string $algorithm Hash algorithm (sha256, sha512, ripemd160)
     *
     * @throws InvalidArgumentException If amount is not positive or hash config is invalid
     */
    public static function hashlocked(string $hash, int $amount, string $algorithm = 'sha256', ?string $owner = null, ?string $id = null): self
    {
        $innerLock = $owner !== null ? new Owner($owner) : null;

        return new self(
            id: new OutputId($id ?? IdGenerator::forOutput($amount)),
            amount: $amount,
            lock: HashLock::fromHash($hash, $algorithm, $innerLock),
        );
    }
}
