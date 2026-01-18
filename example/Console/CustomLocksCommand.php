<?php

declare(strict_types=1);

namespace Example\Console;

use Chemaclass\Unspent\Exception\AuthorizationException;
use Chemaclass\Unspent\LedgerInterface;
use Chemaclass\Unspent\Lock\LockFactory;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputLock;
use Chemaclass\Unspent\Tx;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

#[AsCommand(
    name: 'sample:custom-locks',
    description: 'Custom Locks - Time-Locked Outputs',
    aliases: ['locks'],
)]
final class CustomLocksCommand extends AbstractExampleCommand
{
    protected function runDemo(): int
    {
        $this->registerTimeLockHandler();

        $ledger = $this->loadOrCreate(static fn (): array => [
            Output::lockedWith(new TimeLock(strtotime('2020-01-01'), 'alice'), 1000, 'alice-unlocked'),
            Output::lockedWith(new TimeLock(strtotime('+1 year'), 'bob'), 500, 'bob-locked'),
        ]);

        $ledger = $this->processRandomAction($ledger);

        $this->save($ledger);

        $this->demonstrateSecurityBlocks($ledger);
        $this->showCurrentState($ledger);
        $this->showStats($ledger);

        LockFactory::reset();

        return Command::SUCCESS;
    }

    private function registerTimeLockHandler(): void
    {
        LockFactory::register('timelock', static fn (array $data): TimeLock => new TimeLock(
            $data['unlockTime'],
            $data['owner'],
        ));
        $this->io->text("Registered 'timelock' handler");
        $this->io->newLine();
    }

    private function processRandomAction(LedgerInterface $ledger): LedgerInterface
    {
        // Find unlocked outputs that can be spent
        $spendable = [];
        $now = time();

        foreach ($ledger->unspent() as $output) {
            $lockData = $output->lock->toArray();
            // @phpstan-ignore nullCoalesce.offset, offsetAccess.notFound
            if (($lockData['type'] ?? '') === 'timelock' && $lockData['unlockTime'] <= $now) {
                $spendable[] = $output;
            }
        }

        if ($spendable === []) {
            $this->io->text('No unlocked outputs available to spend.');
            $this->io->newLine();

            // Create a new time-locked output for demonstration
            $txNum = $ledger->unspent()->count();
            $owner = ['alice', 'bob', 'charlie'][array_rand(['alice', 'bob', 'charlie'])];
            $unlockTime = random_int(0, 1) === 0 ? strtotime('2020-01-01') : strtotime('+1 year');
            $amount = random_int(100, 500);

            $ledger = $ledger->credit($owner, $amount, "{$owner}-timelock-{$txNum}");

            // Convert to time-locked
            $newOutputs = iterator_to_array($ledger->unspentByOwner($owner));
            if ($newOutputs !== []) {
                $toConvert = $newOutputs[array_key_last($newOutputs)];
                $ledger = $ledger->apply(Tx::create(
                    spendIds: [$toConvert->id->value],
                    outputs: [Output::lockedWith(
                        new TimeLock($unlockTime, $owner),
                        $toConvert->amount,
                        "{$owner}-locked-{$txNum}",
                    )],
                    signedBy: $owner,
                    id: "lock-{$txNum}",
                ));
                $status = $unlockTime <= $now ? 'unlocked' : 'locked until ' . date('Y-m-d', $unlockTime);
                $this->io->text("Created {$owner}'s time-locked output ({$status})");
            }

            return $ledger;
        }

        // Spend a random unlocked output
        $toSpend = $spendable[array_rand($spendable)];
        $lockData = $toSpend->lock->toArray();
        $owner = $lockData['owner'] ?? 'unknown'; // @phpstan-ignore nullCoalesce.offset
        $txNum = $ledger->unspent()->count();

        $recipients = array_diff(['alice', 'bob', 'charlie'], [$owner]);
        $recipient = $recipients[array_rand($recipients)];

        $ledger = $ledger->apply(Tx::create(
            spendIds: [$toSpend->id->value],
            outputs: [Output::ownedBy($recipient, $toSpend->amount, "{$recipient}-from-{$owner}-{$txNum}")],
            signedBy: $owner,
            id: "spend-{$txNum}",
        ));

        $this->io->text("{$owner} spent unlocked funds -> {$recipient}");

        return $ledger;
    }

    private function demonstrateSecurityBlocks(LedgerInterface $ledger): void
    {
        $this->io->section('Security Demonstrations');

        // Find a locked output
        $now = time();
        foreach ($ledger->unspent() as $output) {
            $lockData = $output->lock->toArray();
            // @phpstan-ignore nullCoalesce.offset, offsetAccess.notFound
            if (($lockData['type'] ?? '') === 'timelock' && $lockData['unlockTime'] > $now) {
                $owner = $lockData['owner'];
                $this->io->text("{$owner} tries to spend locked output... ");

                try {
                    $ledger->apply(Tx::create(
                        spendIds: [$output->id->value],
                        outputs: [Output::open($output->amount)],
                        signedBy: $owner,
                    ));
                } catch (RuntimeException $e) {
                    $this->io->text('<fg=green>BLOCKED</>: ' . $e->getMessage());
                }
                break;
            }
        }

        // Wrong signer attempt
        foreach ($ledger->unspent() as $output) {
            $lockData = $output->lock->toArray();
            // @phpstan-ignore nullCoalesce.offset, offsetAccess.notFound
            if (($lockData['type'] ?? '') === 'timelock' && $lockData['unlockTime'] <= $now) {
                $owner = $lockData['owner'];
                $this->io->text("Eve tries to spend {$owner}'s output... ");

                try {
                    $ledger->apply(Tx::create(
                        spendIds: [$output->id->value],
                        outputs: [Output::open($output->amount)],
                        signedBy: 'eve',
                    ));
                } catch (AuthorizationException) {
                    $this->io->text('<fg=green>BLOCKED</>');
                }
                break;
            }
        }
    }

    private function showCurrentState(LedgerInterface $ledger): void
    {
        $this->io->section('Time-Locked Outputs');

        $now = time();
        foreach ($ledger->unspent() as $id => $output) {
            $lockData = $output->lock->toArray();
            $type = $lockData['type'] ?? 'standard'; // @phpstan-ignore nullCoalesce.offset

            if ($type === 'timelock') {
                // @phpstan-ignore offsetAccess.notFound
                $owner = $lockData['owner'];
                // @phpstan-ignore offsetAccess.notFound
                $unlockTime = $lockData['unlockTime'];
                $status = $unlockTime <= $now ? '<fg=green>UNLOCKED</>' : '<fg=yellow>locked until ' . date('Y-m-d', $unlockTime) . '</>';
                $this->io->text("  {$id}: {$output->amount} ({$owner}) - {$status}");
            } else {
                $owner = $lockData['name'] ?? 'open';
                $this->io->text("  {$id}: {$output->amount} ({$owner})");
            }
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

    public function type(): string
    {
        return 'timelock';
    }
}
