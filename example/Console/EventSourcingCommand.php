<?php

declare(strict_types=1);

namespace Example\Console;

use Chemaclass\Unspent\CoinbaseTx;
use Chemaclass\Unspent\LedgerInterface;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\Tx;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

#[AsCommand(
    name: 'sample:event-sourcing',
    description: 'Event Sourcing - Order Lifecycle',
    aliases: ['events'],
)]
final class EventSourcingCommand extends AbstractExampleCommand
{
    protected function runDemo(): int
    {
        $this->io->text('Order lifecycle: placed -> paid -> shipped -> delivered');
        $this->io->text('Each transition spends old state, creates new state');
        $this->io->newLine();

        $ledger = $this->loadOrCreateEmpty();
        $ledger = $this->processNewOrder($ledger);

        $this->save($ledger);

        $this->showAllOrders($ledger);
        $this->showStats($ledger);

        return Command::SUCCESS;
    }

    private function processNewOrder(LedgerInterface $ledger): LedgerInterface
    {
        $orderNum = $ledger->unspent()->count() + 1;
        $orderId = "order-{$orderNum}";

        // Place order (using coinbase to mint the order state token)
        $ledger->applyCoinbase(CoinbaseTx::create(
            outputs: [Output::open(1, "{$orderId}_placed")],
            id: "create_{$orderId}",
        ));
        $this->io->text("{$orderId}: placed");

        // Pay
        $ledger->apply(Tx::create(
            spendIds: ["{$orderId}_placed"],
            outputs: [Output::open(1, "{$orderId}_paid")],
            id: "evt_{$orderId}_payment",
        ));
        $this->io->text("{$orderId}: paid");

        // Ship
        $ledger->apply(Tx::create(
            spendIds: ["{$orderId}_paid"],
            outputs: [Output::open(1, "{$orderId}_shipped")],
            id: "evt_{$orderId}_shipped",
        ));
        $this->io->text("{$orderId}: shipped");

        // Deliver
        $ledger->apply(Tx::create(
            spendIds: ["{$orderId}_shipped"],
            outputs: [Output::open(1, "{$orderId}_delivered")],
            id: "evt_{$orderId}_delivered",
        ));
        $this->io->text("{$orderId}: delivered");
        $this->io->newLine();

        // Show event chain for this order
        $this->showEventChain($ledger, $orderId);

        return $ledger;
    }

    private function showEventChain(LedgerInterface $ledger, string $orderId): void
    {
        $this->io->section("Event Chain for {$orderId}");

        $states = [
            "{$orderId}_placed",
            "{$orderId}_paid",
            "{$orderId}_shipped",
            "{$orderId}_delivered",
        ];

        foreach ($states as $state) {
            $id = new OutputId($state);
            $created = $ledger->outputCreatedBy($id);
            $spent = $ledger->outputSpentBy($id);
            $status = $spent ? "-> {$spent}" : '(current)';
            $this->io->text("  {$state}: {$created} {$status}");
        }
    }

    private function showAllOrders(LedgerInterface $ledger): void
    {
        $this->io->section('All Orders (Current State)');

        foreach ($ledger->unspent() as $id => $output) {
            $parts = explode('_', $id);
            if (\count($parts) === 2) {
                [$orderId, $state] = $parts;
                $this->io->text("  {$orderId}: {$state}");
            }
        }
    }
}
