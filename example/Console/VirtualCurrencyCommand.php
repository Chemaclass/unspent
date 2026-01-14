<?php

declare(strict_types=1);

namespace Example\Console;

use Chemaclass\Unspent\CoinbaseTx;
use Chemaclass\Unspent\Exception\AuthorizationException;
use Chemaclass\Unspent\Exception\OutputAlreadySpentException;
use Chemaclass\Unspent\LedgerInterface;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\OutputLock;
use Chemaclass\Unspent\Tx;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

#[AsCommand(
    name: 'sample:virtual-currency',
    description: 'Virtual Currency - In-Game Economy (Flagship Demo)',
    aliases: ['game'],
)]
final class VirtualCurrencyCommand extends AbstractExampleCommand
{
    /** @var list<string> */
    private array $players = ['alice', 'bob', 'charlie', 'shop'];

    protected function runMemoryDemo(): int
    {
        $ledger = $this->loadOrCreate(static fn (): array => [
            Output::ownedBy('alice', 1000, 'alice-gold'),
            Output::ownedBy('bob', 500, 'bob-gold'),
        ]);
        $this->io->text('Game started: Alice=1000g, Bob=500g');
        $this->io->newLine();

        // Minting demo
        $ledger = $this->mintDailyBonus($ledger);

        // Purchase demo
        $ledger = $this->aliceBuysSword($ledger);

        // Security demos
        $this->demonstrateTheftBlocked($ledger);
        $this->demonstrateDoubleSpendBlocked($ledger);

        // Quest reward with timelock
        $ledger = $this->grantQuestReward($ledger);
        $this->demonstrateTimelockBlocked($ledger);

        // Trade with fee
        $ledger = $this->bobPaysAlice($ledger);

        // History tracing
        $this->demonstrateHistoryTracing($ledger);

        // Final state
        $this->showFinalBalances($ledger);

        return Command::SUCCESS;
    }

    protected function runDatabaseDemo(): int
    {
        $ledger = $this->loadOrCreate(static fn (): array => [
            Output::ownedBy('alice', 1000, 'alice-start'),
            Output::ownedBy('bob', 500, 'bob-start'),
            Output::ownedBy('shop', 5000, 'shop-inventory'),
        ]);

        $ledger = $this->processRandomAction($ledger);
        $this->showBalances($ledger);
        $this->showStats($ledger);

        return Command::SUCCESS;
    }

    private function mintDailyBonus(LedgerInterface $ledger): LedgerInterface
    {
        $this->io->section('Minting');

        $ledger = $ledger->applyCoinbase(CoinbaseTx::create(
            outputs: [Output::ownedBy('alice', 100, 'daily-bonus')],
            id: 'mint-daily-bonus',
        ));

        $this->io->text('Admin minted 100g daily bonus for Alice');
        $this->io->text("Total minted so far: {$ledger->totalMinted()}g");

        return $ledger;
    }

    private function aliceBuysSword(LedgerInterface $ledger): LedgerInterface
    {
        $this->io->section('Purchase');

        $ledger = $ledger->apply(Tx::create(
            spendIds: ['alice-gold'],
            outputs: [
                Output::ownedBy('shop', 200, 'shop-payment'),
                Output::ownedBy('alice', 800, 'alice-change'),
            ],
            signedBy: 'alice',
            id: 'buy-sword',
        ));

        $this->io->text('Alice bought sword (-200g), now has 900g total');

        return $ledger;
    }

    private function demonstrateTheftBlocked(LedgerInterface $ledger): void
    {
        $this->io->section('Security');
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

    private function demonstrateDoubleSpendBlocked(LedgerInterface $ledger): void
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

    private function grantQuestReward(LedgerInterface $ledger): LedgerInterface
    {
        $this->io->section('Quest Reward');

        $ledger = $ledger->applyCoinbase(CoinbaseTx::create(
            outputs: [
                Output::lockedWith(
                    new GameTimeLock(time() + 3600, 'alice'),
                    500,
                    'quest-reward',
                ),
            ],
            id: 'quest-complete',
        ));

        $this->io->text('Alice completed quest! Reward: 500g (locked for 1 hour)');

        return $ledger;
    }

    private function demonstrateTimelockBlocked(LedgerInterface $ledger): void
    {
        $this->io->text('Alice tries to spend locked reward... ');

        try {
            $ledger->apply(Tx::create(
                spendIds: ['quest-reward'],
                outputs: [Output::ownedBy('alice', 500)],
                signedBy: 'alice',
            ));
        } catch (RuntimeException) {
            $this->io->text('<fg=green>BLOCKED</> (cooldown active)');
        }
    }

    private function bobPaysAlice(LedgerInterface $ledger): LedgerInterface
    {
        $this->io->section('Trade');

        // Simple API: auto-selects Bob's outputs, handles change, applies fee
        $ledger = $ledger->transfer('bob', 'alice', 450, fee: 50);

        $this->io->text('Bob paid Alice 450g (50g fee/tax)');
        $this->io->text('Fee collected: 50g');

        return $ledger;
    }

    private function demonstrateHistoryTracing(LedgerInterface $ledger): void
    {
        $this->io->section('History Tracing');

        $history = $ledger->outputHistory(new OutputId('alice-change'));
        if ($history !== null) {
            $this->io->text("alice-change: created by '{$history->createdBy}'");
        }

        $history2 = $ledger->outputHistory(new OutputId('daily-bonus'));
        if ($history2 !== null) {
            $this->io->text("daily-bonus: created by '{$history2->createdBy}' (minted)");
        }
    }

    private function processRandomAction(LedgerInterface $ledger): LedgerInterface
    {
        // Find players with available balance
        $playerBalances = [];
        foreach ($this->players as $player) {
            if ($player === 'shop') {
                continue;
            }
            $balance = $ledger->totalUnspentByOwner($player);
            if ($balance >= 50) {
                $playerBalances[$player] = $balance;
            }
        }

        if ($playerBalances === []) {
            $this->io->text('No player has enough gold!');

            return $ledger;
        }

        // Pick a random player with sufficient funds
        $players = array_keys($playerBalances);
        $owner = $players[array_rand($players)];
        $amount = $playerBalances[$owner];
        $fee = max(1, (int) ($amount * 0.05));
        $action = random_int(0, 1);

        if ($action === 0) {
            return $this->buyFromShop($ledger, $owner, $amount, $fee);
        }

        return $this->tradeWithPlayer($ledger, $owner, $amount, $fee);
    }

    private function buyFromShop(
        LedgerInterface $ledger,
        string $owner,
        int $amount,
        int $fee,
    ): LedgerInterface {
        $spend = min(200, (int) ($amount * 0.3));

        // Simple API: handles output selection, change, and fees automatically
        $ledger = $ledger->transfer($owner, 'shop', $spend, fee: $fee);

        $this->io->text("{$owner} bought item for {$spend}g (tax: {$fee}g)");

        return $ledger;
    }

    private function tradeWithPlayer(
        LedgerInterface $ledger,
        string $owner,
        int $amount,
        int $fee,
    ): LedgerInterface {
        $otherPlayers = array_diff($this->players, [$owner, 'shop']);
        $recipient = $otherPlayers[array_rand($otherPlayers)];
        $send = (int) (($amount - $fee) * 0.5);

        // Simple API: handles output selection, change, and fees automatically
        $ledger = $ledger->transfer($owner, $recipient, $send, fee: $fee);

        $this->io->text("{$owner} sent {$send}g to {$recipient} (tax: {$fee}g)");

        return $ledger;
    }

    private function showBalances(LedgerInterface $ledger): void
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

    private function showFinalBalances(LedgerInterface $ledger): void
    {
        $this->io->section('Final State');

        // Calculate balances by owner
        $balances = [];
        foreach ($ledger->unspent() as $output) {
            $lockData = $output->lock->toArray();
            /** @var string $owner */
            $owner = $lockData['name'] ?? $lockData['owner'] ?? 'unknown'; // @phpstan-ignore nullCoalesce.offset
            $balances[$owner] = ($balances[$owner] ?? 0) + $output->amount;
        }

        foreach ($balances as $player => $balance) {
            $this->io->text("  {$player}: {$balance}g");
        }

        $this->io->newLine();
        $this->io->text("Total in circulation: {$ledger->totalUnspentAmount()}g");
        $this->io->text("Total fees (burned): {$ledger->totalFeesCollected()}g");
        $this->io->text("Total minted: {$ledger->totalMinted()}g");
        $this->io->text("UTXOs: {$ledger->unspent()->count()}");
    }
}

/**
 * Time-locked output for quest rewards with cooldown periods.
 */
final readonly class GameTimeLock implements OutputLock
{
    public function __construct(
        public int $unlockTime,
        public string $owner,
    ) {
    }

    public function validate(Tx $tx, int $inputIndex): void
    {
        if (time() < $this->unlockTime) {
            $remaining = $this->unlockTime - time();
            throw new RuntimeException("Locked for {$remaining} more seconds");
        }
        if ($tx->signedBy !== $this->owner) {
            throw AuthorizationException::notOwner($this->owner, $tx->signedBy);
        }
    }

    public function toArray(): array
    {
        return [
            'type' => 'timelock',
            'unlockTime' => $this->unlockTime,
            'owner' => $this->owner,
        ];
    }

    public function type(): string
    {
        return 'timelock';
    }
}
