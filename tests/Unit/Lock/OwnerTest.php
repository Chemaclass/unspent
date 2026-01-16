<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit\Lock;

use Chemaclass\Unspent\Exception\AuthorizationException;
use Chemaclass\Unspent\Lock\LockType;
use Chemaclass\Unspent\Lock\Owner;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\OutputLock;
use Chemaclass\Unspent\Tx;
use Chemaclass\Unspent\TxId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class OwnerTest extends TestCase
{
    public function test_implements_output_lock_interface(): void
    {
        $lock = new Owner('alice');

        self::assertInstanceOf(OutputLock::class, $lock);
    }

    public function test_stores_owner_name(): void
    {
        $lock = new Owner('alice');

        self::assertSame('alice', $lock->name);
    }

    public function test_throws_on_empty_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Owner name cannot be empty or whitespace');

        new Owner('');
    }

    public function test_throws_on_whitespace_only_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Owner name cannot be empty or whitespace');

        new Owner('   ');
    }

    public function test_validate_passes_when_signed_by_owner(): void
    {
        $lock = new Owner('alice');
        $tx = new Tx(
            id: new TxId('tx1'),
            spends: [new OutputId('a')],
            outputs: [Output::open(100, 'b')],
            signedBy: 'alice',
        );

        $this->expectNotToPerformAssertions();

        $lock->validate($tx, 0);
    }

    public function test_validate_throws_when_signed_by_wrong_owner(): void
    {
        $lock = new Owner('alice');
        $tx = new Tx(
            id: new TxId('tx1'),
            spends: [new OutputId('a')],
            outputs: [Output::open(100, 'b')],
            signedBy: 'bob',
        );

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage("Output owned by 'alice', but spend signed by 'bob'");

        $lock->validate($tx, 0);
    }

    public function test_validate_throws_when_not_signed(): void
    {
        $lock = new Owner('alice');
        $tx = new Tx(
            id: new TxId('tx1'),
            spends: [new OutputId('a')],
            outputs: [Output::open(100, 'b')],
        );

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage("Output owned by 'alice', but spend signed by 'null'");

        $lock->validate($tx, 0);
    }

    public function test_validate_uses_timing_safe_comparison(): void
    {
        $lock = new Owner('alice');
        $tx = new Tx(
            id: new TxId('tx1'),
            spends: [new OutputId('a')],
            outputs: [Output::open(100, 'b')],
            signedBy: 'alice',
        );

        // This test just ensures the comparison works - timing safety is a property
        // of hash_equals which is used internally
        $this->expectNotToPerformAssertions();

        $lock->validate($tx, 0);
    }

    public function test_type_returns_owner(): void
    {
        $lock = new Owner('alice');

        self::assertSame(LockType::OWNER->value, $lock->type());
    }

    public function test_to_array_returns_correct_format(): void
    {
        $lock = new Owner('alice');

        $expected = ['type' => 'owner', 'name' => 'alice'];

        self::assertSame($expected, $lock->toArray());
    }

    public function test_allows_special_characters_in_name(): void
    {
        $lock = new Owner('user@example.com');

        self::assertSame('user@example.com', $lock->name);
    }

    public function test_allows_unicode_characters_in_name(): void
    {
        $lock = new Owner('utilisateur_français');

        self::assertSame('utilisateur_français', $lock->name);
    }
}
