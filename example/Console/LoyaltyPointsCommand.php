<?php

declare(strict_types=1);

namespace Example\Console;

use Chemaclass\Unspent\CoinbaseTx;
use Chemaclass\Unspent\Ledger;
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

        $ledger = $this->earnPoints($ledger, 'alice', 50, 'purchase-001', 'earn-50');
        $this->io->text('Alice bought $50 -> earned 50 pts');

        $ledger = $this->earnPoints($ledger, 'alice', 30, 'purchase-002', 'earn-30');
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

    private function earnPoints(
        Ledger $ledger,
        string $customer,
        int $amount,
        string $outputId,
        string $txId,
    ): Ledger {
        return $ledger->applyCoinbase(CoinbaseTx::create(
            outputs: [Output::ownedBy($customer, $amount, $outputId)],
            id: $txId,
        ));
    }

    private function earnFromPurchase(Ledger $ledger): Ledger
    {
        $purchaseAmount = rand(20, 100);
        $earnId = "purchase-{$this->runNumber}";

        $ledger = $ledger->applyCoinbase(CoinbaseTx::create(
            outputs: [Output::ownedBy('customer', $purchaseAmount, $earnId)],
            id: "earn-{$this->runNumber}",
        ));

        $this->io->text("Customer bought \${$purchaseAmount} -> earned {$purchaseAmount} pts");
        $this->io->text("Total points minted: {$ledger->totalMinted()}");
        $this->io->newLine();

        return $ledger;
    }

    private function redeemPoints(Ledger $ledger): Ledger
    {
        $ledger = $ledger->apply(Tx::create(
            spendIds: ['purchase-001', 'purchase-002'],
            outputs: [
                Output::open(60, 'coffee-voucher'),
                Output::ownedBy('alice', 20, 'change'),
            ],
            signedBy: 'alice',
            id: 'redeem-coffee',
        ));

        $this->io->text('Alice redeemed 60 pts for coffee voucher');
        $this->io->text("Remaining: {$ledger->totalUnspentAmount()} pts");
        $this->io->newLine();

        return $ledger;
    }

    private function maybeRedeem(Ledger $ledger): Ledger
    {
        if ($this->runNumber % 3 !== 0) {
            return $ledger;
        }

        $outputs = iterator_to_array($ledger->unspent());
        $customerOutputs = array_filter(
            $outputs,
            static fn ($o) => ($o->lock->toArray()['name'] ?? '') === 'customer',
        );

        if (\count($customerOutputs) < 2) {
            return $ledger;
        }

        $toRedeem = \array_slice($customerOutputs, 0, 2);
        $total = array_sum(array_map(static fn ($o) => $o->amount, $toRedeem));
        $redeemAmount = (int) ($total * 0.8);
        $change = $total - $redeemAmount;

        $ledger = $ledger->apply(Tx::create(
            spendIds: array_map(static fn ($o) => $o->id->value, $toRedeem),
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

    private function showAudit(Ledger $ledger): void
    {
        $this->io->section('Audit Trail');
        $history = $ledger->outputHistory(new OutputId('purchase-001'));
        $this->io->text('purchase-001: minted in earn-50, spent in redeem-coffee');
    }

    private function showFinalState(Ledger $ledger): void
    {
        $this->io->section('Final State');
        foreach ($ledger->unspent() as $id => $output) {
            $lockType = $output->lock->toArray()['type'];
            $this->io->text("  {$id}: {$output->amount} pts ({$lockType})");
        }
    }

    private function showPointsBreakdown(Ledger $ledger): void
    {
        $this->io->section('Points Breakdown');
        foreach ($ledger->unspent() as $id => $output) {
            $owner = $output->lock->toArray()['name'] ?? 'voucher';
            $this->io->text("  {$id}: {$output->amount} pts ({$owner})");
        }
    }
}
