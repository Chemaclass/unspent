<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit;

use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\TxBuilder;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TxBuilderTest extends TestCase
{
    #[Test]
    public function it_builds_a_simple_transaction(): void
    {
        $tx = TxBuilder::new()
            ->spend('input-1')
            ->output('bob', 100)
            ->signedBy('alice')
            ->build();

        self::assertCount(1, $tx->spends);
        self::assertSame('input-1', $tx->spends[0]->value);
        self::assertCount(1, $tx->outputs);
        self::assertSame(100, $tx->outputs[0]->amount);
        self::assertSame('alice', $tx->signedBy);
    }

    #[Test]
    public function it_builds_transaction_with_multiple_inputs(): void
    {
        $tx = TxBuilder::new()
            ->spend('input-1', 'input-2', 'input-3')
            ->output('bob', 250)
            ->signedBy('alice')
            ->build();

        self::assertCount(3, $tx->spends);
        self::assertSame('input-1', $tx->spends[0]->value);
        self::assertSame('input-2', $tx->spends[1]->value);
        self::assertSame('input-3', $tx->spends[2]->value);
    }

    #[Test]
    public function it_builds_transaction_with_multiple_outputs(): void
    {
        $tx = TxBuilder::new()
            ->spend('input-1')
            ->output('bob', 100)
            ->output('alice', 50)
            ->signedBy('alice')
            ->build();

        self::assertCount(2, $tx->outputs);
        self::assertSame(100, $tx->outputs[0]->amount);
        self::assertSame(50, $tx->outputs[1]->amount);
    }

    #[Test]
    public function it_builds_transaction_with_custom_id(): void
    {
        $tx = TxBuilder::new()
            ->spend('input-1')
            ->output('bob', 100)
            ->withId('my-custom-tx-id')
            ->build();

        self::assertSame('my-custom-tx-id', $tx->id->value);
    }

    #[Test]
    public function it_builds_transaction_with_proofs(): void
    {
        $tx = TxBuilder::new()
            ->spend('input-1')
            ->output('bob', 100)
            ->withProofs('signature-1')
            ->build();

        self::assertSame(['signature-1'], $tx->proofs);
    }

    #[Test]
    public function it_adds_open_outputs(): void
    {
        $tx = TxBuilder::new()
            ->spend('input-1')
            ->openOutput(100)
            ->build();

        self::assertSame('none', $tx->outputs[0]->lock->toArray()['type']);
    }

    #[Test]
    public function it_adds_signed_output_with_public_key(): void
    {
        $keyPair = sodium_crypto_sign_keypair();
        $publicKey = base64_encode(sodium_crypto_sign_publickey($keyPair));

        $tx = TxBuilder::new()
            ->spend('input-1')
            ->signedOutput($publicKey, 100)
            ->build();

        $lockArray = $tx->outputs[0]->lock->toArray();
        self::assertSame('pubkey', $lockArray['type']);
        self::assertArrayHasKey('key', $lockArray);
        /** @phpstan-ignore-next-line offsetAccess.notFound */
        self::assertSame($publicKey, $lockArray['key']);
    }

    #[Test]
    public function it_adds_custom_output(): void
    {
        $customOutput = Output::ownedBy('charlie', 75, 'custom-id');

        $tx = TxBuilder::new()
            ->spend('input-1')
            ->addOutput($customOutput)
            ->build();

        self::assertSame('custom-id', $tx->outputs[0]->id->value);
        self::assertSame(75, $tx->outputs[0]->amount);
    }

    #[Test]
    public function it_can_be_reset_and_reused(): void
    {
        $builder = TxBuilder::new()
            ->spend('input-1')
            ->output('bob', 100);

        $tx1 = $builder->build();

        $builder->reset()
            ->spend('input-2')
            ->output('charlie', 200);

        $tx2 = $builder->build();

        self::assertSame('input-1', $tx1->spends[0]->value);
        self::assertSame('input-2', $tx2->spends[0]->value);
    }

    #[Test]
    public function it_fails_without_spends(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one spend is required');

        TxBuilder::new()
            ->output('bob', 100)
            ->build();
    }

    #[Test]
    public function it_fails_without_outputs(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one output is required');

        TxBuilder::new()
            ->spend('input-1')
            ->build();
    }
}
