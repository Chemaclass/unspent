<?php

declare(strict_types=1);

namespace Chemaclass\Unspent;

use Chemaclass\Unspent\Exception\DuplicateOutputIdException;
use Chemaclass\Unspent\Validation\DuplicateValidator;
use InvalidArgumentException;

final readonly class Tx
{
    /**
     * @param list<OutputId> $spends  IDs of outputs being spent (consumed)
     * @param list<Output>   $outputs New outputs being created
     * @param list<string>   $proofs  Authorization proofs indexed by spend position
     */
    public function __construct(
        public TxId $id,
        public array $spends,
        public array $outputs,
        public ?string $signedBy = null,
        public array $proofs = [],
    ) {
        if ($spends === []) {
            throw new InvalidArgumentException('Tx must have at least one spend');
        }

        if ($outputs === []) {
            throw new InvalidArgumentException('Tx must have at least one output');
        }

        DuplicateValidator::assertNoDuplicateSpendIds($spends);
        DuplicateValidator::assertNoDuplicateOutputIds($outputs);
    }

    /**
     * Creates a new transaction.
     *
     * @param list<string> $spendIds IDs of outputs to spend (consume)
     * @param list<Output> $outputs  New outputs to create
     * @param list<string> $proofs   Authorization proofs indexed by input position
     *
     * @throws InvalidArgumentException   If spendIds or outputs is empty, or duplicate spend IDs found
     * @throws DuplicateOutputIdException If any output ID appears more than once
     */
    public static function create(
        array $spendIds,
        array $outputs,
        ?string $signedBy = null,
        ?string $id = null,
        array $proofs = [],
    ): self {
        $actualId = $id ?? IdGenerator::forTx($spendIds, $outputs);

        return new self(
            id: new TxId($actualId),
            spends: array_map(
                static fn (string $spendId): OutputId => new OutputId($spendId),
                $spendIds,
            ),
            outputs: $outputs,
            signedBy: $signedBy,
            proofs: $proofs,
        );
    }

    public function totalOutputAmount(): int
    {
        return array_sum(array_map(
            static fn (Output $output): int => $output->amount,
            $this->outputs,
        ));
    }
}
