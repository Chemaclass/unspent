<?php

declare(strict_types=1);

namespace Example\Console;

use Chemaclass\Unspent\LedgerInterface;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\Tx;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

#[AsCommand(
    name: 'sample:loyalty-points',
    description: 'Loyalty Points - Customer Rewards Program',
    aliases: ['loyalty'],
)]
final class LoyaltyPointsCommand extends AbstractExampleCommand
{
    protected function runMemoryDemo(): int
    {
        $ledger = $this->loadOrCreateEmpty();

        // Simple API: mints new value to the customer
        $ledger = $ledger->credit('alice', 50, 'earn-50');
        $this->io->text('Alice bought $50 -> earned 50 pts');

        $ledger = $ledger->credit('alice', 30, 'earn-30');
        $this->io->text('Alice bought $30 -> earned 30 pts');
        $this->io->newLine();

        $this->io->text("Total points minted: {$ledger->totalMinted()}");
        $this->io->text("Alice's balance: {$ledger->totalUnspentAmount()} pts");
        $this->io->newLine();

        $ledger = $this->redeemPoints($ledger);
        $this->showAudit($ledger);
        $this->showFinalState($ledger);

        return Command::SUCCESS;
    }

    protected function runDatabaseDemo(): int
    {
        $ledger = $this->loadOrCreateEmpty();

        $ledger = $this->earnFromPurchase($ledger);
        $ledger = $this->maybeRedeem($ledger);

        $this->io->text("Customer balance: {$ledger->totalUnspentAmount()} pts");
        $this->showPointsBreakdown($ledger);
        $this->showStats($ledger);

        return Command::SUCCESS;
    }

    private function earnFromPurchase(LedgerInterface $ledger): LedgerInterface
    {
        $purchaseAmount = random_int(20, 100);

        // Simple API: mints new value to the customer
        $ledger = $ledger->credit('customer', $purchaseAmount, "earn-{$this->runNumber}");

        $this->io->text("Customer bought \${$purchaseAmount} -> earned {$purchaseAmount} pts");
        $this->io->text("Total points minted: {$ledger->totalMinted()}");
        $this->io->newLine();

        return $ledger;
    }

    private function redeemPoints(LedgerInterface $ledger): LedgerInterface
    {
        // Coin Control: Create a voucher (open lock) from Alice's points
        // This demonstrates selecting specific outputs and creating different lock types
        $aliceOutputs = iterator_to_array($ledger->unspentByOwner('alice'));
        $spendIds = array_values(array_map(static fn (Output $o): string => $o->id->value, $aliceOutputs));
        $total = array_sum(array_map(static fn (Output $o): int => $o->amount, $aliceOutputs));

        $ledger = $ledger->apply(Tx::create(
            spendIds: $spendIds,
            outputs: [
                Output::open(60, 'coffee-voucher'),
                Output::ownedBy('alice', $total - 60, 'change'),
            ],
            signedBy: 'alice',
            id: 'redeem-coffee',
        ));

        $this->io->text('Alice redeemed 60 pts for coffee voucher');
        $this->io->text("Remaining: {$ledger->totalUnspentAmount()} pts");
        $this->io->newLine();

        return $ledger;
    }

    private function maybeRedeem(LedgerInterface $ledger): LedgerInterface
    {
        if ($this->runNumber % 3 !== 0) {
            return $ledger;
        }

        $outputs = iterator_to_array($ledger->unspent());
        $customerOutputs = array_filter(
            $outputs,
            static fn (Output $o): bool => ($o->lock->toArray()['name'] ?? '') === 'customer',
        );

        if (\count($customerOutputs) < 2) {
            return $ledger;
        }

        $toRedeem = \array_slice($customerOutputs, 0, 2);
        $total = array_sum(array_map(static fn (Output $o): int => $o->amount, $toRedeem));
        $redeemAmount = (int) ($total * 0.8);
        $change = $total - $redeemAmount;

        $ledger = $ledger->apply(Tx::create(
            spendIds: array_values(array_map(static fn (Output $o): string => $o->id->value, $toRedeem)),
            outputs: [
                Output::open($redeemAmount, "voucher-{$this->runNumber}"),
                Output::ownedBy('customer', $change, "change-{$this->runNumber}"),
            ],
            signedBy: 'customer',
            id: "redeem-{$this->runNumber}",
        ));

        $this->io->text("Redeemed {$redeemAmount} pts for voucher!");
        $this->io->text("Change: {$change} pts");
        $this->io->newLine();

        return $ledger;
    }

    private function showAudit(LedgerInterface $ledger): void
    {
        $this->io->section('Audit Trail');
        $history = $ledger->outputHistory(new OutputId('coffee-voucher'));
        if ($history !== null) {
            $this->io->text("coffee-voucher: created by '{$history->createdBy}' (redeemed from points)");
        }
    }

    private function showFinalState(LedgerInterface $ledger): void
    {
        $this->io->section('Final State');
        foreach ($ledger->unspent() as $id => $output) {
            $lockType = $output->lock->toArray()['type'];
            $this->io->text("  {$id}: {$output->amount} pts ({$lockType})");
        }
    }

    private function showPointsBreakdown(LedgerInterface $ledger): void
    {
        $this->io->section('Points Breakdown');
        foreach ($ledger->unspent() as $id => $output) {
            $owner = $output->lock->toArray()['name'] ?? 'voucher';
            $this->io->text("  {$id}: {$output->amount} pts ({$owner})");
        }
    }
}
