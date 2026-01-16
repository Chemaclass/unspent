<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit\Lock;

use Chemaclass\Unspent\Lock\LockFactory;
use Chemaclass\Unspent\Lock\LockTypeAttribute;
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

    public function test_register_custom_handler(): void
    {
        $handler = static fn (array $data): OutputLock => new NoLock();

        LockFactory::register('custom', $handler);

        self::assertTrue(LockFactory::hasHandler('custom'));
    }

    public function test_has_handler_returns_false_for_unregistered(): void
    {
        self::assertFalse(LockFactory::hasHandler('nonexistent'));
    }

    public function test_registered_types_returns_all_registered(): void
    {
        LockFactory::register('type1', static fn (): OutputLock => new NoLock());
        LockFactory::register('type2', static fn (): OutputLock => new NoLock());

        $types = LockFactory::registeredTypes();

        self::assertContains('type1', $types);
        self::assertContains('type2', $types);
        self::assertCount(2, $types);
    }

    public function test_reset_clears_all_handlers(): void
    {
        LockFactory::register('custom', static fn (): OutputLock => new NoLock());
        self::assertTrue(LockFactory::hasHandler('custom'));

        LockFactory::reset();

        self::assertFalse(LockFactory::hasHandler('custom'));
        self::assertSame([], LockFactory::registeredTypes());
    }

    public function test_from_array_creates_no_lock(): void
    {
        $lock = LockFactory::fromArray(['type' => 'none']);

        self::assertInstanceOf(NoLock::class, $lock);
    }

    public function test_from_array_creates_owner_lock(): void
    {
        $lock = LockFactory::fromArray(['type' => 'owner', 'name' => 'alice']);

        self::assertInstanceOf(Owner::class, $lock);
        self::assertSame('alice', $lock->name);
    }

    public function test_from_array_creates_pubkey_lock(): void
    {
        $keyPair = sodium_crypto_sign_keypair();
        $publicKey = base64_encode(sodium_crypto_sign_publickey($keyPair));

        $lock = LockFactory::fromArray(['type' => 'pubkey', 'key' => $publicKey]);

        self::assertInstanceOf(PublicKey::class, $lock);
        self::assertSame($publicKey, $lock->key);
    }

    public function test_from_array_throws_when_type_missing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Lock type is required');

        LockFactory::fromArray([]);
    }

    public function test_from_array_throws_for_unknown_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown lock type: unknown');

        LockFactory::fromArray(['type' => 'unknown']);
    }

    public function test_from_array_throws_when_owner_name_missing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Name is required for owner lock');

        LockFactory::fromArray(['type' => 'owner']);
    }

    public function test_from_array_throws_when_pubkey_key_missing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Key is required for pubkey lock');

        LockFactory::fromArray(['type' => 'pubkey']);
    }

    public function test_custom_handler_takes_precedence_over_builtin(): void
    {
        $customLock = new Owner('custom-handler');
        LockFactory::register('none', static fn (): OutputLock => $customLock);

        $lock = LockFactory::fromArray(['type' => 'none']);

        self::assertSame($customLock, $lock);
    }

    public function test_from_array_throws_when_handler_returns_non_output_lock(): void
    {
        LockFactory::register('broken', static fn (): object => new stdClass());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Handler for lock type 'broken' must return OutputLock");

        LockFactory::fromArray(['type' => 'broken']);
    }

    public function test_register_from_class_with_valid_class(): void
    {
        LockFactory::registerFromClass(ValidCustomLock::class);

        self::assertTrue(LockFactory::hasHandler('valid-custom'));

        $lock = LockFactory::fromArray(['type' => 'valid-custom', 'value' => 42]);

        self::assertInstanceOf(ValidCustomLock::class, $lock);
        self::assertSame(42, $lock->value);
    }

    public function test_register_from_class_throws_when_missing_attribute(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must have #[LockTypeAttribute] attribute');

        LockFactory::registerFromClass(LockWithoutAttribute::class);
    }

    public function test_register_from_class_throws_when_not_implementing_output_lock(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must implement OutputLock');

        /** @phpstan-ignore-next-line argument.type */
        LockFactory::registerFromClass(LockNotImplementingInterface::class);
    }

    public function test_register_from_class_throws_when_missing_from_array(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must have a public static fromArray');

        LockFactory::registerFromClass(LockWithoutFromArray::class);
    }

    public function test_register_from_classes_registers_multiple(): void
    {
        LockFactory::registerFromClasses(ValidCustomLock::class);

        self::assertTrue(LockFactory::hasHandler('valid-custom'));
    }
}

#[LockTypeAttribute('valid-custom')]
final readonly class ValidCustomLock implements OutputLock
{
    public function __construct(
        public int $value,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self((int) ($data['value'] ?? 0));
    }

    public function validate(Tx $tx, int $spendIndex): void
    {
        // No validation needed for test lock
    }

    public function type(): string
    {
        return 'valid-custom';
    }

    public function toArray(): array
    {
        return ['type' => 'valid-custom', 'value' => $this->value];
    }
}

final readonly class LockWithoutAttribute implements OutputLock
{
    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self();
    }

    public function validate(Tx $tx, int $spendIndex): void
    {
    }

    public function type(): string
    {
        return 'no-attribute';
    }

    public function toArray(): array
    {
        return ['type' => 'no-attribute'];
    }
}

#[LockTypeAttribute('not-implementing')]
final readonly class LockNotImplementingInterface
{
    public static function fromArray(): self
    {
        return new self();
    }
}

#[LockTypeAttribute('no-from-array')]
final readonly class LockWithoutFromArray implements OutputLock
{
    public function validate(Tx $tx, int $spendIndex): void
    {
    }

    public function type(): string
    {
        return 'no-from-array';
    }

    public function toArray(): array
    {
        return ['type' => 'no-from-array'];
    }
}
