<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit;

use Chemaclass\Unspent\Exception\DuplicateOutputIdException;
use Chemaclass\Unspent\Exception\InsufficientSpendsException;
use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\Tx;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Exact-boundary behaviour of the ledger: the points where "covers the target"
 * flips to "exceeds it", and where a change output stops being emitted.
 */
#[CoversClass(Ledger::class)]
final class LedgerBoundaryTest extends TestCase
{
    public function test_selection_stops_at_the_first_output_that_exactly_covers_the_target(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 40, 'a-1'),
            Output::ownedBy('alice', 60, 'a-2'),
        );

        $ledger->transfer('alice', 'bob', 40, txId: 'tx-1');

        self::assertFalse($ledger->unspent()->contains(new OutputId('a-1')));
        self::assertTrue(
            $ledger->unspent()->contains(new OutputId('a-2')),
            'an exact cover must not pull in the next output',
        );
    }

    public function test_transfer_of_the_exact_balance_creates_no_change_output(): void
    {
        $ledger = Ledger::withGenesis(Output::ownedBy('alice', 100, 'a-1'));

        $ledger->transfer('alice', 'bob', 100, txId: 'tx-1');

        self::assertSame(0, $ledger->totalUnspentByOwner('alice'));
        self::assertSame(1, $ledger->unspent()->count());
    }

    public function test_debit_of_the_exact_balance_leaves_the_transaction_without_outputs(): void
    {
        $ledger = Ledger::withGenesis(Output::ownedBy('alice', 100, 'a-1'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tx must have at least one output');

        $ledger->debit('alice', 100, txId: 'tx-1');
    }

    public function test_consolidate_rejects_a_fee_that_consumes_the_whole_balance(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 40, 'a-1'),
            Output::ownedBy('alice', 60, 'a-2'),
        );

        $this->expectException(InsufficientSpendsException::class);

        $ledger->consolidate('alice', fee: 100, txId: 'tx-1');
    }

    public function test_consolidate_allows_a_fee_one_unit_below_the_whole_balance(): void
    {
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 40, 'a-1'),
            Output::ownedBy('alice', 60, 'a-2'),
        );

        $ledger->consolidate('alice', fee: 99, txId: 'tx-1');

        self::assertSame(1, $ledger->totalUnspentByOwner('alice'));
    }

    public function test_an_output_id_may_be_reused_by_the_transaction_that_spends_it(): void
    {
        $ledger = Ledger::withGenesis(Output::open(100, 'recycled'));

        $ledger->apply(Tx::create(
            spendIds: ['recycled'],
            outputs: [Output::open(100, 'recycled')],
            id: 'tx-1',
        ));

        self::assertTrue($ledger->unspent()->contains(new OutputId('recycled')));
        self::assertSame(100, $ledger->totalUnspentAmount());
    }

    public function test_to_json_defaults_to_compact_unescaped_output(): void
    {
        $ledger = Ledger::withGenesis(Output::ownedBy('<alice>', 100, 'a-1'));

        $json = $ledger->toJson();

        // JSON_HEX_TAG would escape the angle brackets to their unicode form
        self::assertStringContainsString('"name":"<alice>"', $json);
        // JSON_PRETTY_PRINT would introduce newlines
        self::assertStringNotContainsString("\n", $json);
    }

    public function test_a_recycled_output_id_does_not_stop_the_conflict_check_for_later_outputs(): void
    {
        $ledger = Ledger::withGenesis(
            Output::open(100, 'recycled'),
            Output::open(50, 'taken'),
        );

        $this->expectException(DuplicateOutputIdException::class);

        // 'recycled' is legal (this tx spends it), but the scan must carry on
        // and still reject 'taken', which is unspent and untouched here.
        $ledger->apply(Tx::create(
            spendIds: ['recycled'],
            outputs: [Output::open(60, 'recycled'), Output::open(40, 'taken')],
            id: 'tx-1',
        ));
    }
}
