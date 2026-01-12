<?php

declare(strict_types=1);

namespace Example\Console;

use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\LedgerInterface;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\Tx;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'sample:event-sourcing',
    description: 'Event Sourcing - Order Lifecycle',
    aliases: ['events'],
)]
final class EventSourcingCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Event Sourcing - Order Lifecycle');
        $io->text('Order lifecycle: placed -> paid -> shipped -> delivered');
        $io->text('Each transition spends old state, creates new state');
        $io->newLine();

        $orders = $this->processOrderLifecycle($io);
        $this->showEventChain($io, $orders);
        $this->showMultipleOrders($io);

        return Command::SUCCESS;
    }

    private function processOrderLifecycle(SymfonyStyle $io): LedgerInterface
    {
        $orders = Ledger::withGenesis(
            Output::open(1, 'order-1001_placed'),
        );
        $io->text('Order #1001: placed');

        $orders = $orders->apply(Tx::create(
            spendIds: ['order-1001_placed'],
            outputs: [Output::open(1, 'order-1001_paid')],
            id: 'evt_payment',
        ));
        $io->text('Order #1001: paid');

        $orders = $orders->apply(Tx::create(
            spendIds: ['order-1001_paid'],
            outputs: [Output::open(1, 'order-1001_shipped')],
            id: 'evt_shipped',
        ));
        $io->text('Order #1001: shipped');

        $orders = $orders->apply(Tx::create(
            spendIds: ['order-1001_shipped'],
            outputs: [Output::open(1, 'order-1001_delivered')],
            id: 'evt_delivered',
        ));
        $io->text('Order #1001: delivered');
        $io->newLine();

        return $orders;
    }

    private function showEventChain(SymfonyStyle $io, LedgerInterface $orders): void
    {
        $io->section('Event Chain');

        $states = [
            'order-1001_placed',
            'order-1001_paid',
            'order-1001_shipped',
            'order-1001_delivered',
        ];

        foreach ($states as $state) {
            $id = new OutputId($state);
            $created = $orders->outputCreatedBy($id);
            $spent = $orders->outputSpentBy($id);
            $status = $spent ? "-> {$spent}" : '(current)';
            $io->text("  {$state}: {$created} {$status}");
        }
    }

    private function showMultipleOrders(SymfonyStyle $io): void
    {
        $io->section('Multiple Orders');

        $multi = Ledger::withGenesis(
            Output::open(1, 'order-2001_placed'),
            Output::open(1, 'order-2002_placed'),
        );

        $multi = $multi->apply(Tx::create(
            spendIds: ['order-2001_placed'],
            outputs: [Output::open(1, 'order-2001_paid')],
            id: 'evt_2001_pay',
        ));

        foreach ($multi->unspent() as $id => $output) {
            [$orderId, $state] = explode('_', $id);
            $io->text("  {$orderId}: {$state}");
        }
    }
}
