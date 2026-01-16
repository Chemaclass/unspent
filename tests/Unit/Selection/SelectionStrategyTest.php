<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit\Selection;

use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\Selection\ExactMatchStrategy;
use Chemaclass\Unspent\Selection\FifoStrategy;
use Chemaclass\Unspent\Selection\LargestFirstStrategy;
use Chemaclass\Unspent\Selection\SmallestFirstStrategy;
use Chemaclass\Unspent\UnspentSet;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SelectionStrategyTest extends TestCase
{
    private UnspentSet $outputs;

    protected function setUp(): void
    {
        $this->outputs = UnspentSet::fromOutputs(
            Output::ownedBy('alice', 100, 'a'),
            Output::ownedBy('alice', 50, 'b'),
            Output::ownedBy('alice', 200, 'c'),
            Output::ownedBy('alice', 75, 'd'),
        );
    }

    #[Test]
    public function fifo_selects_in_order(): void
    {
        $strategy = new FifoStrategy();
        $selected = $strategy->select($this->outputs, 150);

        self::assertCount(2, $selected);
        self::assertSame('a', $selected[0]->id->value);
        self::assertSame('b', $selected[1]->id->value);
    }

    #[Test]
    public function fifo_selects_all_if_needed(): void
    {
        $strategy = new FifoStrategy();
        $selected = $strategy->select($this->outputs, 400);

        self::assertCount(4, $selected);
    }

    #[Test]
    public function largest_first_selects_biggest(): void
    {
        $strategy = new LargestFirstStrategy();
        $selected = $strategy->select($this->outputs, 250);

        self::assertCount(2, $selected);
        self::assertSame(200, $selected[0]->amount);
        self::assertSame(100, $selected[1]->amount);
    }

    #[Test]
    public function smallest_first_consolidates_dust(): void
    {
        $strategy = new SmallestFirstStrategy();
        $selected = $strategy->select($this->outputs, 150);

        self::assertCount(3, $selected);
        self::assertSame(50, $selected[0]->amount);
        self::assertSame(75, $selected[1]->amount);
        self::assertSame(100, $selected[2]->amount);
    }

    #[Test]
    public function exact_match_finds_exact_combination(): void
    {
        $strategy = new ExactMatchStrategy();

        // 100 + 50 = 150 exactly
        $selected = $strategy->select($this->outputs, 150);

        $total = array_sum(array_map(static fn (Output $o): int => $o->amount, $selected));
        self::assertSame(150, $total);
    }

    #[Test]
    public function exact_match_falls_back_when_no_exact(): void
    {
        $strategy = new ExactMatchStrategy();

        // 123 cannot be matched exactly
        $selected = $strategy->select($this->outputs, 123);

        $total = array_sum(array_map(static fn (Output $o): int => $o->amount, $selected));
        self::assertGreaterThanOrEqual(123, $total);
    }

    #[Test]
    public function strategies_have_names(): void
    {
        self::assertSame('fifo', new FifoStrategy()->name());
        self::assertSame('largest-first', new LargestFirstStrategy()->name());
        self::assertSame('smallest-first', new SmallestFirstStrategy()->name());
        self::assertSame('exact-match', new ExactMatchStrategy()->name());
    }

    #[Test]
    public function exact_match_prefers_fewer_outputs(): void
    {
        $outputs = UnspentSet::fromOutputs(
            Output::ownedBy('alice', 50, 'a'),
            Output::ownedBy('alice', 50, 'b'),
            Output::ownedBy('alice', 100, 'c'),
        );

        $strategy = new ExactMatchStrategy();
        $selected = $strategy->select($outputs, 100);

        // Should prefer single 100 over two 50s
        self::assertCount(1, $selected);
        self::assertSame(100, $selected[0]->amount);
    }
}
