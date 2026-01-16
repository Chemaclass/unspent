<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit\Validation;

use Chemaclass\Unspent\Exception\DuplicateOutputIdException;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\Validation\DuplicateValidator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DuplicateValidatorTest extends TestCase
{
    public function test_assert_no_duplicate_spend_ids_with_empty_array(): void
    {
        $this->expectNotToPerformAssertions();

        DuplicateValidator::assertNoDuplicateSpendIds([]);
    }

    public function test_assert_no_duplicate_spend_ids_with_single_item(): void
    {
        $this->expectNotToPerformAssertions();

        DuplicateValidator::assertNoDuplicateSpendIds([new OutputId('spend1')]);
    }

    public function test_assert_no_duplicate_spend_ids_with_unique_items(): void
    {
        $this->expectNotToPerformAssertions();

        DuplicateValidator::assertNoDuplicateSpendIds([
            new OutputId('spend1'),
            new OutputId('spend2'),
            new OutputId('spend3'),
        ]);
    }

    public function test_assert_no_duplicate_spend_ids_throws_on_duplicate_at_start(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Duplicate spend id: 'spend1'");

        DuplicateValidator::assertNoDuplicateSpendIds([
            new OutputId('spend1'),
            new OutputId('spend1'),
            new OutputId('spend2'),
        ]);
    }

    public function test_assert_no_duplicate_spend_ids_throws_on_duplicate_in_middle(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Duplicate spend id: 'spend2'");

        DuplicateValidator::assertNoDuplicateSpendIds([
            new OutputId('spend1'),
            new OutputId('spend2'),
            new OutputId('spend2'),
            new OutputId('spend3'),
        ]);
    }

    public function test_assert_no_duplicate_spend_ids_throws_on_duplicate_at_end(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Duplicate spend id: 'spend3'");

        DuplicateValidator::assertNoDuplicateSpendIds([
            new OutputId('spend1'),
            new OutputId('spend2'),
            new OutputId('spend3'),
            new OutputId('spend3'),
        ]);
    }

    public function test_assert_no_duplicate_spend_ids_throws_on_non_adjacent_duplicates(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Duplicate spend id: 'spend1'");

        DuplicateValidator::assertNoDuplicateSpendIds([
            new OutputId('spend1'),
            new OutputId('spend2'),
            new OutputId('spend3'),
            new OutputId('spend1'),
        ]);
    }

    public function test_assert_no_duplicate_output_ids_with_empty_array(): void
    {
        $this->expectNotToPerformAssertions();

        DuplicateValidator::assertNoDuplicateOutputIds([]);
    }

    public function test_assert_no_duplicate_output_ids_with_single_item(): void
    {
        $this->expectNotToPerformAssertions();

        DuplicateValidator::assertNoDuplicateOutputIds([Output::open(100, 'output1')]);
    }

    public function test_assert_no_duplicate_output_ids_with_unique_items(): void
    {
        $this->expectNotToPerformAssertions();

        DuplicateValidator::assertNoDuplicateOutputIds([
            Output::open(100, 'output1'),
            Output::open(50, 'output2'),
            Output::open(25, 'output3'),
        ]);
    }

    public function test_assert_no_duplicate_output_ids_throws_on_duplicate_at_start(): void
    {
        $this->expectException(DuplicateOutputIdException::class);
        $this->expectExceptionMessage("Duplicate output id: 'output1'");

        DuplicateValidator::assertNoDuplicateOutputIds([
            Output::open(100, 'output1'),
            Output::open(50, 'output1'),
            Output::open(25, 'output2'),
        ]);
    }

    public function test_assert_no_duplicate_output_ids_throws_on_duplicate_in_middle(): void
    {
        $this->expectException(DuplicateOutputIdException::class);
        $this->expectExceptionMessage("Duplicate output id: 'output2'");

        DuplicateValidator::assertNoDuplicateOutputIds([
            Output::open(100, 'output1'),
            Output::open(50, 'output2'),
            Output::open(25, 'output2'),
            Output::open(10, 'output3'),
        ]);
    }

    public function test_assert_no_duplicate_output_ids_throws_on_duplicate_at_end(): void
    {
        $this->expectException(DuplicateOutputIdException::class);
        $this->expectExceptionMessage("Duplicate output id: 'output3'");

        DuplicateValidator::assertNoDuplicateOutputIds([
            Output::open(100, 'output1'),
            Output::open(50, 'output2'),
            Output::open(25, 'output3'),
            Output::open(10, 'output3'),
        ]);
    }

    public function test_assert_no_duplicate_output_ids_throws_on_non_adjacent_duplicates(): void
    {
        $this->expectException(DuplicateOutputIdException::class);
        $this->expectExceptionMessage("Duplicate output id: 'output1'");

        DuplicateValidator::assertNoDuplicateOutputIds([
            Output::open(100, 'output1'),
            Output::open(50, 'output2'),
            Output::open(25, 'output3'),
            Output::open(10, 'output1'),
        ]);
    }

    public function test_spend_id_exception_is_invalid_argument_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Duplicate spend id: 'spend1'");

        DuplicateValidator::assertNoDuplicateSpendIds([
            new OutputId('spend1'),
            new OutputId('spend1'),
        ]);
    }

    public function test_output_id_exception_is_duplicate_output_id_exception(): void
    {
        try {
            DuplicateValidator::assertNoDuplicateOutputIds([
                Output::open(100, 'output1'),
                Output::open(50, 'output1'),
            ]);
            self::fail('Expected DuplicateOutputIdException to be thrown');
        } catch (DuplicateOutputIdException $e) {
            self::assertInstanceOf(DuplicateOutputIdException::class, $e);
            self::assertSame(DuplicateOutputIdException::CODE, $e->getCode());
        }
    }
}
