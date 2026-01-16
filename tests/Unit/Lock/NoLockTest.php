<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit\Lock;

use Chemaclass\Unspent\Lock\LockType;
use Chemaclass\Unspent\Lock\NoLock;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\OutputLock;
use Chemaclass\Unspent\Tx;
use Chemaclass\Unspent\TxId;
use PHPUnit\Framework\TestCase;

final class NoLockTest extends TestCase
{
    public function test_implements_output_lock_interface(): void
    {
        $lock = new NoLock();

        self::assertInstanceOf(OutputLock::class, $lock);
    }

    public function test_validate_does_not_throw(): void
    {
        $lock = new NoLock();
        $tx = new Tx(
            id: new TxId('tx1'),
            spends: [new OutputId('a')],
            outputs: [Output::open(100, 'b')],
        );

        $this->expectNotToPerformAssertions();

        $lock->validate($tx, 0);
    }

    public function test_validate_allows_unsigned_transaction(): void
    {
        $lock = new NoLock();
        $tx = new Tx(
            id: new TxId('tx1'),
            spends: [new OutputId('a')],
            outputs: [Output::open(100, 'b')],
        );

        $this->expectNotToPerformAssertions();

        $lock->validate($tx, 0);
    }

    public function test_validate_allows_any_signer(): void
    {
        $lock = new NoLock();
        $tx = new Tx(
            id: new TxId('tx1'),
            spends: [new OutputId('a')],
            outputs: [Output::open(100, 'b')],
            signedBy: 'anyone',
        );

        $this->expectNotToPerformAssertions();

        $lock->validate($tx, 0);
    }

    public function test_type_returns_none(): void
    {
        $lock = new NoLock();

        self::assertSame(LockType::NONE->value, $lock->type());
    }

    public function test_to_array_returns_correct_format(): void
    {
        $lock = new NoLock();

        $expected = ['type' => 'none'];

        self::assertSame($expected, $lock->toArray());
    }
}
