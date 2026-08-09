<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit\Selection;

use Chemaclass\Unspent\Exception\InsufficientSpendsException;
use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\Selection\FifoStrategy;
use Chemaclass\Unspent\Selection\LargestFirstStrategy;
use Chemaclass\Unspent\Selection\SmallestFirstStrategy;
use Chemaclass\Unspent\TxId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Ledger::class)]
final class LedgerSelectionStrategyTest extends TestCase
{
    public function test_default_selection_spends_outputs_in_insertion_order(): void
    {
        $ledger = $this->ledgerWithOutputs();

        $ledger->transfer('alice', 'bob', 15, txId: 'tx-1');

        self::assertFalse($ledger->unspent()->contains($this->id('a-small')));
        self::assertFalse($ledger->unspent()->contains($this->id('a-mid')));
        self::assertTrue($ledger->unspent()->contains($this->id('a-large')));
    }

    public function test_largest_first_strategy_spends_the_biggest_output_only(): void
    {
        $ledger = $this->ledgerWithOutputs()->selectWith(new LargestFirstStrategy());

        $ledger->transfer('alice', 'bob', 15, txId: 'tx-1');

        self::assertTrue($ledger->unspent()->contains($this->id('a-small')));
        self::assertTrue($ledger->unspent()->contains($this->id('a-mid')));
        self::assertFalse($ledger->unspent()->contains($this->id('a-large')));
    }

    public function test_smallest_first_strategy_consumes_dust_first(): void
    {
        $ledger = $this->ledgerWithOutputs()->selectWith(new SmallestFirstStrategy());

        $ledger->debit('alice', 12, txId: 'tx-1');

        self::assertFalse($ledger->unspent()->contains($this->id('a-small')));
        self::assertFalse($ledger->unspent()->contains($this->id('a-mid')));
        self::assertTrue($ledger->unspent()->contains($this->id('a-large')));
    }

    public function test_strategy_applies_to_batch_transfer(): void
    {
        $ledger = $this->ledgerWithOutputs()->selectWith(new LargestFirstStrategy());

        $ledger->batchTransfer('alice', ['bob' => 10, 'carol' => 20], txId: 'tx-1');

        self::assertFalse($ledger->unspent()->contains($this->id('a-large')));
        self::assertSame(10, $ledger->totalUnspentByOwner('bob'));
        self::assertSame(20, $ledger->totalUnspentByOwner('carol'));
    }

    public function test_strategy_change_is_kept_after_a_transfer(): void
    {
        $ledger = $this->ledgerWithOutputs()->selectWith(new LargestFirstStrategy());

        $ledger->transfer('alice', 'bob', 1, txId: 'tx-1');
        $ledger->credit('alice', 500, 'cb-1');
        $ledger->transfer('alice', 'bob', 1, txId: 'tx-2');

        self::assertTrue($ledger->isTxApplied(new TxId('tx-2')));
    }

    public function test_strategy_can_be_reset_back_to_the_default(): void
    {
        $ledger = $this->ledgerWithOutputs()
            ->selectWith(new LargestFirstStrategy())
            ->selectWith(null);

        $ledger->transfer('alice', 'bob', 15, txId: 'tx-1');

        self::assertFalse($ledger->unspent()->contains($this->id('a-small')));
        self::assertTrue($ledger->unspent()->contains($this->id('a-large')));
    }

    public function test_strategy_selection_below_target_reports_insufficient_spends(): void
    {
        $ledger = $this->ledgerWithOutputs()->selectWith(new FifoStrategy());

        $this->expectException(InsufficientSpendsException::class);

        $ledger->transfer('alice', 'bob', 10_000, txId: 'tx-1');
    }

    public function test_selection_strategy_is_ignored_for_owners_without_outputs(): void
    {
        $ledger = $this->ledgerWithOutputs()->selectWith(new LargestFirstStrategy());

        $this->expectException(InsufficientSpendsException::class);

        $ledger->transfer('nobody', 'bob', 1, txId: 'tx-1');
    }

    private function ledgerWithOutputs(): Ledger
    {
        return Ledger::withGenesis(
            Output::ownedBy('alice', 5, 'a-small'),
            Output::ownedBy('alice', 20, 'a-mid'),
            Output::ownedBy('alice', 100, 'a-large'),
        );
    }

    private function id(string $value): OutputId
    {
        return new OutputId($value);
    }
}
