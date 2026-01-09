<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit;

use Chemaclass\Unspent\Exception\AuthorizationException;
use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Lock\NoLock;
use Chemaclass\Unspent\Lock\OwnerLock;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\Spend;
use PHPUnit\Framework\TestCase;

final class OwnershipTest extends TestCase
{
    public function test_owner_can_spend_their_output(): void
    {
        $ledger = Ledger::empty()->addGenesis(
            Output::create(1000, 'alice-funds', new OwnerLock('alice')),
        );

        $ledger = $ledger->apply(Spend::create(
            inputIds: ['alice-funds'],
            outputs: [Output::create(900, 'bob-payment')],
            authorizedBy: 'alice',
        ));

        self::assertSame(900, $ledger->totalUnspentAmount());
    }

    public function test_non_owner_cannot_spend_others_output(): void
    {
        $ledger = Ledger::empty()->addGenesis(
            Output::create(1000, 'alice-funds', new OwnerLock('alice')),
        );

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage("Output owned by 'alice', but spend authorized by 'bob'");

        $ledger->apply(Spend::create(
            inputIds: ['alice-funds'],
            outputs: [Output::create(900, 'stolen')],
            authorizedBy: 'bob',
        ));
    }

    public function test_unauthorized_spend_without_authorization_fails(): void
    {
        $ledger = Ledger::empty()->addGenesis(
            Output::create(1000, 'alice-funds', new OwnerLock('alice')),
        );

        $this->expectException(AuthorizationException::class);

        $ledger->apply(Spend::create(
            inputIds: ['alice-funds'],
            outputs: [Output::create(900, 'stolen')],
        ));
    }

    public function test_unlocked_outputs_can_be_spent_by_anyone(): void
    {
        $ledger = Ledger::empty()->addGenesis(
            Output::create(1000, 'open-funds', new NoLock()),
        );

        $ledger = $ledger->apply(Spend::create(
            inputIds: ['open-funds'],
            outputs: [Output::create(900, 'taken')],
        ));

        self::assertSame(900, $ledger->totalUnspentAmount());
    }

    public function test_default_output_has_no_lock(): void
    {
        $ledger = Ledger::empty()->addGenesis(
            Output::create(1000, 'default-funds'),
        );

        $ledger = $ledger->apply(Spend::create(
            inputIds: ['default-funds'],
            outputs: [Output::create(900, 'spent')],
        ));

        self::assertSame(900, $ledger->totalUnspentAmount());
    }

    public function test_spend_can_combine_inputs_from_same_owner(): void
    {
        $ledger = Ledger::empty()->addGenesis(
            Output::create(500, 'alice-1', new OwnerLock('alice')),
            Output::create(300, 'alice-2', new OwnerLock('alice')),
        );

        $ledger = $ledger->apply(Spend::create(
            inputIds: ['alice-1', 'alice-2'],
            outputs: [Output::create(750, 'combined')],
            authorizedBy: 'alice',
        ));

        self::assertSame(750, $ledger->totalUnspentAmount());
    }

    public function test_cannot_combine_inputs_from_different_owners(): void
    {
        $ledger = Ledger::empty()->addGenesis(
            Output::create(500, 'alice-funds', new OwnerLock('alice')),
            Output::create(300, 'bob-funds', new OwnerLock('bob')),
        );

        $this->expectException(AuthorizationException::class);

        $ledger->apply(Spend::create(
            inputIds: ['alice-funds', 'bob-funds'],
            outputs: [Output::create(750, 'combined')],
            authorizedBy: 'alice',
        ));
    }

    public function test_ownership_transfers_with_new_lock(): void
    {
        $ledger = Ledger::empty()->addGenesis(
            Output::create(1000, 'alice-funds', new OwnerLock('alice')),
        );

        $ledger = $ledger->apply(Spend::create(
            inputIds: ['alice-funds'],
            outputs: [Output::create(900, 'bob-funds', new OwnerLock('bob'))],
            authorizedBy: 'alice',
        ));

        // Alice can no longer spend
        $this->expectException(AuthorizationException::class);
        $ledger->apply(Spend::create(
            inputIds: ['bob-funds'],
            outputs: [Output::create(800, 'stolen')],
            authorizedBy: 'alice',
        ));
    }

    public function test_ownership_preserved_through_serialization(): void
    {
        $original = Ledger::empty()->addGenesis(
            Output::create(1000, 'alice-funds', new OwnerLock('alice')),
        );

        $restored = Ledger::fromJson($original->toJson());

        // Owner can still spend after restore
        $restored = $restored->apply(Spend::create(
            inputIds: ['alice-funds'],
            outputs: [Output::create(900, 'spent')],
            authorizedBy: 'alice',
        ));

        self::assertSame(900, $restored->totalUnspentAmount());
    }

    public function test_ownership_lock_serialization_round_trip(): void
    {
        $original = Ledger::empty()->addGenesis(
            Output::create(1000, 'alice-funds', new OwnerLock('alice')),
        );

        $restored = Ledger::fromJson($original->toJson());

        // Non-owner still blocked after restore
        $this->expectException(AuthorizationException::class);
        $restored->apply(Spend::create(
            inputIds: ['alice-funds'],
            outputs: [Output::create(900, 'stolen')],
            authorizedBy: 'bob',
        ));
    }
}
