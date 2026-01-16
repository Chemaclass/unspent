<?php

declare(strict_types=1);

namespace Chemaclass\Unspent;

use InvalidArgumentException;

/**
 * Fluent builder for creating transactions.
 *
 * Provides a clean API for constructing complex transactions with multiple
 * inputs and outputs.
 *
 * Usage:
 *     $tx = TxBuilder::new()
 *         ->spend('output-1', 'output-2')
 *         ->output('bob', 100)
 *         ->output('alice', 50)  // change
 *         ->signedBy('alice')
 *         ->build();
 */
final class TxBuilder
{
    /** @var list<string> */
    private array $spendIds = [];

    /** @var list<Output> */
    private array $outputs = [];

    /** @var list<string> */
    private array $proofs = [];

    private ?string $signedBy = null;
    private ?string $txId = null;

    private function __construct()
    {
    }

    public static function new(): self
    {
        return new self();
    }

    /**
     * Add output IDs to spend (consume).
     *
     * @param string ...$ids Output IDs to spend
     */
    public function spend(string ...$ids): self
    {
        $this->spendIds = array_values([...$this->spendIds, ...$ids]);

        return $this;
    }

    /**
     * Add a single output to create.
     *
     * @param string      $owner  Owner of the new output
     * @param int         $amount Amount for the output
     * @param string|null $id     Optional custom output ID
     */
    public function output(string $owner, int $amount, ?string $id = null): self
    {
        $this->outputs[] = Output::ownedBy($owner, $amount, $id);

        return $this;
    }

    /**
     * Add an open (unlocked) output.
     *
     * @param int         $amount Amount for the output
     * @param string|null $id     Optional custom output ID
     */
    public function openOutput(int $amount, ?string $id = null): self
    {
        $this->outputs[] = Output::open($amount, $id);

        return $this;
    }

    /**
     * Add an output with a public key lock.
     *
     * @param string      $publicKey Ed25519 public key
     * @param int         $amount    Amount for the output
     * @param string|null $id        Optional custom output ID
     */
    public function signedOutput(string $publicKey, int $amount, ?string $id = null): self
    {
        $this->outputs[] = Output::signedBy($publicKey, $amount, $id);

        return $this;
    }

    /**
     * Add a custom output.
     */
    public function addOutput(Output $output): self
    {
        $this->outputs[] = $output;

        return $this;
    }

    /**
     * Set the signer for owner-locked outputs.
     */
    public function signedBy(string $signer): self
    {
        $this->signedBy = $signer;

        return $this;
    }

    /**
     * Add cryptographic proofs for signature verification.
     *
     * @param string ...$proofs Signatures indexed by spend position
     */
    public function withProofs(string ...$proofs): self
    {
        $this->proofs = array_values([...$this->proofs, ...$proofs]);

        return $this;
    }

    /**
     * Set a custom transaction ID.
     */
    public function withId(string $id): self
    {
        $this->txId = $id;

        return $this;
    }

    /**
     * Build the transaction.
     *
     * @throws InvalidArgumentException If no spends or outputs defined
     */
    public function build(): Tx
    {
        if ($this->spendIds === []) {
            throw new InvalidArgumentException('TxBuilder: at least one spend is required');
        }

        if ($this->outputs === []) {
            throw new InvalidArgumentException('TxBuilder: at least one output is required');
        }

        return Tx::create(
            spendIds: $this->spendIds,
            outputs: $this->outputs,
            signedBy: $this->signedBy,
            id: $this->txId,
            proofs: $this->proofs,
        );
    }

    /**
     * Reset the builder for reuse.
     */
    public function reset(): self
    {
        $this->spendIds = [];
        $this->outputs = [];
        $this->proofs = [];
        $this->signedBy = null;
        $this->txId = null;

        return $this;
    }
}
