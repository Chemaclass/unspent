<?php

declare(strict_types=1);

namespace Example\Console;

use Chemaclass\Unspent\CoinbaseTx;
use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\Tx;
use Chemaclass\Unspent\TxId;
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

    protected function runMemoryDemo(): int
    {
        $ledger = $this->loadOrCreate(fn() => [Output::open(self::BLOCK_REWARD, 'satoshi-0')]);
        $this->io->text('Block 0: Satoshi mines 50 BTC');

        $ledger = $this->mineBlock($ledger, 1);
        $this->io->text('Block 1: Satoshi mines 50 BTC (total: 100 BTC)');
        $this->io->newLine();

        $ledger = $this->applyTransaction($ledger, 'satoshi-0', 'hal-funds', 10 * self::SATOSHIS_PER_BTC);
        $ledger = $this->mineBlock($ledger, 2);
        $this->io->text('Block 2: Satoshi sends 10 BTC to Hal');
        $this->io->text('  Fee: ' . $this->formatBtc($ledger->feeForTx(new TxId('tx-to-hal'))));
        $this->io->newLine();

        $ledger = $this->applyTransaction($ledger, 'hal-funds', 'laszlo-pizza', 5 * self::SATOSHIS_PER_BTC, 'tx-pizza', 'hal-change');
        $ledger = $this->mineBlock($ledger, 3);
        $this->io->text('Block 3: Hal buys pizza for 5 BTC');
        $this->io->newLine();

        $ledger = $this->consolidateOutputs($ledger);
        $ledger = $this->mineBlock($ledger, 4);
        $this->io->text('Block 4: Satoshi consolidates 3 UTXOs into 1');
        $this->io->newLine();

        $this->displayFinalState($ledger);

        return Command::SUCCESS;
    }

    protected function runDatabaseDemo(): int
    {
        $ledger = $this->loadOrCreate(fn() => [Output::open(self::BLOCK_REWARD, 'satoshi-genesis')]);

        $blockNum = $this->runNumber;
        $this->io->section("Mining Block #{$blockNum}");

        $ledger = $this->processTransaction($ledger, $blockNum);
        $ledger = $this->mineCoinbase($ledger, $blockNum);

        $this->displayBlockchainState($ledger);
        $this->showStats($ledger);

        return Command::SUCCESS;
    }

    private function mineBlock(Ledger $ledger, int $blockNum, string $prefix = 'satoshi'): Ledger
    {
        $outputId = $blockNum >= 2 ? "miner-{$blockNum}" : "{$prefix}-{$blockNum}";
        return $ledger->applyCoinbase(CoinbaseTx::create(
            [Output::open(self::BLOCK_REWARD, $outputId)],
            "block-{$blockNum}",
        ));
    }

    private function applyTransaction(
        Ledger $ledger,
        string $fromId,
        string $toId,
        int $amount,
        string $txId = 'tx-to-hal',
        string $changeId = 'satoshi-change',
    ): Ledger {
        $output = $ledger->unspent()->get(new OutputId($fromId));
        if ($output === null) {
            return $ledger;
        }

        $fromAmount = $output->amount;
        $change = $fromAmount - $amount - 1000; // small fee

        return $ledger->apply(Tx::create(
            spendIds: [$fromId],
            outputs: [
                Output::open($amount, $toId),
                Output::open($change, $changeId),
            ],
            id: $txId,
        ));
    }

    private function consolidateOutputs(Ledger $ledger): Ledger
    {
        return $ledger->apply(Tx::create(
            spendIds: ['satoshi-1', 'satoshi-change', 'miner-2'],
            outputs: [Output::open(139_98000000, 'satoshi-consolidated')],
            id: 'tx-consolidate',
        ));
    }

    private function processTransaction(Ledger $ledger, int $blockNum): Ledger
    {
        $outputs = iterator_to_array($ledger->unspent());
        usort($outputs, fn($a, $b) => $b->amount <=> $a->amount);

        if (empty($outputs) || $outputs[0]->amount < 1 * self::SATOSHIS_PER_BTC) {
            return $ledger;
        }

        $toSpend = $outputs[0];
        $amount = $toSpend->amount;
        $fee = max(1000000, (int) ($amount * 0.001));
        $send = (int) (($amount - $fee) * 0.3);
        $change = $amount - $fee - $send;

        $txId = "tx-block-{$blockNum}";
        $ledger = $ledger->apply(Tx::create(
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

    private function mineCoinbase(Ledger $ledger, int $blockNum): Ledger
    {
        $ledger = $ledger->applyCoinbase(CoinbaseTx::create(
            [Output::open(self::BLOCK_REWARD, "miner-{$blockNum}")],
            "coinbase-block-{$blockNum}",
        ));

        $this->io->text('Mined: ' . $this->formatBtc(self::BLOCK_REWARD) . " -> miner-{$blockNum}");
        $this->io->newLine();

        return $ledger;
    }

    private function displayBlockchainState(Ledger $ledger): void
    {
        $this->io->section('Blockchain State');
        $this->io->listing([
            'Total minted: ' . $this->formatBtc($ledger->totalMinted()),
            'Total fees: ' . $this->formatBtc($ledger->totalFeesCollected()),
            'In circulation: ' . $this->formatBtc($ledger->totalUnspentAmount()),
            'UTXOs: ' . $ledger->unspent()->count(),
        ]);
    }

    private function displayFinalState(Ledger $ledger): void
    {
        $this->io->section('Final State');
        $this->io->listing([
            'Blocks mined: 5',
            'Total minted: ' . $this->formatBtc($ledger->totalMinted()),
            'Total fees: ' . $this->formatBtc($ledger->totalFeesCollected()),
            'In circulation: ' . $this->formatBtc($ledger->totalUnspentAmount()),
            'UTXOs: ' . $ledger->unspent()->count(),
        ]);

        $this->io->section('UTXOs');
        foreach ($ledger->unspent() as $id => $output) {
            $this->io->text("  {$id}: " . $this->formatBtc($output->amount));
        }
    }

    private function formatBtc(int $satoshis): string
    {
        return ($satoshis / self::SATOSHIS_PER_BTC) . ' BTC';
    }
}
