<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit;

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
                Output::open(100, 'a'),
                Output::open(50, 'a'),
            );
        } catch (UnspentException $e) {
            $caughtExceptions[] = $e::class;
        }

        // Test GenesisNotAllowedException
        try {
            Ledger::empty()
                ->addGenesis(Output::open(100, 'a'))
                ->addGenesis(Output::open(50, 'b'));
        } catch (UnspentException $e) {
            $caughtExceptions[] = $e::class;
        }

        // Test OutputAlreadySpentException
        try {
            Ledger::empty()
                ->addGenesis(Output::open(100, 'a'))
                ->apply(new Spend(
                    id: new SpendId('tx1'),
                    inputs: [new OutputId('nonexistent')],
                    outputs: [Output::open(100, 'b')],
                ));
        } catch (UnspentException $e) {
            $caughtExceptions[] = $e::class;
        }

        // Test InsufficientInputsException (outputs exceed inputs)
        try {
            Ledger::empty()
                ->addGenesis(Output::open(100, 'a'))
                ->apply(new Spend(
                    id: new SpendId('tx1'),
                    inputs: [new OutputId('a')],
                    outputs: [Output::open(150, 'b')],
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
