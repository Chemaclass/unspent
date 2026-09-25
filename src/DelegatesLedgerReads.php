<?php

declare(strict_types=1);

namespace Chemaclass\Unspent;

use Chemaclass\Unspent\Exception\UnspentException;
use Chemaclass\Unspent\Persistence\HistoryRepository;

/**
 * Delegates every read-only LedgerInterface operation to a wrapped Ledger.
 *
 * Shared by the Ledger decorators (EventDispatchingLedger, LoggingLedger), which
 * add behavior only to the state-changing methods and forward all queries unchanged.
 *
 * Requires the using class to expose a `Ledger $ledger` property.
 */
trait DelegatesLedgerReads
{
    public function unspent(): UnspentSet
    {
        return $this->ledger->unspent();
    }

    public function totalUnspentAmount(): int
    {
        return $this->ledger->totalUnspentAmount();
    }

    public function unspentByOwner(string $owner): UnspentSet
    {
        return $this->ledger->unspentByOwner($owner);
    }

    public function totalUnspentByOwner(string $owner): int
    {
        return $this->ledger->totalUnspentByOwner($owner);
    }

    public function canApply(Tx $tx): ?UnspentException
    {
        return $this->ledger->canApply($tx);
    }

    public function isTxApplied(TxId|string $txId): bool
    {
        return $this->ledger->isTxApplied($txId);
    }

    public function totalFeesCollected(): int
    {
        return $this->ledger->totalFeesCollected();
    }

    public function feeForTx(TxId|string $txId): ?int
    {
        return $this->ledger->feeForTx($txId);
    }

    /**
     * @return array<string, int>
     */
    public function allTxFees(): array
    {
        return $this->ledger->allTxFees();
    }

    public function totalMinted(): int
    {
        return $this->ledger->totalMinted();
    }

    public function isCoinbase(TxId|string $id): bool
    {
        return $this->ledger->isCoinbase($id);
    }

    public function coinbaseAmount(TxId|string $id): ?int
    {
        return $this->ledger->coinbaseAmount($id);
    }

    public function outputCreatedBy(OutputId|string $id): ?string
    {
        return $this->ledger->outputCreatedBy($id);
    }

    public function outputSpentBy(OutputId|string $id): ?string
    {
        return $this->ledger->outputSpentBy($id);
    }

    public function getOutput(OutputId|string $id): ?Output
    {
        return $this->ledger->getOutput($id);
    }

    public function outputExists(OutputId|string $id): bool
    {
        return $this->ledger->outputExists($id);
    }

    public function outputHistory(OutputId|string $id): ?OutputHistory
    {
        return $this->ledger->outputHistory($id);
    }

    public function historyRepository(): HistoryRepository
    {
        return $this->ledger->historyRepository();
    }

    /**
     * @return TLedgerArray
     */
    public function toArray(): array
    {
        return $this->ledger->toArray();
    }

    public function toJson(int $flags = 0): string
    {
        return $this->ledger->toJson($flags);
    }
}
