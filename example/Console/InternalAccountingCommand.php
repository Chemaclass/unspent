<?php

declare(strict_types=1);

namespace Example\Console;

use Chemaclass\Unspent\Exception\AuthorizationException;
use Chemaclass\Unspent\Exception\InsufficientSpendsException;
use Chemaclass\Unspent\LedgerInterface;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\Tx;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

#[AsCommand(
    name: 'sample:internal-accounting',
    description: 'Internal Accounting - Department Budgets',
    aliases: ['accounting'],
)]
final class InternalAccountingCommand extends AbstractExampleCommand
{
    protected function runDemo(): int
    {
        $ledger = $this->loadOrCreate(static fn (): array => [
            Output::ownedBy('engineering', 100_000, 'eng-budget'),
            Output::ownedBy('marketing', 50_000, 'mkt-budget'),
            Output::ownedBy('operations', 30_000, 'ops-budget'),
        ]);

        $this->io->text("Total budget: \${$ledger->totalUnspentAmount()}");
        $this->io->newLine();

        $ledger = $this->processRandomAction($ledger);

        $this->save($ledger);

        $this->demonstrateSecurityBlocks($ledger);
        $this->showCurrentState($ledger);
        $this->showReconciliation($ledger);
        $this->showStats($ledger);

        return Command::SUCCESS;
    }

    private function processRandomAction(LedgerInterface $ledger): LedgerInterface
    {
        // Find departments with available budget
        $departments = ['engineering', 'marketing', 'operations'];
        $availableDepts = [];

        foreach ($departments as $dept) {
            $balance = $ledger->totalUnspentByOwner($dept);
            if ($balance >= 5_000) {
                $availableDepts[$dept] = $balance;
            }
        }

        if ($availableDepts === []) {
            $this->io->text('No department has enough budget for transfers!');

            return $ledger;
        }

        // Pick random sender and action
        $depts = array_keys($availableDepts);
        $sender = $depts[array_rand($depts)];
        $balance = $availableDepts[$sender];
        $action = random_int(0, 1);

        if ($action === 0) {
            return $this->splitBudget($ledger, $sender, $balance);
        }

        $otherDepts = array_diff($depts, [$sender]);
        if ($otherDepts === []) {
            return $this->splitBudget($ledger, $sender, $balance);
        }

        $recipient = $otherDepts[array_rand($otherDepts)];

        return $this->transferBudget($ledger, $sender, $recipient, $balance);
    }

    private function splitBudget(LedgerInterface $ledger, string $dept, int $balance): LedgerInterface
    {
        $txNum = $ledger->unspent()->count();
        $splitAmount = (int) ($balance * 0.4);
        $remaining = $balance - $splitAmount;

        // Get the output to spend
        $outputs = array_values(iterator_to_array($ledger->unspentByOwner($dept)));
        if ($outputs === []) {
            return $ledger;
        }
        $toSpend = $outputs[0];

        $ledger = $ledger->apply(Tx::create(
            spendIds: [$toSpend->id->value],
            outputs: [
                Output::ownedBy($dept, $splitAmount, "{$dept}-split-a-{$txNum}"),
                Output::ownedBy($dept, $remaining, "{$dept}-split-b-{$txNum}"),
            ],
            signedBy: $dept,
            id: "{$dept}-split-{$txNum}",
        ));

        $this->io->text("{$dept} splits budget: \${$splitAmount} + \${$remaining}");

        return $ledger;
    }

    private function transferBudget(LedgerInterface $ledger, string $sender, string $recipient, int $balance): LedgerInterface
    {
        $transfer = min(15_000, (int) ($balance * 0.3));
        $fee = (int) ($transfer * 0.02);

        $ledger = $ledger->transfer($sender, $recipient, $transfer, fee: $fee);

        $this->io->text("{$sender} transfers \${$transfer} to {$recipient} (fee: \${$fee})");

        return $ledger;
    }

    private function demonstrateSecurityBlocks(LedgerInterface $ledger): void
    {
        $this->io->section('Security Demonstrations');

        // Unauthorized access attempt
        $this->io->text('Finance tries to reallocate engineering funds... ');
        $engOutputs = array_values(iterator_to_array($ledger->unspentByOwner('engineering')));
        if ($engOutputs !== []) {
            try {
                $ledger->apply(Tx::create(
                    spendIds: [$engOutputs[0]->id->value],
                    outputs: [Output::ownedBy('marketing', $engOutputs[0]->amount)],
                    signedBy: 'finance',
                ));
            } catch (AuthorizationException) {
                $this->io->text('<fg=green>BLOCKED</>');
            }
        }

        // Overspend attempt
        $this->io->text('Marketing tries to overspend... ');
        try {
            $ledger->transfer('marketing', 'vendor', 1_000_000);
        } catch (InsufficientSpendsException) {
            $this->io->text('<fg=green>BLOCKED</>');
        }
    }

    private function showReconciliation(LedgerInterface $ledger): void
    {
        $this->io->section('Reconciliation');

        // Initial budget comes from genesis (180k), not from minting
        $initial = 180_000;
        $fees = $ledger->totalFeesCollected();
        $remaining = $ledger->totalUnspentAmount();

        $this->io->listing([
            'Initial: $' . number_format($initial),
            'Fees: $' . number_format($fees),
            'Remaining: $' . number_format($remaining),
            'Check: ' . ($fees + $remaining === $initial ? 'BALANCED' : 'DISCREPANCY'),
        ]);
    }

    private function showCurrentState(LedgerInterface $ledger): void
    {
        $this->io->section('Budget by Department');

        $budgets = [];
        foreach ($ledger->unspent() as $output) {
            $dept = $output->lock->toArray()['name'] ?? 'unassigned';
            $budgets[$dept] = ($budgets[$dept] ?? 0) + $output->amount;
        }

        foreach ($budgets as $dept => $amount) {
            $this->io->text("  {$dept}: \$" . number_format($amount));
        }
    }
}
