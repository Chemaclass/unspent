<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit\Selection;

use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\Selection\ExactMatchStrategy;
use Chemaclass\Unspent\Selection\SelectionStrategy;
use Chemaclass\Unspent\UnspentSet;
use PHPUnit\Framework\TestCase;

final class ExactMatchStrategyTest extends TestCase
{
    public function test_name_returns_exact_match(): void
    {
        $strategy = new ExactMatchStrategy();

        self::assertSame('exact-match', $strategy->name());
    }

    public function test_select_finds_exact_match_single_output(): void
    {
        $strategy = new ExactMatchStrategy();
        $available = UnspentSet::fromOutputs(
            Output::open(100, 'a'),
            Output::open(50, 'b'),
            Output::open(25, 'c'),
        );

        $selected = $strategy->select($available, 100);

        self::assertCount(1, $selected);
        self::assertSame(100, $this->sum($selected));
    }

    public function test_select_finds_exact_match_multiple_outputs(): void
    {
        $strategy = new ExactMatchStrategy();
        $available = UnspentSet::fromOutputs(
            Output::open(70, 'a'),
            Output::open(30, 'b'),
            Output::open(20, 'c'),
        );

        $selected = $strategy->select($available, 100);

        self::assertSame(100, $this->sum($selected));
    }

    public function test_select_finds_exact_match_three_outputs(): void
    {
        $strategy = new ExactMatchStrategy();
        $available = UnspentSet::fromOutputs(
            Output::open(40, 'a'),
            Output::open(35, 'b'),
            Output::open(25, 'c'),
        );

        $selected = $strategy->select($available, 100);

        self::assertCount(3, $selected);
        self::assertSame(100, $this->sum($selected));
    }

    public function test_select_prefers_fewer_outputs(): void
    {
        $strategy = new ExactMatchStrategy();
        $available = UnspentSet::fromOutputs(
            Output::open(100, 'a'),
            Output::open(50, 'b'),
            Output::open(30, 'c'),
            Output::open(20, 'd'),
        );

        $selected = $strategy->select($available, 100);

        // Should prefer single output over multiple
        self::assertCount(1, $selected);
        self::assertSame(100, $this->sum($selected));
    }

    public function test_select_falls_back_when_no_exact_match(): void
    {
        $strategy = new ExactMatchStrategy();
        $available = UnspentSet::fromOutputs(
            Output::open(70, 'a'),
            Output::open(50, 'b'),
        );

        $selected = $strategy->select($available, 100);

        // Should use fallback strategy and return enough to cover target
        self::assertGreaterThanOrEqual(100, $this->sum($selected));
    }

    public function test_select_with_custom_fallback(): void
    {
        $fallback = new class() implements SelectionStrategy {
            public function select(UnspentSet $available, int $target): array
            {
                return [Output::open(999, 'fallback')];
            }

            public function name(): string
            {
                return 'test-fallback';
            }
        };

        $strategy = new ExactMatchStrategy($fallback);
        $available = UnspentSet::fromOutputs(
            Output::open(70, 'a'),
            Output::open(50, 'b'),
        );

        $selected = $strategy->select($available, 100);

        // Should use our custom fallback
        self::assertCount(1, $selected);
        self::assertSame(999, $selected[0]->amount);
    }

    public function test_select_handles_empty_set(): void
    {
        $strategy = new ExactMatchStrategy();
        $available = UnspentSet::empty();

        $selected = $strategy->select($available, 100);

        self::assertSame([], $selected);
    }

    public function test_select_handles_large_output_set(): void
    {
        $strategy = new ExactMatchStrategy();
        $outputs = [];
        for ($i = 0; $i < 150; ++$i) {
            $outputs[] = Output::open($i + 1, "output-{$i}");
        }
        $available = UnspentSet::fromOutputs(...$outputs);

        // This should complete without timeout
        $selected = $strategy->select($available, 100);

        self::assertGreaterThanOrEqual(100, $this->sum($selected));
    }

    public function test_select_exact_match_with_target_zero(): void
    {
        $strategy = new ExactMatchStrategy();
        $available = UnspentSet::fromOutputs(
            Output::open(100, 'a'),
        );

        $selected = $strategy->select($available, 0);

        self::assertSame([], $selected);
    }

    /**
     * @param list<Output> $outputs
     */
    private function sum(array $outputs): int
    {
        return array_sum(array_map(static fn (Output $o): int => $o->amount, $outputs));
    }
}
