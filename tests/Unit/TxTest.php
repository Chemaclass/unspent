<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit;

use Chemaclass\Unspent\Exception\DuplicateOutputIdException;
use Chemaclass\Unspent\Lock\NoLock;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\Tx;
use Chemaclass\Unspent\TxId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TxTest extends TestCase
{
    public function test_can_be_created_with_inputs_and_outputs(): void
    {
        $tx = new Tx(
            id: new TxId('tx1'),
            inputs: [new OutputId('a'), new OutputId('b')],
            outputs: [
                new Output(new OutputId('c'), 100, new NoLock()),
                new Output(new OutputId('d'), 50, new NoLock()),
            ],
        );

        self::assertSame('tx1', $tx->id->value);
        self::assertCount(2, $tx->inputs);
        self::assertCount(2, $tx->outputs);
    }

    public function test_must_have_at_least_one_input(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tx must have at least one input');

        new Tx(
            id: new TxId('tx1'),
            inputs: [],
            outputs: [new Output(new OutputId('c'), 100, new NoLock())],
        );
    }

    public function test_must_have_at_least_one_output(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tx must have at least one output');

        new Tx(
            id: new TxId('tx1'),
            inputs: [new OutputId('a')],
            outputs: [],
        );
    }

    public function test_total_output_amount(): void
    {
        $tx = new Tx(
            id: new TxId('tx1'),
            inputs: [new OutputId('a')],
            outputs: [
                new Output(new OutputId('c'), 100, new NoLock()),
                new Output(new OutputId('d'), 50, new NoLock()),
            ],
        );

        self::assertSame(150, $tx->totalOutputAmount());
    }

    public function test_outputs_must_have_unique_ids(): void
    {
        $this->expectException(DuplicateOutputIdException::class);
        $this->expectExceptionMessage("Duplicate output id: 'c'");

        new Tx(
            id: new TxId('tx1'),
            inputs: [new OutputId('a')],
            outputs: [
                new Output(new OutputId('c'), 50, new NoLock()),
                new Output(new OutputId('c'), 50, new NoLock()),
            ],
        );
    }

    public function test_inputs_must_have_unique_ids(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Duplicate input id: 'a'");

        new Tx(
            id: new TxId('tx1'),
            inputs: [new OutputId('a'), new OutputId('a')],
            outputs: [new Output(new OutputId('c'), 100, new NoLock())],
        );
    }

    public function test_create_factory_method(): void
    {
        $tx = Tx::create(
            inputIds: ['a', 'b'],
            outputs: [
                Output::open(100, 'c'),
                Output::open(50, 'd'),
            ],
            id: 'tx1',
        );

        self::assertSame('tx1', $tx->id->value);
        self::assertCount(2, $tx->inputs);
        self::assertSame('a', $tx->inputs[0]->value);
        self::assertSame('b', $tx->inputs[1]->value);
        self::assertCount(2, $tx->outputs);
        self::assertSame(150, $tx->totalOutputAmount());
    }

    public function test_create_with_signed_by(): void
    {
        $tx = Tx::create(
            inputIds: ['alice-funds'],
            outputs: [Output::ownedBy('bob', 100)],
            signedBy: 'alice',
            id: 'tx1',
        );

        self::assertSame('alice', $tx->signedBy);
    }

    public function test_create_with_proofs(): void
    {
        $tx = Tx::create(
            inputIds: ['secure-funds'],
            outputs: [Output::open(100)],
            proofs: ['signature-1', 'signature-2'],
            id: 'tx1',
        );

        self::assertCount(2, $tx->proofs);
        self::assertSame('signature-1', $tx->proofs[0]);
        self::assertSame('signature-2', $tx->proofs[1]);
    }

    public function test_create_with_auto_generated_id(): void
    {
        $tx = Tx::create(
            inputIds: ['a', 'b'],
            outputs: [
                Output::open(100, 'c'),
                Output::open(50, 'd'),
            ],
        );

        self::assertSame(32, \strlen($tx->id->value));
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $tx->id->value);
    }

    public function test_same_content_generates_same_id(): void
    {
        $tx1 = Tx::create(
            inputIds: ['a', 'b'],
            outputs: [
                Output::open(100, 'c'),
                Output::open(50, 'd'),
            ],
        );

        $tx2 = Tx::create(
            inputIds: ['a', 'b'],
            outputs: [
                Output::open(100, 'c'),
                Output::open(50, 'd'),
            ],
        );

        self::assertSame($tx1->id->value, $tx2->id->value);
    }

    public function test_different_content_generates_different_id(): void
    {
        $tx1 = Tx::create(
            inputIds: ['a'],
            outputs: [Output::open(100, 'c')],
        );

        $tx2 = Tx::create(
            inputIds: ['b'],
            outputs: [Output::open(100, 'c')],
        );

        self::assertNotSame($tx1->id->value, $tx2->id->value);
    }

    public function test_signed_by_does_not_affect_id_generation(): void
    {
        $tx1 = Tx::create(
            inputIds: ['a'],
            outputs: [Output::open(100, 'c')],
            signedBy: 'alice',
        );

        $tx2 = Tx::create(
            inputIds: ['a'],
            outputs: [Output::open(100, 'c')],
            signedBy: 'bob',
        );

        // IDs should be the same since signedBy is authorization context, not content
        self::assertSame($tx1->id->value, $tx2->id->value);
    }
}
