<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests;

use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\Spend;
use Chemaclass\Unspent\SpendId;
use Chemaclass\Unspent\Exception\DuplicateOutputIdException;
use Chemaclass\Unspent\Exception\DuplicateSpendException;
use Chemaclass\Unspent\Exception\GenesisNotAllowedException;
use Chemaclass\Unspent\Exception\UnbalancedSpendException;
use Chemaclass\Unspent\Exception\OutputAlreadySpentException;
use PHPUnit\Framework\TestCase;

final class LedgerTest extends TestCase
{
    public function test_empty_ledger_has_zero_unspent(): void
    {
        $ledger = Ledger::empty();

        self::assertSame(0, $ledger->totalUnspentAmount());
        self::assertTrue($ledger->unspent()->isEmpty());
    }

    public function test_can_add_genesis_outputs(): void
    {
        $output1 = new Output(new OutputId('genesis-1'), 100);
        $output2 = new Output(new OutputId('genesis-2'), 50);

        $ledger = Ledger::empty()->addGenesis($output1, $output2);

        self::assertSame(150, $ledger->totalUnspentAmount());
        self::assertSame(2, $ledger->unspent()->count());
    }

    public function test_genesis_only_allowed_on_empty_ledger(): void
    {
        $this->expectException(GenesisNotAllowedException::class);
        $this->expectExceptionMessage('Genesis outputs can only be added to an empty ledger');

        $ledger = Ledger::empty()
            ->addGenesis(new Output(new OutputId('a'), 100))
            ->addGenesis(new Output(new OutputId('b'), 50));
    }

    public function test_genesis_fails_on_duplicate_output_ids(): void
    {
        $this->expectException(DuplicateOutputIdException::class);
        $this->expectExceptionMessage("Duplicate output id: 'a'");

        Ledger::empty()->addGenesis(
            new Output(new OutputId('a'), 100),
            new Output(new OutputId('a'), 50),
        );
    }

    public function test_apply_spend_happy_path(): void
    {
        $ledger = Ledger::empty()
            ->addGenesis(
                new Output(new OutputId('a'), 100),
                new Output(new OutputId('b'), 50),
            )
            ->apply(new Spend(
                id: new SpendId('tx1'),
                inputs: [new OutputId('a')],
                outputs: [
                    new Output(new OutputId('c'), 60),
                    new Output(new OutputId('d'), 40),
                ],
            ));

        self::assertSame(150, $ledger->totalUnspentAmount());
        self::assertFalse($ledger->unspent()->contains(new OutputId('a')));
        self::assertTrue($ledger->unspent()->contains(new OutputId('b')));
        self::assertTrue($ledger->unspent()->contains(new OutputId('c')));
        self::assertTrue($ledger->unspent()->contains(new OutputId('d')));
    }

    public function test_apply_spend_fails_when_input_not_in_unspent_set(): void
    {
        $this->expectException(OutputAlreadySpentException::class);
        $this->expectExceptionMessage("Output 'nonexistent' is not in the unspent set");

        Ledger::empty()
            ->addGenesis(new Output(new OutputId('a'), 100))
            ->apply(new Spend(
                id: new SpendId('tx1'),
                inputs: [new OutputId('nonexistent')],
                outputs: [new Output(new OutputId('b'), 100)],
            ));
    }

    public function test_apply_spend_fails_when_amounts_dont_balance(): void
    {
        $this->expectException(UnbalancedSpendException::class);
        $this->expectExceptionMessage('Spend is unbalanced: input amount (100) does not equal output amount (50)');

        Ledger::empty()
            ->addGenesis(new Output(new OutputId('a'), 100))
            ->apply(new Spend(
                id: new SpendId('tx1'),
                inputs: [new OutputId('a')],
                outputs: [new Output(new OutputId('b'), 50)],
            ));
    }

    public function test_apply_same_spend_twice_fails(): void
    {
        $this->expectException(DuplicateSpendException::class);
        $this->expectExceptionMessage("Spend 'tx1' has already been applied");

        $spend1 = new Spend(
            id: new SpendId('tx1'),
            inputs: [new OutputId('a')],
            outputs: [new Output(new OutputId('b'), 100)],
        );

        $spend2 = new Spend(
            id: new SpendId('tx1'),
            inputs: [new OutputId('b')],
            outputs: [new Output(new OutputId('c'), 100)],
        );

        Ledger::empty()
            ->addGenesis(new Output(new OutputId('a'), 100))
            ->apply($spend1)
            ->apply($spend2);
    }

    public function test_output_can_only_be_spent_once(): void
    {
        $this->expectException(OutputAlreadySpentException::class);
        $this->expectExceptionMessage("Output 'a' is not in the unspent set");

        $ledger = Ledger::empty()
            ->addGenesis(new Output(new OutputId('a'), 100))
            ->apply(new Spend(
                id: new SpendId('tx1'),
                inputs: [new OutputId('a')],
                outputs: [new Output(new OutputId('b'), 100)],
            ));

        $ledger->apply(new Spend(
            id: new SpendId('tx2'),
            inputs: [new OutputId('a')],
            outputs: [new Output(new OutputId('c'), 100)],
        ));
    }

    public function test_spend_output_ids_must_be_unique(): void
    {
        $this->expectException(DuplicateOutputIdException::class);
        $this->expectExceptionMessage("Duplicate output id: 'c'");

        Ledger::empty()
            ->addGenesis(new Output(new OutputId('a'), 100))
            ->apply(new Spend(
                id: new SpendId('tx1'),
                inputs: [new OutputId('a')],
                outputs: [
                    new Output(new OutputId('c'), 50),
                    new Output(new OutputId('c'), 50),
                ],
            ));
    }

    public function test_spend_output_id_cannot_conflict_with_existing_unspent(): void
    {
        $this->expectException(DuplicateOutputIdException::class);
        $this->expectExceptionMessage("Duplicate output id: 'b'");

        Ledger::empty()
            ->addGenesis(
                new Output(new OutputId('a'), 100),
                new Output(new OutputId('b'), 50),
            )
            ->apply(new Spend(
                id: new SpendId('tx1'),
                inputs: [new OutputId('a')],
                outputs: [new Output(new OutputId('b'), 100)],
            ));
    }

    public function test_multiple_spends_in_sequence(): void
    {
        $ledger = Ledger::empty()
            ->addGenesis(new Output(new OutputId('genesis'), 1000))
            ->apply(new Spend(
                id: new SpendId('tx1'),
                inputs: [new OutputId('genesis')],
                outputs: [
                    new Output(new OutputId('a'), 600),
                    new Output(new OutputId('b'), 400),
                ],
            ))
            ->apply(new Spend(
                id: new SpendId('tx2'),
                inputs: [new OutputId('a')],
                outputs: [
                    new Output(new OutputId('c'), 300),
                    new Output(new OutputId('d'), 300),
                ],
            ));

        self::assertSame(1000, $ledger->totalUnspentAmount());
        self::assertSame(3, $ledger->unspent()->count());
        self::assertTrue($ledger->unspent()->contains(new OutputId('b')));
        self::assertTrue($ledger->unspent()->contains(new OutputId('c')));
        self::assertTrue($ledger->unspent()->contains(new OutputId('d')));
    }

    public function test_spend_with_multiple_inputs(): void
    {
        $ledger = Ledger::empty()
            ->addGenesis(
                new Output(new OutputId('a'), 100),
                new Output(new OutputId('b'), 50),
            )
            ->apply(new Spend(
                id: new SpendId('tx1'),
                inputs: [new OutputId('a'), new OutputId('b')],
                outputs: [new Output(new OutputId('c'), 150)],
            ));

        self::assertSame(150, $ledger->totalUnspentAmount());
        self::assertSame(1, $ledger->unspent()->count());
        self::assertTrue($ledger->unspent()->contains(new OutputId('c')));
    }
}
