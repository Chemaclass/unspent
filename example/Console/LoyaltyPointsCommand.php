<?php

declare(strict_types=1);

namespace Example\Console;

use Chemaclass\Unspent\LedgerInterface;
use Chemaclass\Unspent\Output;
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
    protected function runDemo(): int
    {
        $ledger = $this->loadOrCreateEmpty();

        $ledger = $this->earnFromPurchase($ledger);
        $ledger = $this->maybeRedeem($ledger);

        $this->save($ledger);

        $this->io->text("Customer balance: {$ledger->totalUnspentAmount()} pts");
        $this->showPointsBreakdown($ledger);
        $this->showStats($ledger);

        return Command::SUCCESS;
    }

    private function earnFromPurchase(LedgerInterface $ledger): LedgerInterface
    {
        $purchaseAmount = random_int(20, 100);
        $txNum = $ledger->unspent()->count() + 1;

        // Simple API: mints new value to the customer
        $ledger = $ledger->credit('customer', $purchaseAmount, "earn-{$txNum}");

        $this->io->text("Customer bought \${$purchaseAmount} -> earned {$purchaseAmount} pts");
        $this->io->text("Total points minted: {$ledger->totalMinted()}");
        $this->io->newLine();

        return $ledger;
    }

    private function maybeRedeem(LedgerInterface $ledger): LedgerInterface
    {
        $txNum = $ledger->unspent()->count();
        if ($txNum % 3 !== 0) {
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
                Output::open($redeemAmount, "voucher-{$txNum}"),
                Output::ownedBy('customer', $change, "change-{$txNum}"),
            ],
            signedBy: 'customer',
            id: "redeem-{$txNum}",
        ));

        $this->io->text("Redeemed {$redeemAmount} pts for voucher!");
        $this->io->text("Change: {$change} pts");
        $this->io->newLine();

        return $ledger;
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
