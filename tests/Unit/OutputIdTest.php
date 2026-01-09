<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit;

use Chemaclass\Unspent\OutputId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class OutputIdTest extends TestCase
{
    public function test_can_be_created_from_string(): void
    {
        $id = new OutputId('abc123');

        self::assertSame('abc123', $id->value);
    }

    public function test_two_output_ids_with_same_value_are_equal(): void
    {
        $id1 = new OutputId('abc');
        $id2 = new OutputId('abc');

        self::assertTrue($id1->equals($id2));
    }

    public function test_two_output_ids_with_different_values_are_not_equal(): void
    {
        $id1 = new OutputId('abc');
        $id2 = new OutputId('xyz');

        self::assertFalse($id1->equals($id2));
    }

    public function test_can_be_converted_to_string(): void
    {
        $id = new OutputId('test-id');

        self::assertSame('test-id', (string) $id);
    }

    public function test_empty_string_is_not_allowed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('OutputId cannot be empty');

        new OutputId('');
    }

    public function test_whitespace_only_is_not_allowed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('OutputId cannot be empty');

        new OutputId('   ');
    }
}
