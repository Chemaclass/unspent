<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit\Persistence;

use Chemaclass\Unspent\Lock\LockFactory;
use Chemaclass\Unspent\Lock\LockTypeAttribute;
use Chemaclass\Unspent\Lock\NoLock;
use Chemaclass\Unspent\Lock\Owner;
use Chemaclass\Unspent\Lock\PublicKey;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputLock;
use Chemaclass\Unspent\Persistence\LockData;
use Chemaclass\Unspent\Tx;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LockDataTest extends TestCase
{
    protected function tearDown(): void
    {
        LockFactory::reset();
    }

    public function test_from_output_with_no_lock(): void
    {
        $output = Output::open(100);

        $lockData = LockData::fromOutput($output);

        self::assertSame('none', $lockData->type);
        self::assertNull($lockData->owner);
        self::assertNull($lockData->pubkey);
        self::assertNull($lockData->custom);
    }

    public function test_from_output_with_owner_lock(): void
    {
        $output = Output::ownedBy('alice', 100);

        $lockData = LockData::fromOutput($output);

        self::assertSame('owner', $lockData->type);
        self::assertSame('alice', $lockData->owner);
        self::assertNull($lockData->pubkey);
        self::assertNull($lockData->custom);
    }

    public function test_from_output_with_pubkey_lock(): void
    {
        $keyPair = sodium_crypto_sign_keypair();
        $publicKey = base64_encode(sodium_crypto_sign_publickey($keyPair));
        $output = new Output(
            new \Chemaclass\Unspent\OutputId('test'),
            100,
            new PublicKey($publicKey),
        );

        $lockData = LockData::fromOutput($output);

        self::assertSame('pubkey', $lockData->type);
        self::assertNull($lockData->owner);
        self::assertSame($publicKey, $lockData->pubkey);
        self::assertNull($lockData->custom);
    }

    public function test_from_lock_with_custom_lock(): void
    {
        $customLock = new TestCustomLock('custom-value');

        $lockData = LockData::fromLock($customLock);

        self::assertSame('test-custom', $lockData->type);
        self::assertNull($lockData->owner);
        self::assertNull($lockData->pubkey);
        self::assertNotNull($lockData->custom);
        self::assertStringContainsString('custom-value', $lockData->custom);
    }

    public function test_is_owner_lock_returns_true_for_owner(): void
    {
        $lockData = LockData::fromLock(new Owner('alice'));

        self::assertTrue($lockData->isOwnerLock());
        self::assertFalse($lockData->isPublicKeyLock());
        self::assertFalse($lockData->isNoLock());
        self::assertFalse($lockData->isCustomLock());
    }

    public function test_is_public_key_lock_returns_true_for_pubkey(): void
    {
        $keyPair = sodium_crypto_sign_keypair();
        $publicKey = base64_encode(sodium_crypto_sign_publickey($keyPair));
        $lockData = LockData::fromLock(new PublicKey($publicKey));

        self::assertFalse($lockData->isOwnerLock());
        self::assertTrue($lockData->isPublicKeyLock());
        self::assertFalse($lockData->isNoLock());
        self::assertFalse($lockData->isCustomLock());
    }

    public function test_is_no_lock_returns_true_for_none(): void
    {
        $lockData = LockData::fromLock(new NoLock());

        self::assertFalse($lockData->isOwnerLock());
        self::assertFalse($lockData->isPublicKeyLock());
        self::assertTrue($lockData->isNoLock());
        self::assertFalse($lockData->isCustomLock());
    }

    public function test_is_custom_lock_returns_true_for_custom(): void
    {
        $lockData = LockData::fromLock(new TestCustomLock('value'));

        self::assertFalse($lockData->isOwnerLock());
        self::assertFalse($lockData->isPublicKeyLock());
        self::assertFalse($lockData->isNoLock());
        self::assertTrue($lockData->isCustomLock());
    }

    public function test_to_array_from_row_with_no_lock(): void
    {
        $row = [
            'lock_type' => 'none',
            'lock_owner' => null,
            'lock_pubkey' => null,
            'lock_custom_data' => null,
        ];

        $array = LockData::toArrayFromRow($row);

        self::assertSame(['type' => 'none'], $array);
    }

    public function test_to_array_from_row_with_owner_lock(): void
    {
        $row = [
            'lock_type' => 'owner',
            'lock_owner' => 'alice',
            'lock_pubkey' => null,
            'lock_custom_data' => null,
        ];

        $array = LockData::toArrayFromRow($row);

        self::assertSame(['type' => 'owner', 'name' => 'alice'], $array);
    }

    public function test_to_array_from_row_with_pubkey_lock(): void
    {
        $row = [
            'lock_type' => 'pubkey',
            'lock_owner' => null,
            'lock_pubkey' => 'test-key',
            'lock_custom_data' => null,
        ];

        $array = LockData::toArrayFromRow($row);

        self::assertSame(['type' => 'pubkey', 'key' => 'test-key'], $array);
    }

    public function test_to_array_from_row_with_custom_lock(): void
    {
        $customData = json_encode(['type' => 'timelock', 'until' => 12345]);
        $row = [
            'lock_type' => 'timelock',
            'lock_owner' => null,
            'lock_pubkey' => null,
            'lock_custom_data' => $customData,
        ];

        $array = LockData::toArrayFromRow($row);

        self::assertSame(['type' => 'timelock', 'until' => 12345], $array);
    }

    public function test_to_array_from_row_throws_for_unknown_type(): void
    {
        $row = [
            'lock_type' => 'unknown',
            'lock_owner' => null,
            'lock_pubkey' => null,
            'lock_custom_data' => null,
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown lock type: unknown');

        LockData::toArrayFromRow($row);
    }
}

#[LockTypeAttribute('test-custom')]
final readonly class TestCustomLock implements OutputLock
{
    public function __construct(
        public string $value,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self((string) ($data['value'] ?? ''));
    }

    public function validate(Tx $tx, int $spendIndex): void
    {
        // No validation for test lock
    }

    public function type(): string
    {
        return 'test-custom';
    }

    public function toArray(): array
    {
        return ['type' => 'test-custom', 'value' => $this->value];
    }
}
