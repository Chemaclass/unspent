<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit;

use Chemaclass\Unspent\Lock\NoLock;
use Chemaclass\Unspent\Lock\Owner;
use Chemaclass\Unspent\Lock\PublicKey;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\OutputLock;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class OutputTest extends TestCase
{
    public function test_can_be_created_with_id_amount_and_lock(): void
    {
        $id = new OutputId('out-1');
        $lock = new Owner('alice');
        $output = new Output($id, 100, $lock);

        self::assertSame($id, $output->id);
        self::assertSame(100, $output->amount);
        self::assertSame($lock, $output->lock);
    }

    public function test_amount_must_be_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Amount must be positive');

        new Output(new OutputId('out-1'), 0, new NoLock());
    }

    public function test_negative_amount_is_not_allowed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Amount must be positive');

        new Output(new OutputId('out-1'), -50, new NoLock());
    }

    // ownedBy factory method tests

    public function test_owned_by_creates_output_with_owner_lock(): void
    {
        $output = Output::ownedBy('alice', 1000, 'test-id');

        self::assertSame('test-id', $output->id->value);
        self::assertSame(1000, $output->amount);
        self::assertInstanceOf(Owner::class, $output->lock);
        self::assertSame('alice', $output->lock->name);
    }

    public function test_owned_by_with_auto_generated_id(): void
    {
        $output = Output::ownedBy('alice', 1000);

        self::assertSame(32, strlen($output->id->value));
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $output->id->value);
        self::assertInstanceOf(Owner::class, $output->lock);
    }

    public function test_owned_by_generates_unique_ids(): void
    {
        $output1 = Output::ownedBy('alice', 100);
        $output2 = Output::ownedBy('alice', 100);

        self::assertNotSame($output1->id->value, $output2->id->value);
    }

    // signedBy factory method tests

    public function test_signed_by_creates_output_with_public_key_lock(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $publicKey = base64_encode(sodium_crypto_sign_publickey($keypair));

        $output = Output::signedBy($publicKey, 1000, 'secure-id');

        self::assertSame('secure-id', $output->id->value);
        self::assertSame(1000, $output->amount);
        self::assertInstanceOf(PublicKey::class, $output->lock);
        self::assertSame($publicKey, $output->lock->key);
    }

    public function test_signed_by_with_auto_generated_id(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $publicKey = base64_encode(sodium_crypto_sign_publickey($keypair));

        $output = Output::signedBy($publicKey, 1000);

        self::assertSame(32, strlen($output->id->value));
        self::assertInstanceOf(PublicKey::class, $output->lock);
    }

    // open factory method tests

    public function test_open_creates_output_with_no_lock(): void
    {
        $output = Output::open(1000, 'open-id');

        self::assertSame('open-id', $output->id->value);
        self::assertSame(1000, $output->amount);
        self::assertInstanceOf(NoLock::class, $output->lock);
    }

    public function test_open_with_auto_generated_id(): void
    {
        $output = Output::open(1000);

        self::assertSame(32, strlen($output->id->value));
        self::assertInstanceOf(NoLock::class, $output->lock);
    }

    // lockedWith factory method tests

    public function test_locked_with_accepts_custom_lock(): void
    {
        $customLock = new class implements OutputLock {
            public function validate(\Chemaclass\Unspent\Spend $spend, int $inputIndex): void {}
            public function toArray(): array { return ['type' => 'custom']; }
        };

        $output = Output::lockedWith($customLock, 1000, 'custom-id');

        self::assertSame('custom-id', $output->id->value);
        self::assertSame(1000, $output->amount);
        self::assertSame($customLock, $output->lock);
    }

    public function test_locked_with_auto_generated_id(): void
    {
        $output = Output::lockedWith(new Owner('bob'), 500);

        self::assertSame(32, strlen($output->id->value));
        self::assertInstanceOf(Owner::class, $output->lock);
    }
}
