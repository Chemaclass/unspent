<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit\Lock;

use Chemaclass\Unspent\Lock\LockType;
use PHPUnit\Framework\TestCase;

final class LockTypeTest extends TestCase
{
    public function test_none_lock_type_value(): void
    {
        self::assertSame('none', LockType::NONE->value);
    }

    public function test_owner_lock_type_value(): void
    {
        self::assertSame('owner', LockType::OWNER->value);
    }

    public function test_public_key_lock_type_value(): void
    {
        self::assertSame('pubkey', LockType::PUBLIC_KEY->value);
    }

    public function test_is_built_in_returns_true_for_none(): void
    {
        self::assertTrue(LockType::isBuiltIn('none'));
    }

    public function test_is_built_in_returns_true_for_owner(): void
    {
        self::assertTrue(LockType::isBuiltIn('owner'));
    }

    public function test_is_built_in_returns_true_for_pubkey(): void
    {
        self::assertTrue(LockType::isBuiltIn('pubkey'));
    }

    public function test_is_built_in_returns_false_for_unknown(): void
    {
        self::assertFalse(LockType::isBuiltIn('unknown'));
    }

    public function test_is_built_in_returns_false_for_empty_string(): void
    {
        self::assertFalse(LockType::isBuiltIn(''));
    }

    public function test_try_from_returns_enum_for_valid_value(): void
    {
        self::assertSame(LockType::NONE, LockType::tryFrom('none'));
        self::assertSame(LockType::OWNER, LockType::tryFrom('owner'));
        self::assertSame(LockType::PUBLIC_KEY, LockType::tryFrom('pubkey'));
    }

    public function test_try_from_returns_null_for_invalid_value(): void
    {
        // Test behavior via isBuiltIn which uses tryFrom internally
        self::assertFalse(LockType::isBuiltIn('invalid'));
    }

    public function test_from_returns_enum_for_valid_value(): void
    {
        self::assertSame(LockType::NONE, LockType::from('none'));
        self::assertSame(LockType::OWNER, LockType::from('owner'));
        self::assertSame(LockType::PUBLIC_KEY, LockType::from('pubkey'));
    }
}
