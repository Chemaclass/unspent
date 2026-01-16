<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit\Persistence;

use Chemaclass\Unspent\Persistence\TransactionInfo;
use PHPUnit\Framework\TestCase;

final class TransactionInfoTest extends TestCase
{
    public function test_constructor_sets_properties(): void
    {
        $info = new TransactionInfo('tx-1', 100);

        self::assertSame('tx-1', $info->id);
        self::assertSame(100, $info->fee);
    }

    public function test_from_row_creates_instance(): void
    {
        $row = ['id' => 'tx-1', 'fee' => 100];

        $info = TransactionInfo::fromRow($row);

        self::assertSame('tx-1', $info->id);
        self::assertSame(100, $info->fee);
    }

    public function test_from_row_casts_fee_to_int(): void
    {
        $row = ['id' => 'tx-1', 'fee' => '50'];

        $info = TransactionInfo::fromRow($row);

        self::assertSame(50, $info->fee);
    }

    public function test_to_array_returns_correct_format(): void
    {
        $info = new TransactionInfo('tx-1', 100);

        $expected = ['id' => 'tx-1', 'fee' => 100];

        self::assertSame($expected, $info->toArray());
    }

    public function test_round_trip_preserves_data(): void
    {
        $original = new TransactionInfo('tx-123', 500);

        $restored = TransactionInfo::fromRow($original->toArray());

        self::assertSame($original->id, $restored->id);
        self::assertSame($original->fee, $restored->fee);
    }

    public function test_allows_zero_fee(): void
    {
        $info = new TransactionInfo('tx-1', 0);

        self::assertSame(0, $info->fee);
    }

    public function test_allows_large_fee(): void
    {
        $info = new TransactionInfo('tx-1', PHP_INT_MAX);

        self::assertSame(PHP_INT_MAX, $info->fee);
    }

    public function test_from_row_with_extra_fields_ignores_them(): void
    {
        $row = [
            'id' => 'tx-1',
            'fee' => 100,
            'extra_field' => 'ignored',
            'another' => 42,
        ];

        $info = TransactionInfo::fromRow($row);

        self::assertSame('tx-1', $info->id);
        self::assertSame(100, $info->fee);
    }
}
