<?php

declare(strict_types=1);

namespace Example\Console;

use Chemaclass\Unspent\Exception\AuthorizationException;
use Chemaclass\Unspent\Exception\OutputAlreadySpentException;
use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\Tx;
use Chemaclass\Unspent\TxId;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

#[AsCommand(
    name: 'sample:virtual-currency',
    description: 'Virtual Currency - In-Game Economy',
    aliases: ['game'],
)]
final class VirtualCurrencyCommand extends AbstractExampleCommand
{
    /** @var list<string> */
    private array $players = ['alice', 'bob', 'charlie', 'shop'];

    protected function runMemoryDemo(): int
    {
        $ledger = $this->loadOrCreate(static fn () => [
            Output::ownedBy('alice', 1000, 'alice-gold'),
            Output::ownedBy('bob', 500, 'bob-gold'),
        ]);
        $this->io->text('Game started: Alice=1000g, Bob=500g');
        $this->io->newLine();

        $ledger = $this->aliceBuysSword($ledger);
        $this->demonstrateTheftBlocked($ledger);
        $this->demonstrateDoubleSpendBlocked($ledger);
        $ledger = $this->bobPaysAlice($ledger);
        $this->showFinalBalances($ledger);

        return Command::SUCCESS;
    }

    protected function runDatabaseDemo(): int
    {
        $ledger = $this->loadOrCreate(static fn () => [
            Output::ownedBy('alice', 1000, 'alice-start'),
            Output::ownedBy('bob', 500, 'bob-start'),
            Output::ownedBy('shop', 5000, 'shop-inventory'),
        ]);

        $ledger = $this->processRandomAction($ledger);
        $this->showBalances($ledger);
        $this->showStats($ledger);

        return Command::SUCCESS;
    }

    private function aliceBuysSword(Ledger $ledger): Ledger
    {
        $ledger = $ledger->apply(Tx::create(
            spendIds: ['alice-gold'],
            outputs: [
                Output::ownedBy('shop', 200, 'shop-payment'),
                Output::ownedBy('alice', 800, 'alice-change'),
            ],
            signedBy: 'alice',
            id: 'buy-sword',
        ));

        $this->io->text('Alice bought sword (-200g), now has 800g');

        return $ledger;
    }

    private function demonstrateTheftBlocked(Ledger $ledger): void
    {
        $this->io->newLine();
        $this->io->text("Mallory tries to steal Bob's gold... ");

        try {
            $ledger->apply(Tx::create(
                spendIds: ['bob-gold'],
                outputs: [Output::ownedBy('mallory', 500)],
                signedBy: 'mallory',
            ));
        } catch (AuthorizationException) {
            $this->io->text('<fg=green>BLOCKED</>');
        }
    }

    private function demonstrateDoubleSpendBlocked(Ledger $ledger): void
    {
        $this->io->text('Alice tries to spend already-spent gold... ');

        try {
            $ledger->apply(Tx::create(
                spendIds: ['alice-gold'],
                outputs: [Output::ownedBy('alice', 1000)],
                signedBy: 'alice',
            ));
        } catch (OutputAlreadySpentException) {
            $this->io->text('<fg=green>BLOCKED</>');
        }
    }

    private function bobPaysAlice(Ledger $ledger): Ledger
    {
        $ledger = $ledger->apply(Tx::create(
            spendIds: ['bob-gold'],
            outputs: [Output::ownedBy('alice', 450)],
            signedBy: 'bob',
            id: 'bob-pays-alice',
        ));

        $this->io->newLine();
        $this->io->text('Bob paid Alice 450g (50g fee/tax)');
        $this->io->text("Fee collected: {$ledger->feeForTx(new TxId('bob-pays-alice'))}g");

        return $ledger;
    }

    private function processRandomAction(Ledger $ledger): Ledger
    {
        $outputs = iterator_to_array($ledger->unspent());
        $playerOutputs = array_filter($outputs, function ($o) {
            $owner = $o->lock->toArray()['name'] ?? '';
            return \in_array($owner, $this->players) && $owner !== 'shop';
        });

        if (empty($playerOutputs)) {
            $this->io->text('No player outputs available!');
            return $ledger;
        }

        $toSpend = $playerOutputs[array_rand($playerOutputs)];
        $owner = $toSpend->lock->toArray()['name'];
        $amount = $toSpend->amount;

        if ($amount < 50) {
            $this->io->text("{$owner} doesn't have enough gold.");
            return $ledger;
        }

        $fee = max(1, (int) ($amount * 0.05));
        $action = rand(0, 1);

        if ($action === 0) {
            return $this->buyFromShop($ledger, $toSpend, $owner, $amount, $fee);
        }

        return $this->tradeWithPlayer($ledger, $toSpend, $owner, $amount, $fee);
    }

    private function buyFromShop(
        Ledger $ledger,
        mixed $toSpend,
        string $owner,
        int $amount,
        int $fee,
    ): Ledger {
        $spend = min(200, (int) ($amount * 0.3));
        $change = $amount - $spend - $fee;

        $ledger = $ledger->apply(Tx::create(
            spendIds: [$toSpend->id->value],
            outputs: [
                Output::ownedBy('shop', $spend, "shop-sale-{$this->runNumber}"),
                Output::ownedBy($owner, $change, "{$owner}-change-{$this->runNumber}"),
            ],
            signedBy: $owner,
            id: "buy-{$this->runNumber}",
        ));

        $this->io->text("{$owner} bought item for {$spend}g (tax: {$fee}g)");

        return $ledger;
    }

    private function tradeWithPlayer(
        Ledger $ledger,
        mixed $toSpend,
        string $owner,
        int $amount,
        int $fee,
    ): Ledger {
        $otherPlayers = array_diff($this->players, [$owner, 'shop']);
        $recipient = $otherPlayers[array_rand($otherPlayers)];
        $send = (int) (($amount - $fee) * 0.5);
        $change = $amount - $send - $fee;

        $ledger = $ledger->apply(Tx::create(
            spendIds: [$toSpend->id->value],
            outputs: [
                Output::ownedBy($recipient, $send, "{$recipient}-from-{$owner}-{$this->runNumber}"),
                Output::ownedBy($owner, $change, "{$owner}-change-{$this->runNumber}"),
            ],
            signedBy: $owner,
            id: "trade-{$this->runNumber}",
        ));

        $this->io->text("{$owner} sent {$send}g to {$recipient} (tax: {$fee}g)");

        return $ledger;
    }

    private function showBalances(Ledger $ledger): void
    {
        $this->io->section('Balances');
        $balances = [];
        foreach ($ledger->unspent() as $output) {
            $owner = $output->lock->toArray()['name'] ?? 'unknown';
            $balances[$owner] = ($balances[$owner] ?? 0) + $output->amount;
        }
        foreach ($balances as $player => $balance) {
            $this->io->text("  {$player}: {$balance}g");
        }
        $this->io->newLine();
        $this->io->text("Total fees collected: {$ledger->totalFeesCollected()}g");
    }

    private function showFinalBalances(Ledger $ledger): void
    {
        $this->io->section('Audit Trail');
        $this->io->text('alice-gold: created at genesis, spent in buy-sword');

        $this->io->section('Final Balances');
        foreach ($ledger->unspent() as $id => $output) {
            $owner = $output->lock->toArray()['name'] ?? 'open';
            $this->io->text("  {$owner}: {$output->amount}g ({$id})");
        }
        $this->io->text("Total in circulation: {$ledger->totalUnspentAmount()}g");
        $this->io->text("Total fees (burned): {$ledger->totalFeesCollected()}g");
    }
}
