<?php

declare(strict_types=1);

namespace Example\Console;

use Chemaclass\Unspent\CoinbaseTx;
use Chemaclass\Unspent\LedgerInterface;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\Tx;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

#[AsCommand(
    name: 'sample:bitcoin-simulation',
    description: 'Bitcoin Simulation - Multi-Block Mining',
    aliases: ['btc'],
)]
final class BitcoinSimulationCommand extends AbstractExampleCommand
{
    private const int SATOSHIS_PER_BTC = 100_000_000;
    private const int BLOCK_REWARD = 50 * self::SATOSHIS_PER_BTC;

    protected function runDemo(): int
    {
        $ledger = $this->loadOrCreate(static fn (): array => [Output::open(self::BLOCK_REWARD, 'satoshi-genesis')]);

        $blockNum = $ledger->unspent()->count();
        $this->io->section("Mining Block #{$blockNum}");

        $ledger = $this->processTransaction($ledger, $blockNum);
        $ledger = $this->mineCoinbase($ledger, $blockNum);

        $this->save($ledger);

        $this->displayBlockchainState($ledger);
        $this->showStats($ledger);

        return Command::SUCCESS;
    }

    private function processTransaction(LedgerInterface $ledger, int $blockNum): LedgerInterface
    {
        $outputs = iterator_to_array($ledger->unspent());
        usort($outputs, static fn ($a, $b): int => $b->amount <=> $a->amount);

        if ($outputs === [] || $outputs[0]->amount < 1 * self::SATOSHIS_PER_BTC) {
            return $ledger;
        }

        $toSpend = $outputs[0];
        $amount = $toSpend->amount;
        $fee = max(1000000, (int) ($amount * 0.001));
        $send = (int) (($amount - $fee) * 0.3);
        $change = $amount - $fee - $send;

        $txId = "tx-block-{$blockNum}";
        $ledger->apply(Tx::create(
            spendIds: [$toSpend->id->value],
            outputs: [
                Output::open($send, "recipient-{$blockNum}"),
                Output::open($change, "change-{$blockNum}"),
            ],
            id: $txId,
        ));

        $this->io->text("Transaction: {$toSpend->id->value} -> recipient-{$blockNum}");
        $this->io->listing([
            'Sent: ' . $this->formatBtc($send),
            'Change: ' . $this->formatBtc($change),
            'Fee: ' . $this->formatBtc($fee),
        ]);

        return $ledger;
    }

    private function mineCoinbase(LedgerInterface $ledger, int $blockNum): LedgerInterface
    {
        $ledger->applyCoinbase(CoinbaseTx::create(
            [Output::open(self::BLOCK_REWARD, "miner-{$blockNum}")],
            "coinbase-block-{$blockNum}",
        ));

        $this->io->text('Mined: ' . $this->formatBtc(self::BLOCK_REWARD) . " -> miner-{$blockNum}");
        $this->io->newLine();

        return $ledger;
    }

    private function displayBlockchainState(LedgerInterface $ledger): void
    {
        $this->io->section('Blockchain State');
        $this->io->listing([
            'Total minted: ' . $this->formatBtc($ledger->totalMinted()),
            'Total fees: ' . $this->formatBtc($ledger->totalFeesCollected()),
            'In circulation: ' . $this->formatBtc($ledger->totalUnspentAmount()),
            'UTXOs: ' . $ledger->unspent()->count(),
        ]);
    }

    private function formatBtc(int $satoshis): string
    {
        return ($satoshis / self::SATOSHIS_PER_BTC) . ' BTC';
    }
}
