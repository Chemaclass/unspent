<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit;

use Chemaclass\Unspent\Lock\NoLock;
use Chemaclass\Unspent\Lock\Owner;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputHistory;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\OutputStatus;
use PHPUnit\Framework\TestCase;

final class OutputHistoryTest extends TestCase
{
    public function test_constructor_stores_all_properties(): void
    {
        $id = new OutputId('test-id');
        $lock = new Owner('alice');

        $history = new OutputHistory(
            id: $id,
            amount: 100,
            lock: $lock,
            createdBy: 'tx-1',
            spentBy: 'tx-2',
            status: OutputStatus::SPENT,
        );

        self::assertSame($id, $history->id);
        self::assertSame(100, $history->amount);
        self::assertSame($lock, $history->lock);
        self::assertSame('tx-1', $history->createdBy);
        self::assertSame('tx-2', $history->spentBy);
        self::assertSame(OutputStatus::SPENT, $history->status);
    }

    public function test_from_output_creates_history_for_unspent_output(): void
    {
        $output = Output::ownedBy('alice', 100);

        $history = OutputHistory::fromOutput($output, 'tx-1', null);

        self::assertSame($output->id, $history->id);
        self::assertSame(100, $history->amount);
        self::assertSame('tx-1', $history->createdBy);
        self::assertNull($history->spentBy);
        self::assertSame(OutputStatus::UNSPENT, $history->status);
    }

    public function test_from_output_creates_history_for_spent_output(): void
    {
        $output = Output::ownedBy('alice', 100);

        $history = OutputHistory::fromOutput($output, 'tx-1', 'tx-2');

        self::assertSame('tx-2', $history->spentBy);
        self::assertSame(OutputStatus::SPENT, $history->status);
    }

    public function test_is_spent_returns_true_when_spent(): void
    {
        $output = Output::ownedBy('alice', 100);
        $history = OutputHistory::fromOutput($output, 'tx-1', 'tx-2');

        self::assertTrue($history->isSpent());
    }

    public function test_is_spent_returns_false_when_unspent(): void
    {
        $output = Output::ownedBy('alice', 100);
        $history = OutputHistory::fromOutput($output, 'tx-1', null);

        self::assertFalse($history->isSpent());
    }

    public function test_is_unspent_returns_true_when_unspent(): void
    {
        $output = Output::ownedBy('alice', 100);
        $history = OutputHistory::fromOutput($output, 'tx-1', null);

        self::assertTrue($history->isUnspent());
    }

    public function test_is_unspent_returns_false_when_spent(): void
    {
        $output = Output::ownedBy('alice', 100);
        $history = OutputHistory::fromOutput($output, 'tx-1', 'tx-2');

        self::assertFalse($history->isUnspent());
    }

    public function test_is_genesis_returns_true_when_created_by_genesis(): void
    {
        $output = Output::ownedBy('alice', 100);
        $history = OutputHistory::fromOutput($output, 'genesis', null);

        self::assertTrue($history->isGenesis());
    }

    public function test_is_genesis_returns_false_when_created_by_transaction(): void
    {
        $output = Output::ownedBy('alice', 100);
        $history = OutputHistory::fromOutput($output, 'tx-1', null);

        self::assertFalse($history->isGenesis());
    }

    public function test_is_genesis_returns_false_when_created_by_is_null(): void
    {
        $output = Output::ownedBy('alice', 100);
        $history = OutputHistory::fromOutput($output, null, null);

        self::assertFalse($history->isGenesis());
    }

    public function test_to_array_serializes_unspent_output(): void
    {
        $output = Output::ownedBy('alice', 100);
        $history = OutputHistory::fromOutput($output, 'tx-1', null);

        $array = $history->toArray();

        self::assertSame($output->id->value, $array['id']);
        self::assertSame(100, $array['amount']);
        self::assertSame(['type' => 'owner', 'name' => 'alice'], $array['lock']);
        self::assertSame('tx-1', $array['createdBy']);
        self::assertNull($array['spentBy']);
        self::assertSame('unspent', $array['status']);
    }

    public function test_to_array_serializes_spent_output(): void
    {
        $output = Output::ownedBy('bob', 50);
        $history = OutputHistory::fromOutput($output, 'tx-1', 'tx-2');

        $array = $history->toArray();

        self::assertSame('tx-2', $array['spentBy']);
        self::assertSame('spent', $array['status']);
    }

    public function test_to_array_with_no_lock(): void
    {
        $output = Output::open(100);
        $history = OutputHistory::fromOutput($output, 'tx-1', null);

        $array = $history->toArray();

        self::assertSame(['type' => 'none'], $array['lock']);
    }

    public function test_from_output_preserves_lock_type(): void
    {
        $output = Output::open(100);
        $history = OutputHistory::fromOutput($output, 'tx-1', null);

        self::assertInstanceOf(NoLock::class, $history->lock);
    }
}
