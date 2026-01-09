<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests;

use Chemaclass\Unspent\SpendId;
use PHPUnit\Framework\TestCase;

final class SpendIdTest extends TestCase
{
    public function test_can_be_created_from_string(): void
    {
        $id = new SpendId('spend-1');

        self::assertSame('spend-1', $id->value);
    }

    public function test_two_spend_ids_with_same_value_are_equal(): void
    {
        $id1 = new SpendId('tx1');
        $id2 = new SpendId('tx1');

        self::assertTrue($id1->equals($id2));
    }

    public function test_two_spend_ids_with_different_values_are_not_equal(): void
    {
        $id1 = new SpendId('tx1');
        $id2 = new SpendId('tx2');

        self::assertFalse($id1->equals($id2));
    }

    public function test_can_be_converted_to_string(): void
    {
        $id = new SpendId('spend-id');

        self::assertSame('spend-id', (string) $id);
    }
}
