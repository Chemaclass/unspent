<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Lock;

use Chemaclass\Unspent\Exception\AuthorizationException;
use Chemaclass\Unspent\OutputLock;
use Chemaclass\Unspent\Tx;
use InvalidArgumentException;

/**
 * A lock requiring knowledge of a hash preimage to spend.
 *
 * Also known as HTLC (Hash Time-Locked Contract) when combined with TimeLock.
 * Use for atomic swaps, payment channels, or conditional payments.
 *
 * The preimage is provided in the proof field for the spend.
 *
 * @example
 * // Create lock from secret (hashes automatically)
 * $lock = HashLock::sha256('my-secret', new Owner('alice'));
 *
 * // Create lock from existing hash
 * $lock = HashLock::fromHash($hash, 'sha256', new Owner('alice'));
 *
 * // Spend with preimage
 * Tx::create(
 *     spendIds: ['output-id'],
 *     outputs: [...],
 *     signedBy: 'alice',
 *     proofs: ['my-secret'], // The preimage
 * );
 */
#[LockTypeAttribute('hashlock')]
final readonly class HashLock implements OutputLock
{
    private const array SUPPORTED_ALGORITHMS = ['sha256', 'sha512', 'ripemd160', 'sha3-256'];

    public function __construct(
        public string $hash,
        public string $algorithm,
        public ?OutputLock $innerLock = null,
    ) {
        if (trim($hash) === '') {
            throw new InvalidArgumentException('Hash cannot be empty');
        }

        if (!\in_array($algorithm, self::SUPPORTED_ALGORITHMS, true)) {
            throw new InvalidArgumentException("Unsupported hash algorithm: {$algorithm}");
        }
    }

    /**
     * Create a HashLock by hashing a secret with SHA-256.
     */
    public static function sha256(string $secret, ?OutputLock $innerLock = null): self
    {
        return new self(
            hash: hash('sha256', $secret),
            algorithm: 'sha256',
            innerLock: $innerLock,
        );
    }

    /**
     * Create a HashLock from an existing hash.
     */
    public static function fromHash(string $hash, string $algorithm, ?OutputLock $innerLock = null): self
    {
        return new self($hash, $algorithm, $innerLock);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $innerLock = isset($data['innerLock'])
            ? LockFactory::fromArray($data['innerLock'])
            : null;

        return new self(
            hash: (string) $data['hash'],
            algorithm: (string) $data['algorithm'],
            innerLock: $innerLock,
        );
    }

    public function validate(Tx $tx, int $spendIndex): void
    {
        $preimage = $tx->proofs[$spendIndex] ?? null;

        if ($preimage === null) {
            throw AuthorizationException::missingProof($spendIndex);
        }

        // Verify preimage hashes to expected value
        if (!$this->verifyPreimage($preimage)) {
            throw new AuthorizationException(
                'Invalid hash preimage',
                AuthorizationException::CODE_INVALID_SIGNATURE,
            );
        }

        // Also check inner lock if present
        $this->innerLock?->validate($tx, $spendIndex);
    }

    public function toArray(): array
    {
        $data = [
            'type' => $this->type(),
            'hash' => $this->hash,
            'algorithm' => $this->algorithm,
        ];

        if ($this->innerLock !== null) {
            $data['innerLock'] = $this->innerLock->toArray();
        }

        return $data;
    }

    public function type(): string
    {
        return 'hashlock';
    }

    /**
     * Verify that a preimage hashes to the expected value.
     */
    public function verifyPreimage(string $preimage): bool
    {
        return hash_equals($this->hash, hash($this->algorithm, $preimage));
    }
}
