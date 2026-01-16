<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit;

use Chemaclass\Unspent\OutputStatus;
use PHPUnit\Framework\TestCase;

final class OutputStatusTest extends TestCase
{
    public function test_unspent_value(): void
    {
        self::assertSame('unspent', OutputStatus::UNSPENT->value);
    }

    public function test_spent_value(): void
    {
        self::assertSame('spent', OutputStatus::SPENT->value);
    }

    public function test_from_spent_by_returns_spent_when_not_null(): void
    {
        $status = OutputStatus::fromSpentBy('tx-1');

        self::assertSame(OutputStatus::SPENT, $status);
    }

    public function test_from_spent_by_returns_unspent_when_null(): void
    {
        $status = OutputStatus::fromSpentBy(null);

        self::assertSame(OutputStatus::UNSPENT, $status);
    }

    public function test_is_spent_returns_true_for_spent(): void
    {
        self::assertTrue(OutputStatus::SPENT->isSpent());
    }

    public function test_is_spent_returns_false_for_unspent(): void
    {
        self::assertFalse(OutputStatus::UNSPENT->isSpent());
    }

    public function test_is_unspent_returns_true_for_unspent(): void
    {
        self::assertTrue(OutputStatus::UNSPENT->isUnspent());
    }

    public function test_is_unspent_returns_false_for_spent(): void
    {
        self::assertFalse(OutputStatus::SPENT->isUnspent());
    }
}
