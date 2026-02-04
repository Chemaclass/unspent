<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit\Lock;

use Chemaclass\Unspent\Exception\AuthorizationException;
use Chemaclass\Unspent\Lock\LockFactory;
use Chemaclass\Unspent\Lock\MultisigLock;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\OutputLock;
use Chemaclass\Unspent\Tx;
use Chemaclass\Unspent\TxId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MultisigLockTest extends TestCase
{
    protected function tearDown(): void
    {
        LockFactory::reset();
    }

    public function test_implements_output_lock_interface(): void
    {
        $lock = new MultisigLock(2, ['alice', 'bob', 'charlie']);

        self::assertInstanceOf(OutputLock::class, $lock);
    }

    public function test_stores_threshold_and_signers(): void
    {
        $lock = new MultisigLock(2, ['alice', 'bob', 'charlie']);

        self::assertSame(2, $lock->threshold);
        self::assertSame(['alice', 'bob', 'charlie'], $lock->signers);
    }

    public function test_throws_on_zero_threshold(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Threshold must be at least 1');

        new MultisigLock(0, ['alice', 'bob']);
    }

    public function test_throws_on_threshold_greater_than_signers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Threshold (3) cannot exceed number of signers (2)');

        new MultisigLock(3, ['alice', 'bob']);
    }

    public function test_throws_on_empty_signers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one signer is required');

        new MultisigLock(1, []);
    }

    public function test_throws_on_empty_signer_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Signer names cannot be empty');

        new MultisigLock(1, ['alice', '']);
    }

    public function test_throws_on_duplicate_signers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate signer: alice');

        new MultisigLock(2, ['alice', 'bob', 'alice']);
    }

    public function test_validate_passes_with_exact_threshold_signatures(): void
    {
        $lock = new MultisigLock(2, ['alice', 'bob', 'charlie']);
        $tx = new Tx(
            id: new TxId('tx1'),
            spends: [new OutputId('a')],
            outputs: [Output::open(100, 'b')],
            proofs: ['alice,bob'], // Comma-separated signers
        );

        $this->expectNotToPerformAssertions();

        $lock->validate($tx, 0);
    }

    public function test_validate_passes_with_more_than_threshold_signatures(): void
    {
        $lock = new MultisigLock(2, ['alice', 'bob', 'charlie']);
        $tx = new Tx(
            id: new TxId('tx1'),
            spends: [new OutputId('a')],
            outputs: [Output::open(100, 'b')],
            proofs: ['alice,bob,charlie'],
        );

        $this->expectNotToPerformAssertions();

        $lock->validate($tx, 0);
    }

    public function test_validate_throws_with_fewer_than_threshold_signatures(): void
    {
        $lock = new MultisigLock(2, ['alice', 'bob', 'charlie']);
        $tx = new Tx(
            id: new TxId('tx1'),
            spends: [new OutputId('a')],
            outputs: [Output::open(100, 'b')],
            proofs: ['alice'], // Only 1 signer
        );

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Multisig requires 2 signatures, got 1');

        $lock->validate($tx, 0);
    }

    public function test_validate_throws_with_invalid_signer(): void
    {
        $lock = new MultisigLock(2, ['alice', 'bob', 'charlie']);
        $tx = new Tx(
            id: new TxId('tx1'),
            spends: [new OutputId('a')],
            outputs: [Output::open(100, 'b')],
            proofs: ['alice,eve'], // Eve is not a valid signer
        );

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage("'eve' is not an authorized signer");

        $lock->validate($tx, 0);
    }

    public function test_validate_throws_with_missing_proof(): void
    {
        $lock = new MultisigLock(2, ['alice', 'bob', 'charlie']);
        $tx = new Tx(
            id: new TxId('tx1'),
            spends: [new OutputId('a')],
            outputs: [Output::open(100, 'b')],
        );

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Missing authorization proof for spend 0');

        $lock->validate($tx, 0);
    }

    public function test_validate_ignores_duplicate_signatures(): void
    {
        $lock = new MultisigLock(2, ['alice', 'bob', 'charlie']);
        $tx = new Tx(
            id: new TxId('tx1'),
            spends: [new OutputId('a')],
            outputs: [Output::open(100, 'b')],
            proofs: ['alice,alice'], // Duplicate should count as 1
        );

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Multisig requires 2 signatures, got 1');

        $lock->validate($tx, 0);
    }

    public function test_type_returns_multisig(): void
    {
        $lock = new MultisigLock(2, ['alice', 'bob', 'charlie']);

        self::assertSame('multisig', $lock->type());
    }

    public function test_to_array_returns_correct_format(): void
    {
        $lock = new MultisigLock(2, ['alice', 'bob', 'charlie']);

        $expected = [
            'type' => 'multisig',
            'threshold' => 2,
            'signers' => ['alice', 'bob', 'charlie'],
        ];

        self::assertSame($expected, $lock->toArray());
    }

    public function test_from_array_creates_lock(): void
    {
        LockFactory::registerFromClass(MultisigLock::class);

        $data = [
            'type' => 'multisig',
            'threshold' => 2,
            'signers' => ['alice', 'bob', 'charlie'],
        ];

        $lock = LockFactory::fromArray($data);

        self::assertInstanceOf(MultisigLock::class, $lock);
        self::assertSame(2, $lock->threshold);
        self::assertSame(['alice', 'bob', 'charlie'], $lock->signers);
    }

    public function test_1_of_1_multisig_works(): void
    {
        $lock = new MultisigLock(1, ['alice']);
        $tx = new Tx(
            id: new TxId('tx1'),
            spends: [new OutputId('a')],
            outputs: [Output::open(100, 'b')],
            proofs: ['alice'],
        );

        $this->expectNotToPerformAssertions();

        $lock->validate($tx, 0);
    }

    public function test_description_returns_m_of_n_format(): void
    {
        $lock = new MultisigLock(2, ['alice', 'bob', 'charlie']);

        self::assertSame('2-of-3 multisig', $lock->description());
    }

    public function test_throws_on_whitespace_only_signer_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Signer names cannot be empty');

        new MultisigLock(1, ['alice', '   ']);
    }

    public function test_validate_handles_whitespace_in_proof(): void
    {
        $lock = new MultisigLock(2, ['alice', 'bob', 'charlie']);
        $tx = new Tx(
            id: new TxId('tx1'),
            spends: [new OutputId('a')],
            outputs: [Output::open(100, 'b')],
            proofs: [' alice , bob '], // Whitespace around names
        );

        $this->expectNotToPerformAssertions();

        $lock->validate($tx, 0);
    }
}
