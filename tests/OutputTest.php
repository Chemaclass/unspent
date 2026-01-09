<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests;

use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class OutputTest extends TestCase
{
    public function test_can_be_created_with_id_and_amount(): void
    {
        $id = new OutputId('out-1');
        $output = new Output($id, 100);

        self::assertSame($id, $output->id);
        self::assertSame(100, $output->amount);
    }

    public function test_amount_must_be_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Amount must be positive');

        new Output(new OutputId('out-1'), 0);
    }

    public function test_negative_amount_is_not_allowed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Amount must be positive');

        new Output(new OutputId('out-1'), -50);
    }
}
