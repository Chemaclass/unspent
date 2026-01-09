<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests;

use Chemaclass\Unspent\Exception\DuplicateOutputIdException;
use Chemaclass\Unspent\Exception\DuplicateSpendException;
use Chemaclass\Unspent\Exception\GenesisNotAllowedException;
use Chemaclass\Unspent\Exception\OutputAlreadySpentException;
use Chemaclass\Unspent\Exception\InsufficientInputsException;
use Chemaclass\Unspent\Exception\UnspentException;
use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\Spend;
use Chemaclass\Unspent\SpendId;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ExceptionHierarchyTest extends TestCase
{
    public function test_all_domain_exceptions_extend_unspent_exception(): void
    {
        self::assertTrue(is_a(DuplicateOutputIdException::class, UnspentException::class, true));
        self::assertTrue(is_a(DuplicateSpendException::class, UnspentException::class, true));
        self::assertTrue(is_a(GenesisNotAllowedException::class, UnspentException::class, true));
        self::assertTrue(is_a(OutputAlreadySpentException::class, UnspentException::class, true));
        self::assertTrue(is_a(InsufficientInputsException::class, UnspentException::class, true));
    }

    public function test_unspent_exception_extends_runtime_exception(): void
    {
        self::assertTrue(is_a(UnspentException::class, RuntimeException::class, true));
    }

    public function test_can_catch_all_domain_exceptions_with_single_type(): void
    {
        $caughtExceptions = [];

        // Test DuplicateOutputIdException
        try {
            Ledger::empty()->addGenesis(
                new Output(new OutputId('a'), 100),
                new Output(new OutputId('a'), 50),
            );
        } catch (UnspentException $e) {
            $caughtExceptions[] = $e::class;
        }

        // Test GenesisNotAllowedException
        try {
            Ledger::empty()
                ->addGenesis(new Output(new OutputId('a'), 100))
                ->addGenesis(new Output(new OutputId('b'), 50));
        } catch (UnspentException $e) {
            $caughtExceptions[] = $e::class;
        }

        // Test OutputAlreadySpentException
        try {
            Ledger::empty()
                ->addGenesis(new Output(new OutputId('a'), 100))
                ->apply(new Spend(
                    id: new SpendId('tx1'),
                    inputs: [new OutputId('nonexistent')],
                    outputs: [new Output(new OutputId('b'), 100)],
                ));
        } catch (UnspentException $e) {
            $caughtExceptions[] = $e::class;
        }

        // Test InsufficientInputsException (outputs exceed inputs)
        try {
            Ledger::empty()
                ->addGenesis(new Output(new OutputId('a'), 100))
                ->apply(new Spend(
                    id: new SpendId('tx1'),
                    inputs: [new OutputId('a')],
                    outputs: [new Output(new OutputId('b'), 150)],
                ));
        } catch (UnspentException $e) {
            $caughtExceptions[] = $e::class;
        }

        self::assertCount(4, $caughtExceptions);
        self::assertContains(DuplicateOutputIdException::class, $caughtExceptions);
        self::assertContains(GenesisNotAllowedException::class, $caughtExceptions);
        self::assertContains(OutputAlreadySpentException::class, $caughtExceptions);
        self::assertContains(InsufficientInputsException::class, $caughtExceptions);
    }
}
