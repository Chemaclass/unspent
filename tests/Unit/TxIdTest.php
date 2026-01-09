<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit;

use Chemaclass\Unspent\TxId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TxIdTest extends TestCase
{
    public function test_can_be_created_from_string(): void
    {
        $id = new TxId('tx-1');

        self::assertSame('tx-1', $id->value);
    }

    public function test_two_tx_ids_with_same_value_are_equal(): void
    {
        $id1 = new TxId('tx1');
        $id2 = new TxId('tx1');

        self::assertTrue($id1->equals($id2));
    }

    public function test_two_tx_ids_with_different_values_are_not_equal(): void
    {
        $id1 = new TxId('tx1');
        $id2 = new TxId('tx2');

        self::assertFalse($id1->equals($id2));
    }

    public function test_can_be_converted_to_string(): void
    {
        $id = new TxId('tx-id');

        self::assertSame('tx-id', (string) $id);
    }

    public function test_empty_string_is_not_allowed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('TxId cannot be empty');

        new TxId('');
    }

    public function test_whitespace_only_is_not_allowed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('TxId cannot be empty');

        new TxId('   ');
    }

    public function test_rejects_id_exceeding_max_length(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('TxId cannot exceed 64 characters');

        new TxId(str_repeat('a', 65));
    }

    public function test_accepts_id_at_max_length(): void
    {
        $id = new TxId(str_repeat('a', 64));

        self::assertSame(64, \strlen($id->value));
    }

    public function test_rejects_invalid_characters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('TxId can only contain alphanumeric characters, dashes, and underscores');

        new TxId('tx@invalid!');
    }

    public function test_accepts_valid_characters(): void
    {
        $id = new TxId('valid-tx_ID-123');

        self::assertSame('valid-tx_ID-123', $id->value);
    }
}
