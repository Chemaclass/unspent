<?php

declare(strict_types=1);

namespace Example\Console;

use Chemaclass\Unspent\Exception\AuthorizationException;
use Chemaclass\Unspent\LedgerInterface;
use Chemaclass\Unspent\Output;
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

    protected function runDemo(): int
    {
        $ledger = $this->loadOrCreate(static fn (): array => [
            Output::ownedBy('alice', 1000, 'alice-start'),
            Output::ownedBy('bob', 500, 'bob-start'),
            Output::ownedBy('shop', 5000, 'shop-inventory'),
        ]);

        $ledger = $this->processRandomAction($ledger);

        $this->save($ledger);

        $this->showBalances($ledger);
        $this->showStats($ledger);

        return Command::SUCCESS;
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
