<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit\Lock;

use Chemaclass\Unspent\Exception\AuthorizationException;
use Chemaclass\Unspent\Lock\HashLock;
use Chemaclass\Unspent\Lock\LockFactory;
use Chemaclass\Unspent\Lock\Owner;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\OutputLock;
use Chemaclass\Unspent\Tx;
use Chemaclass\Unspent\TxId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class HashLockTest extends TestCase
{
    private const string SECRET = 'my-secret-preimage';

    protected function tearDown(): void
    {
        LockFactory::reset();
    }

    public function test_implements_output_lock_interface(): void
    {
        $lock = HashLock::sha256(self::SECRET, new Owner('alice'));

        self::assertInstanceOf(OutputLock::class, $lock);
    }

    public function test_create_from_secret_hashes_correctly(): void
    {
        $lock = HashLock::sha256(self::SECRET, new Owner('alice'));

        self::assertSame(hash('sha256', self::SECRET), $lock->hash);
        self::assertSame('sha256', $lock->algorithm);
    }

    public function test_create_from_hash_stores_hash_directly(): void
    {
        $hash = hash('sha256', self::SECRET);
        $lock = HashLock::fromHash($hash, 'sha256', new Owner('alice'));

        self::assertSame($hash, $lock->hash);
    }

    public function test_throws_on_empty_hash(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Hash cannot be empty');

        HashLock::fromHash('', 'sha256', new Owner('alice'));
    }

    public function test_throws_on_unsupported_algorithm(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported hash algorithm: md4');

        HashLock::fromHash('abc123', 'md4', new Owner('alice'));
    }

    public function test_validate_passes_with_correct_preimage(): void
    {
        $lock = HashLock::sha256(self::SECRET, new Owner('alice'));
        $tx = new Tx(
            id: new TxId('tx1'),
            spends: [new OutputId('a')],
            outputs: [Output::open(100, 'b')],
            signedBy: 'alice',
            proofs: [self::SECRET], // Preimage as proof
        );

        $this->expectNotToPerformAssertions();

        $lock->validate($tx, 0);
    }

    public function test_validate_throws_with_wrong_preimage(): void
    {
        $lock = HashLock::sha256(self::SECRET, new Owner('alice'));
        $tx = new Tx(
            id: new TxId('tx1'),
            spends: [new OutputId('a')],
            outputs: [Output::open(100, 'b')],
            signedBy: 'alice',
            proofs: ['wrong-secret'],
        );

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Invalid hash preimage');

        $lock->validate($tx, 0);
    }

    public function test_validate_throws_with_missing_proof(): void
    {
        $lock = HashLock::sha256(self::SECRET, new Owner('alice'));
        $tx = new Tx(
            id: new TxId('tx1'),
            spends: [new OutputId('a')],
            outputs: [Output::open(100, 'b')],
            signedBy: 'alice',
        );

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Missing authorization proof for spend 0');

        $lock->validate($tx, 0);
    }

    public function test_validate_also_checks_inner_lock(): void
    {
        $lock = HashLock::sha256(self::SECRET, new Owner('alice'));
        $tx = new Tx(
            id: new TxId('tx1'),
            spends: [new OutputId('a')],
            outputs: [Output::open(100, 'b')],
            signedBy: 'bob', // Wrong signer
            proofs: [self::SECRET],
        );

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage("Output owned by 'alice', but spend signed by 'bob'");

        $lock->validate($tx, 0);
    }

    public function test_type_returns_hashlock(): void
    {
        $lock = HashLock::sha256(self::SECRET, new Owner('alice'));

        self::assertSame('hashlock', $lock->type());
    }

    public function test_to_array_returns_correct_format(): void
    {
        $lock = HashLock::sha256(self::SECRET, new Owner('alice'));

        $expected = [
            'type' => 'hashlock',
            'hash' => hash('sha256', self::SECRET),
            'algorithm' => 'sha256',
            'innerLock' => ['type' => 'owner', 'name' => 'alice'],
        ];

        self::assertSame($expected, $lock->toArray());
    }

    public function test_from_array_creates_lock(): void
    {
        LockFactory::registerFromClass(HashLock::class);

        $hash = hash('sha256', self::SECRET);
        $data = [
            'type' => 'hashlock',
            'hash' => $hash,
            'algorithm' => 'sha256',
            'innerLock' => ['type' => 'owner', 'name' => 'alice'],
        ];

        $lock = LockFactory::fromArray($data);

        self::assertInstanceOf(HashLock::class, $lock);
        self::assertSame($hash, $lock->hash);
        self::assertSame('sha256', $lock->algorithm);
    }

    public function test_supports_sha256(): void
    {
        $lock = HashLock::sha256(self::SECRET, new Owner('alice'));

        self::assertSame('sha256', $lock->algorithm);
    }

    public function test_supports_sha512(): void
    {
        $lock = new HashLock(
            hash: hash('sha512', self::SECRET),
            algorithm: 'sha512',
            innerLock: new Owner('alice'),
        );

        self::assertSame('sha512', $lock->algorithm);
    }

    public function test_supports_ripemd160(): void
    {
        $lock = new HashLock(
            hash: hash('ripemd160', self::SECRET),
            algorithm: 'ripemd160',
            innerLock: new Owner('alice'),
        );

        self::assertSame('ripemd160', $lock->algorithm);
    }

    public function test_hash_lock_without_inner_lock(): void
    {
        $lock = HashLock::sha256(self::SECRET);
        $tx = new Tx(
            id: new TxId('tx1'),
            spends: [new OutputId('a')],
            outputs: [Output::open(100, 'b')],
            proofs: [self::SECRET],
        );

        $this->expectNotToPerformAssertions();

        $lock->validate($tx, 0);
    }

    public function test_verify_preimage_returns_true_for_correct_preimage(): void
    {
        $lock = HashLock::sha256(self::SECRET, new Owner('alice'));

        self::assertTrue($lock->verifyPreimage(self::SECRET));
    }

    public function test_verify_preimage_returns_false_for_wrong_preimage(): void
    {
        $lock = HashLock::sha256(self::SECRET, new Owner('alice'));

        self::assertFalse($lock->verifyPreimage('wrong'));
    }

    public function test_throws_on_whitespace_only_hash(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Hash cannot be empty');

        HashLock::fromHash('   ', 'sha256', new Owner('alice'));
    }

    public function test_from_array_without_inner_lock(): void
    {
        LockFactory::registerFromClass(HashLock::class);

        $hash = hash('sha256', self::SECRET);
        $data = [
            'type' => 'hashlock',
            'hash' => $hash,
            'algorithm' => 'sha256',
        ];

        $lock = LockFactory::fromArray($data);

        self::assertInstanceOf(HashLock::class, $lock);
        self::assertNull($lock->innerLock);
    }

    public function test_to_array_without_inner_lock(): void
    {
        $lock = HashLock::sha256(self::SECRET);

        $expected = [
            'type' => 'hashlock',
            'hash' => hash('sha256', self::SECRET),
            'algorithm' => 'sha256',
        ];

        self::assertSame($expected, $lock->toArray());
    }
}
