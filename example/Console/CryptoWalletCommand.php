<?php

declare(strict_types=1);

namespace Example\Console;

use Chemaclass\Unspent\Exception\AuthorizationException;
use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
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

    protected function runMemoryDemo(): int
    {
        $this->generateKeys();

        $ledger = $this->loadOrCreate(fn (): array => [
            Output::signedBy($this->alicePub, 1000, 'alice-wallet'),
            Output::signedBy($this->bobPub, 500, 'bob-wallet'),
        ]);
        $this->io->text('Wallets: Alice=1000, Bob=500');
        $this->io->newLine();

        $ledger = $this->aliceSendsToBob($ledger);
        $this->demonstrateMalloryAttack($ledger);
        $ledger = $this->bobCombinesOutputs($ledger);
        $this->showHistory($ledger);
        $this->showFinalBalances($ledger);

        return Command::SUCCESS;
    }

    protected function runDatabaseDemo(): int
    {
        $this->generateKeys();

        $ledger = $this->loadOrCreate(fn (): array => [
            Output::signedBy($this->alicePub, 1000, 'alice-wallet'),
            Output::signedBy($this->bobPub, 500, 'bob-wallet'),
        ]);

        $ledger = $this->processRandomTransfer($ledger);
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

    private function aliceSendsToBob(Ledger $ledger): Ledger
    {
        $txId = 'tx-001';
        $sig = base64_encode(sodium_crypto_sign_detached($txId, $this->alicePriv));

        $ledger = $ledger->apply(Tx::create(
            spendIds: ['alice-wallet'],
            outputs: [
                Output::signedBy($this->bobPub, 300, 'bob-received'),
                Output::signedBy($this->alicePub, 700, 'alice-change'),
            ],
            id: $txId,
            proofs: [$sig],
        ));

        $this->io->text('Alice -> Bob: 300 (signed)');

        return $ledger;
    }

    private function demonstrateMalloryAttack(Ledger $ledger): void
    {
        $this->io->newLine();
        $this->io->text('Mallory tries to steal with wrong key... ');

        $malloryKp = sodium_crypto_sign_keypair();
        $malloryPriv = sodium_crypto_sign_secretkey($malloryKp);
        $fakeSig = base64_encode(sodium_crypto_sign_detached('tx-steal', $malloryPriv));

        try {
            $ledger->apply(Tx::create(
                spendIds: ['bob-wallet'],
                outputs: [Output::open(500)],
                id: 'tx-steal',
                proofs: [$fakeSig],
            ));
        } catch (AuthorizationException) {
            $this->io->text('<fg=green>BLOCKED</>');
        }
    }

    private function bobCombinesOutputs(Ledger $ledger): Ledger
    {
        $txId2 = 'tx-002';
        $bobSig1 = base64_encode(sodium_crypto_sign_detached($txId2, $this->bobPriv));
        $bobSig2 = base64_encode(sodium_crypto_sign_detached($txId2, $this->bobPriv));

        $ledger = $ledger->apply(Tx::create(
            spendIds: ['bob-wallet', 'bob-received'],
            outputs: [Output::signedBy($this->bobPub, 800, 'bob-combined')],
            id: $txId2,
            proofs: [$bobSig1, $bobSig2],
        ));

        $this->io->newLine();
        $this->io->text('Bob combined 500+300 = 800 (multi-sig)');

        return $ledger;
    }

    private function processRandomTransfer(Ledger $ledger): Ledger
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

        $txId = "tx-{$this->runNumber}";
        $lockData = $toSpend->lock->toArray();
        /** @var string $pubKey */
        $pubKey = $lockData['key'] ?? ''; // @phpstan-ignore nullCoalesce.offset
        $isAlice = $pubKey === $this->alicePub;
        $privKey = $isAlice ? $this->alicePriv : $this->bobPriv;
        $recipientPub = $isAlice ? $this->bobPub : $this->alicePub;
        $ownerName = $isAlice ? 'Alice' : 'Bob';
        $recipientName = $isAlice ? 'Bob' : 'Alice';

        $sig = base64_encode(sodium_crypto_sign_detached($txId, $privKey));

        $ledger = $ledger->apply(Tx::create(
            spendIds: [$toSpend->id->value],
            outputs: [
                Output::signedBy($recipientPub, $transfer, "to-{$recipientName}-{$this->runNumber}"),
                Output::signedBy($pubKey, $change, "change-{$ownerName}-{$this->runNumber}"),
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

    private function showHistory(Ledger $ledger): void
    {
        $this->io->section('History');
        $history = $ledger->outputHistory(new OutputId('bob-combined'));
        $this->io->text("bob-combined: created by {$history?->createdBy}");
    }

    private function showFinalBalances(Ledger $ledger): void
    {
        $this->io->section('Final Balances');
        foreach ($ledger->unspent() as $id => $output) {
            $this->io->text("  {$id}: {$output->amount}");
        }
    }
}
