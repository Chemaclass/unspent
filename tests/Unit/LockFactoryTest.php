<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit;

use Chemaclass\Unspent\Lock\LockFactory;
use Chemaclass\Unspent\Lock\NoLock;
use Chemaclass\Unspent\Lock\Owner;
use Chemaclass\Unspent\Lock\PublicKey;
use Chemaclass\Unspent\OutputLock;
use Chemaclass\Unspent\Tx;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use stdClass;

final class LockFactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        LockFactory::reset();
    }

    // ========================================================================
    // Built-in Lock Tests
    // ========================================================================

    public function test_creates_no_lock_from_array(): void
    {
        $lock = LockFactory::fromArray(['type' => 'none']);

        self::assertInstanceOf(NoLock::class, $lock);
    }

    public function test_creates_owner_lock_from_array(): void
    {
        $lock = LockFactory::fromArray(['type' => 'owner', 'name' => 'alice']);

        self::assertInstanceOf(Owner::class, $lock);
        self::assertSame('alice', $lock->name);
    }

    public function test_creates_pubkey_lock_from_array(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $publicKey = base64_encode(sodium_crypto_sign_publickey($keypair));

        $lock = LockFactory::fromArray(['type' => 'pubkey', 'key' => $publicKey]);

        self::assertInstanceOf(PublicKey::class, $lock);
        self::assertSame($publicKey, $lock->key);
    }

    public function test_throws_when_type_missing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Lock type is required');

        LockFactory::fromArray([]);
    }

    public function test_throws_for_unknown_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown lock type: unknown');

        LockFactory::fromArray(['type' => 'unknown']);
    }

    public function test_throws_when_owner_name_missing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Name is required for owner lock');

        LockFactory::fromArray(['type' => 'owner']);
    }

    public function test_throws_when_pubkey_key_missing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Key is required for pubkey lock');

        LockFactory::fromArray(['type' => 'pubkey']);
    }

    // ========================================================================
    // Custom Handler Tests
    // ========================================================================

    public function test_register_custom_handler(): void
    {
        LockFactory::register('custom', static fn (array $data): OutputLock => new NoLock());

        $lock = LockFactory::fromArray(['type' => 'custom']);

        self::assertInstanceOf(NoLock::class, $lock);
    }

    public function test_custom_handler_receives_full_data(): void
    {
        $receivedData = null;

        LockFactory::register('capture', static function (array $data) use (&$receivedData): OutputLock {
            $receivedData = $data;

            return new NoLock();
        });

        LockFactory::fromArray([
            'type' => 'capture',
            'foo' => 'bar',
            'nested' => ['a' => 1],
        ]);

        self::assertIsArray($receivedData);
        self::assertSame('capture', $receivedData['type']);
        self::assertSame('bar', $receivedData['foo']);
        self::assertSame(['a' => 1], $receivedData['nested']);
    }

    public function test_custom_handler_takes_precedence_over_builtin(): void
    {
        $customLockClass = \get_class(new class() implements OutputLock {
            public function validate(Tx $tx, int $inputIndex): void
            {
            }

            public function toArray(): array
            {
                return ['type' => 'none', 'custom' => true];
            }
        });

        LockFactory::register('none', static fn (array $data): OutputLock => new $customLockClass());

        $lock = LockFactory::fromArray(['type' => 'none']);

        // Custom handler returns lock with 'custom' => true in toArray
        self::assertSame(['type' => 'none', 'custom' => true], $lock->toArray());
    }

    public function test_throws_when_handler_returns_non_output_lock(): void
    {
        LockFactory::register('bad', static fn (array $data): stdClass => new stdClass());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Handler for lock type 'bad' must return OutputLock, got stdClass");

        LockFactory::fromArray(['type' => 'bad']);
    }

    // ========================================================================
    // Helper Method Tests
    // ========================================================================

    public function test_has_handler_returns_true_for_registered(): void
    {
        LockFactory::register('custom', static fn (array $data): OutputLock => new NoLock());

        self::assertTrue(LockFactory::hasHandler('custom'));
    }

    public function test_has_handler_returns_false_for_unregistered(): void
    {
        self::assertFalse(LockFactory::hasHandler('nonexistent'));
    }

    public function test_has_handler_returns_false_for_builtin_types(): void
    {
        self::assertFalse(LockFactory::hasHandler('none'));
        self::assertFalse(LockFactory::hasHandler('owner'));
        self::assertFalse(LockFactory::hasHandler('pubkey'));
    }

    public function test_registered_types_returns_custom_types(): void
    {
        LockFactory::register('timelock', static fn (array $data): OutputLock => new NoLock());
        LockFactory::register('multisig', static fn (array $data): OutputLock => new NoLock());

        $types = LockFactory::registeredTypes();

        self::assertContains('timelock', $types);
        self::assertContains('multisig', $types);
        self::assertCount(2, $types);
    }

    public function test_registered_types_empty_by_default(): void
    {
        self::assertSame([], LockFactory::registeredTypes());
    }

    public function test_reset_clears_all_handlers(): void
    {
        LockFactory::register('custom1', static fn (array $data): OutputLock => new NoLock());
        LockFactory::register('custom2', static fn (array $data): OutputLock => new NoLock());

        LockFactory::reset();

        self::assertSame([], LockFactory::registeredTypes());
        self::assertFalse(LockFactory::hasHandler('custom1'));
        self::assertFalse(LockFactory::hasHandler('custom2'));
    }
}
