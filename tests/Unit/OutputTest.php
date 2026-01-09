<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit;

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

    public function test_create_factory_method(): void
    {
        $output = Output::create(100, 'test-id');

        self::assertSame('test-id', $output->id->value);
        self::assertSame(100, $output->amount);
    }

    public function test_create_with_auto_generated_id(): void
    {
        $output = Output::create(100);

        self::assertSame(32, strlen($output->id->value));
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $output->id->value);
    }

    public function test_auto_id_generates_unique_ids(): void
    {
        $output1 = Output::create(100);
        $output2 = Output::create(100);

        // Each call generates a unique ID (includes random bytes)
        self::assertNotSame($output1->id->value, $output2->id->value);
    }
}
