<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit;

use Chemaclass\Unspent\Id;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\SpendId;
use PHPUnit\Framework\TestCase;
use Stringable;

final class IdInterfaceTest extends TestCase
{
    public function test_output_id_implements_id_interface(): void
    {
        $id = new OutputId('test');

        self::assertInstanceOf(Id::class, $id);
        self::assertInstanceOf(Stringable::class, $id);
    }

    public function test_spend_id_implements_id_interface(): void
    {
        $id = new SpendId('test');

        self::assertInstanceOf(Id::class, $id);
        self::assertInstanceOf(Stringable::class, $id);
    }

    public function test_can_handle_ids_generically(): void
    {
        $ids = [
            new OutputId('output-1'),
            new SpendId('spend-1'),
            new OutputId('output-2'),
        ];

        $values = array_map(
            static fn(Id $id): string => $id->value,
            $ids,
        );

        self::assertSame(['output-1', 'spend-1', 'output-2'], $values);
    }

    public function test_ids_are_stringable(): void
    {
        $outputId = new OutputId('out-123');
        $spendId = new SpendId('tx-456');

        self::assertSame('out-123', (string) $outputId);
        self::assertSame('tx-456', (string) $spendId);
    }
}
