<?php

declare(strict_types=1);

namespace Example\Console;

use Chemaclass\Unspent\Exception\AuthorizationException;
use Chemaclass\Unspent\InMemoryLedger;
use Chemaclass\Unspent\Lock\LockFactory;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\OutputLock;
use Chemaclass\Unspent\Tx;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'sample:custom-locks',
    description: 'Custom Locks - Time-Locked Outputs',
    aliases: ['locks'],
)]
final class CustomLocksCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Custom Locks - Time-Locked Outputs');

        $this->registerTimeLockHandler($io);
        $ledger = $this->createLedger();
        $restored = $this->serializeAndRestore($io, $ledger);
        $this->spendUnlockedOutput($io, $restored);
        $this->demonstrateLockedBlocked($io, $restored);
        $this->demonstrateWrongSignerBlocked($io);

        LockFactory::reset();

        return Command::SUCCESS;
    }

    private function registerTimeLockHandler(SymfonyStyle $io): void
    {
        LockFactory::register('timelock', static fn ($data): TimeLock => new TimeLock(
            $data['unlockTime'],
            $data['owner'],
        ));
        $io->text("Registered 'timelock' handler");
        $io->newLine();
    }

    private function createLedger(): InMemoryLedger
    {
        return InMemoryLedger::withGenesis(
            Output::lockedWith(new TimeLock(strtotime('2020-01-01'), 'alice'), 1000, 'unlocked'),
            Output::lockedWith(new TimeLock(strtotime('+1 year'), 'bob'), 500, 'still-locked'),
        );
    }

    private function serializeAndRestore(SymfonyStyle $io, InMemoryLedger $ledger): InMemoryLedger
    {
        $json = $ledger->toJson();
        $restored = InMemoryLedger::fromJson($json);

        $output = $restored->unspent()->get(new OutputId('unlocked'));
        $io->text('Restored lock type: ' . $output?->lock::class);
        $io->newLine();

        return $restored;
    }

    private function spendUnlockedOutput(SymfonyStyle $io, InMemoryLedger $ledger): void
    {
        $ledger->apply(Tx::create(
            spendIds: ['unlocked'],
            outputs: [Output::ownedBy('charlie', 1000)],
            signedBy: 'alice',
        ));
        $io->text('Alice spent unlocked funds');
    }

    private function demonstrateLockedBlocked(SymfonyStyle $io, InMemoryLedger $ledger): void
    {
        $io->text('Bob tries to spend locked output... ');

        try {
            $ledger->apply(Tx::create(
                spendIds: ['still-locked'],
                outputs: [Output::open(500)],
                signedBy: 'bob',
            ));
        } catch (RuntimeException $e) {
            $io->text('<fg=green>BLOCKED</>: ' . $e->getMessage());
        }
    }

    private function demonstrateWrongSignerBlocked(SymfonyStyle $io): void
    {
        $io->text("Eve tries to spend Alice's output... ");

        $ledger2 = InMemoryLedger::withGenesis(
            Output::lockedWith(new TimeLock(strtotime('2020-01-01'), 'alice'), 100, 'alice-funds'),
        );

        try {
            $ledger2->apply(Tx::create(
                spendIds: ['alice-funds'],
                outputs: [Output::open(100)],
                signedBy: 'eve',
            ));
        } catch (AuthorizationException) {
            $io->text('<fg=green>BLOCKED</>');
        }
    }
}

/**
 * Custom time-lock implementation for demonstration.
 */
final readonly class TimeLock implements OutputLock
{
    public function __construct(
        public int $unlockTime,
        public string $owner,
    ) {
    }

    public function validate(Tx $tx, int $inputIndex): void
    {
        if (time() < $this->unlockTime) {
            throw new RuntimeException('Still locked until ' . date('Y-m-d', $this->unlockTime));
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
}
