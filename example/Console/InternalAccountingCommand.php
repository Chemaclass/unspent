<?php

declare(strict_types=1);

namespace Example\Console;

use Chemaclass\Unspent\Exception\AuthorizationException;
use Chemaclass\Unspent\Exception\InsufficientSpendsException;
use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\LedgerInterface;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\Tx;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'sample:internal-accounting',
    description: 'Internal Accounting - Department Budgets',
    aliases: ['accounting'],
)]
final class InternalAccountingCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Internal Accounting - Department Budgets');

        $company = $this->allocateBudget($io);
        $company = $this->splitEngineeringBudget($io, $company);
        $this->demonstrateUnauthorizedBlocked($io, $company);
        $company = $this->interDepartmentTransfer($io, $company);
        $this->demonstrateOverspendBlocked($io, $company);
        $this->showAuditTrail($io, $company);
        $this->showReconciliation($io, $company);
        $this->showCurrentState($io, $company);

        return Command::SUCCESS;
    }

    private function allocateBudget(SymfonyStyle $io): LedgerInterface
    {
        $company = Ledger::withGenesis(
            Output::ownedBy('engineering', 100_000, 'eng-budget'),
            Output::ownedBy('marketing', 50_000, 'mkt-budget'),
            Output::ownedBy('operations', 30_000, 'ops-budget'),
        );

        $io->text('FY Budget: Eng=$100k, Mkt=$50k, Ops=$30k');
        $io->text("Total: \${$company->totalUnspentAmount()}");
        $io->newLine();

        return $company;
    }

    private function splitEngineeringBudget(SymfonyStyle $io, LedgerInterface $company): LedgerInterface
    {
        $company = $company->apply(Tx::create(
            spendIds: ['eng-budget'],
            outputs: [
                Output::ownedBy('engineering', 60_000, 'eng-projects'),
                Output::ownedBy('engineering', 40_000, 'eng-infra'),
            ],
            signedBy: 'engineering',
            id: 'eng-split',
        ));

        $io->text('Engineering splits: projects=$60k, infra=$40k');

        return $company;
    }

    private function demonstrateUnauthorizedBlocked(SymfonyStyle $io, LedgerInterface $company): void
    {
        $io->newLine();
        $io->text('Finance tries to reallocate engineering funds... ');

        try {
            $company->apply(Tx::create(
                spendIds: ['eng-projects'],
                outputs: [Output::ownedBy('marketing', 60_000)],
                signedBy: 'finance',
            ));
        } catch (AuthorizationException) {
            $io->text('<fg=green>BLOCKED</>');
        }
    }

    private function interDepartmentTransfer(SymfonyStyle $io, LedgerInterface $company): LedgerInterface
    {
        // Simple API: handles output selection and change automatically
        $company = $company->transfer('operations', 'marketing', 15_000, fee: 600);

        $io->newLine();
        $io->text('Ops transfers $15k to Marketing (2% admin fee)');
        $io->text('Fee: $600');

        return $company;
    }

    private function demonstrateOverspendBlocked(SymfonyStyle $io, LedgerInterface $company): void
    {
        $io->newLine();
        $io->text('Marketing tries to overspend... ');

        // Marketing has $65k total (50k original + 15k from ops)
        // Trying to spend $100k should fail
        try {
            $company->transfer('marketing', 'vendor', 100_000);
        } catch (InsufficientSpendsException) {
            $io->text('<fg=green>BLOCKED</>');
        }
    }

    private function showAuditTrail(SymfonyStyle $io, LedgerInterface $company): void
    {
        $io->section('Audit Trail');

        // Find a marketing output to trace (transfer from operations)
        foreach ($company->unspentByOwner('marketing') as $output) {
            $createdBy = $company->outputCreatedBy($output->id);
            $io->text("{$output->id->value}: created by {$createdBy}");
            break;
        }
    }

    private function showReconciliation(SymfonyStyle $io, LedgerInterface $company): void
    {
        $io->section('Reconciliation');

        $initial = 180_000;
        $fees = $company->totalFeesCollected();
        $remaining = $company->totalUnspentAmount();

        $io->listing([
            "Initial: \${$initial}",
            "Fees: \${$fees}",
            "Remaining: \${$remaining}",
            'Check: ' . ($fees + $remaining === $initial ? 'BALANCED' : 'DISCREPANCY'),
        ]);
    }

    private function showCurrentState(SymfonyStyle $io, LedgerInterface $company): void
    {
        $io->section('Budget by Department');

        $budgets = [];
        foreach ($company->unspent() as $output) {
            $dept = $output->lock->toArray()['name'] ?? 'unassigned';
            $budgets[$dept] = ($budgets[$dept] ?? 0) + $output->amount;
        }

        foreach ($budgets as $dept => $amount) {
            $io->text("  {$dept}: \$" . number_format($amount));
        }
    }
}
