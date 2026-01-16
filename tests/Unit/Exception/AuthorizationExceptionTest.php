<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit\Exception;

use Chemaclass\Unspent\Exception\AuthorizationException;
use Chemaclass\Unspent\Exception\UnspentException;
use PHPUnit\Framework\TestCase;

final class AuthorizationExceptionTest extends TestCase
{
    public function test_extends_unspent_exception(): void
    {
        $exception = AuthorizationException::notOwner('alice', 'bob');

        self::assertInstanceOf(UnspentException::class, $exception);
    }

    public function test_not_owner_with_actual_signer(): void
    {
        $exception = AuthorizationException::notOwner('alice', 'bob');

        self::assertSame("Output owned by 'alice', but spend signed by 'bob'", $exception->getMessage());
        self::assertSame(AuthorizationException::CODE_NOT_OWNER, $exception->getCode());
    }

    public function test_not_owner_with_null_signer(): void
    {
        $exception = AuthorizationException::notOwner('alice', null);

        self::assertSame("Output owned by 'alice', but spend signed by 'null'", $exception->getMessage());
        self::assertSame(AuthorizationException::CODE_NOT_OWNER, $exception->getCode());
    }

    public function test_missing_proof(): void
    {
        $exception = AuthorizationException::missingProof(0);

        self::assertSame('Missing authorization proof for spend 0', $exception->getMessage());
        self::assertSame(AuthorizationException::CODE_MISSING_PROOF, $exception->getCode());
    }

    public function test_missing_proof_with_different_index(): void
    {
        $exception = AuthorizationException::missingProof(5);

        self::assertSame('Missing authorization proof for spend 5', $exception->getMessage());
        self::assertSame(AuthorizationException::CODE_MISSING_PROOF, $exception->getCode());
    }

    public function test_invalid_signature(): void
    {
        $exception = AuthorizationException::invalidSignature(0);

        self::assertSame('Invalid signature for spend 0', $exception->getMessage());
        self::assertSame(AuthorizationException::CODE_INVALID_SIGNATURE, $exception->getCode());
    }

    public function test_invalid_signature_with_different_index(): void
    {
        $exception = AuthorizationException::invalidSignature(3);

        self::assertSame('Invalid signature for spend 3', $exception->getMessage());
        self::assertSame(AuthorizationException::CODE_INVALID_SIGNATURE, $exception->getCode());
    }

    public function test_error_codes_are_distinct(): void
    {
        self::assertNotSame(
            AuthorizationException::CODE_NOT_OWNER,
            AuthorizationException::CODE_MISSING_PROOF,
        );
        self::assertNotSame(
            AuthorizationException::CODE_NOT_OWNER,
            AuthorizationException::CODE_INVALID_SIGNATURE,
        );
        self::assertNotSame(
            AuthorizationException::CODE_MISSING_PROOF,
            AuthorizationException::CODE_INVALID_SIGNATURE,
        );
    }

    public function test_error_code_values(): void
    {
        self::assertSame(1000, AuthorizationException::CODE_NOT_OWNER);
        self::assertSame(1001, AuthorizationException::CODE_MISSING_PROOF);
        self::assertSame(1002, AuthorizationException::CODE_INVALID_SIGNATURE);
    }
}
