<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Lock;

use Chemaclass\Unspent\Exception\AuthorizationException;
use Chemaclass\Unspent\OutputLock;
use Chemaclass\Unspent\Tx;
use InvalidArgumentException;

/**
 * A lock requiring M-of-N signatures to spend.
 *
 * Use for escrow, joint accounts, or governance scenarios where
 * multiple parties must authorize a spend.
 *
 * Signatures are provided in the proof field as comma-separated signer names.
 *
 * @example
 * // 2-of-3 multisig
 * $lock = new MultisigLock(2, ['alice', 'bob', 'charlie']);
 *
 * // Spend with proof
 * Tx::create(
 *     spendIds: ['output-id'],
 *     outputs: [...],
 *     proofs: ['alice,bob'], // Comma-separated signers
 * );
 */
#[LockTypeAttribute('multisig')]
final readonly class MultisigLock implements OutputLock
{
    /**
     * @param int          $threshold Minimum signatures required (M)
     * @param list<string> $signers   Authorized signer names (N)
     */
    public function __construct(
        public int $threshold,
        public array $signers,
    ) {
        if ($signers === []) {
            throw new InvalidArgumentException('At least one signer is required');
        }

        if ($threshold < 1) {
            throw new InvalidArgumentException('Threshold must be at least 1');
        }

        if ($threshold > \count($signers)) {
            throw new InvalidArgumentException(
                \sprintf('Threshold (%d) cannot exceed number of signers (%d)', $threshold, \count($signers)),
            );
        }

        $seen = [];
        foreach ($signers as $signer) {
            if (trim($signer) === '') {
                throw new InvalidArgumentException('Signer names cannot be empty');
            }
            if (isset($seen[$signer])) {
                throw new InvalidArgumentException("Duplicate signer: {$signer}");
            }
            $seen[$signer] = true;
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            threshold: (int) $data['threshold'],
            signers: $data['signers'],
        );
    }

    public function validate(Tx $tx, int $spendIndex): void
    {
        $proof = $tx->proofs[$spendIndex] ?? null;

        if ($proof === null) {
            throw AuthorizationException::missingProof($spendIndex);
        }

        // Parse comma-separated signers
        $providedSigners = array_unique(array_filter(
            array_map(trim(...), explode(',', $proof)),
            static fn (string $s): bool => $s !== '',
        ));

        // Verify each signer is authorized
        $validSigners = [];
        foreach ($providedSigners as $signer) {
            if (!\in_array($signer, $this->signers, true)) {
                throw new AuthorizationException(
                    "'{$signer}' is not an authorized signer",
                    AuthorizationException::CODE_NOT_OWNER,
                );
            }
            $validSigners[] = $signer;
        }

        // Check threshold
        if (\count($validSigners) < $this->threshold) {
            throw new AuthorizationException(
                \sprintf('Multisig requires %d signatures, got %d', $this->threshold, \count($validSigners)),
                AuthorizationException::CODE_NOT_OWNER,
            );
        }
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type(),
            'threshold' => $this->threshold,
            'signers' => $this->signers,
        ];
    }

    public function type(): string
    {
        return 'multisig';
    }

    /**
     * Human-readable description of the multisig configuration.
     */
    public function description(): string
    {
        return \sprintf('%d-of-%d multisig', $this->threshold, \count($this->signers));
    }
}
