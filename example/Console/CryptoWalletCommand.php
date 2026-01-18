<?php

declare(strict_types=1);

namespace Example\Console;

use Chemaclass\Unspent\Exception\AuthorizationException;
use Chemaclass\Unspent\LedgerInterface;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\Tx;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

#[AsCommand(
    name: 'sample:crypto-wallet',
    description: 'Crypto Wallet - Ed25519 Signatures',
    aliases: ['wallet'],
)]
final class CryptoWalletCommand extends AbstractExampleCommand
{
    /** @var non-empty-string */
    private string $alicePub;

    /** @var non-empty-string */
    private string $alicePriv;

    /** @var non-empty-string */
    private string $bobPub;

    /** @var non-empty-string */
    private string $bobPriv;

    protected function runDemo(): int
    {
        $this->generateKeys();

        $ledger = $this->loadOrCreate(fn (): array => [
            Output::signedBy($this->alicePub, 1000, 'alice-wallet'),
            Output::signedBy($this->bobPub, 500, 'bob-wallet'),
        ]);

        $ledger = $this->processRandomTransfer($ledger);
        $this->demonstrateMalloryAttack($ledger);

        $this->save($ledger);

        $this->showFinalBalances($ledger);
        $this->showStats($ledger);

        return Command::SUCCESS;
    }

    private function generateKeys(): void
    {
        $aliceKp = sodium_crypto_sign_keypair();
        $this->alicePub = base64_encode(sodium_crypto_sign_publickey($aliceKp));
        $this->alicePriv = sodium_crypto_sign_secretkey($aliceKp);

        $bobKp = sodium_crypto_sign_keypair();
        $this->bobPub = base64_encode(sodium_crypto_sign_publickey($bobKp));
        $this->bobPriv = sodium_crypto_sign_secretkey($bobKp);

        $this->io->section('Keys Generated');
        $this->io->listing([
            'Alice: ' . substr($this->alicePub, 0, 16) . '...',
            'Bob: ' . substr($this->bobPub, 0, 16) . '...',
        ]);
    }

    private function processRandomTransfer(LedgerInterface $ledger): LedgerInterface
    {
        $outputs = iterator_to_array($ledger->unspent());
        if ($outputs === []) {
            $this->io->text('No outputs to spend!');

            return $ledger;
        }

        $toSpend = $outputs[array_rand($outputs)];
        $amount = $toSpend->amount;

        if ($amount < 100) {
            $this->io->text('Output too small to split.');

            return $ledger;
        }

        $fee = max(1, (int) ($amount * 0.01));
        $transfer = (int) (($amount - $fee) * 0.4);
        $change = $amount - $fee - $transfer;

        $txNum = $ledger->unspent()->count();
        $txId = "tx-{$txNum}";
        $lockData = $toSpend->lock->toArray();
        /** @var string $pubKey */
        $pubKey = $lockData['key'] ?? ''; // @phpstan-ignore nullCoalesce.offset
        $isAlice = $pubKey === $this->alicePub;
        $privKey = $isAlice ? $this->alicePriv : $this->bobPriv;
        $recipientPub = $isAlice ? $this->bobPub : $this->alicePub;
        $ownerName = $isAlice ? 'Alice' : 'Bob';
        $recipientName = $isAlice ? 'Bob' : 'Alice';

        $sig = base64_encode(sodium_crypto_sign_detached($txId, $privKey));

        $ledger->apply(Tx::create(
            spendIds: [$toSpend->id->value],
            outputs: [
                Output::signedBy($recipientPub, $transfer, "to-{$recipientName}-{$txNum}"),
                Output::signedBy($pubKey, $change, "change-{$ownerName}-{$txNum}"),
            ],
            id: $txId,
            proofs: [$sig],
        ));

        $this->io->text("{$ownerName} -> {$recipientName}: {$transfer} (signed)");
        $this->io->listing([
            "From: {$toSpend->id->value} ({$amount})",
            "Fee: {$fee}",
        ]);

        return $ledger;
    }

    private function demonstrateMalloryAttack(LedgerInterface $ledger): void
    {
        $this->io->newLine();
        $this->io->text('Mallory tries to steal with wrong key... ');

        $malloryKp = sodium_crypto_sign_keypair();
        $malloryPriv = sodium_crypto_sign_secretkey($malloryKp);

        $outputs = iterator_to_array($ledger->unspent());
        if ($outputs === []) {
            $this->io->text('No outputs available');

            return;
        }

        $targetOutput = $outputs[array_rand($outputs)];
        $fakeSig = base64_encode(sodium_crypto_sign_detached('tx-steal', $malloryPriv));

        try {
            $ledger->apply(Tx::create(
                spendIds: [$targetOutput->id->value],
                outputs: [Output::open($targetOutput->amount)],
                id: 'tx-steal',
                proofs: [$fakeSig],
            ));
        } catch (AuthorizationException) {
            $this->io->text('<fg=green>BLOCKED</>');
        }
    }

    private function showFinalBalances(LedgerInterface $ledger): void
    {
        $this->io->section('Final Balances');
        foreach ($ledger->unspent() as $id => $output) {
            $this->io->text("  {$id}: {$output->amount}");
        }
    }
}
