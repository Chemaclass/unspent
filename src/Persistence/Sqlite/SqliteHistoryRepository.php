<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Persistence\Sqlite;

use Chemaclass\Unspent\CoinbaseTx;
use Chemaclass\Unspent\Lock\LockFactory;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputHistory;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\OutputStatus;
use Chemaclass\Unspent\Persistence\HistoryRepository;
use Chemaclass\Unspent\Persistence\LockData;
use Chemaclass\Unspent\Persistence\PersistenceException;
use Chemaclass\Unspent\Tx;
use Chemaclass\Unspent\TxId;
use PDO;
use PDOException;
use PDOStatement;

/**
 * SQLite implementation of HistoryRepository for store-backed mode.
 *
 * Provides direct database queries for history information without loading
 * the entire ledger into memory. Used by Ledger in store-backed mode for scalability.
 */
final class SqliteHistoryRepository implements HistoryRepository
{
    private const string SQL_OUTPUT_BY_ID = 'SELECT * FROM outputs WHERE ledger_id = ? AND id = ?';
    private const string SQL_TX_BY_ID = 'SELECT * FROM transactions WHERE ledger_id = ? AND id = ?';
    private const string SQL_ALL_TX_FEES = 'SELECT id, fee FROM transactions WHERE ledger_id = ? AND is_coinbase = 0 AND fee IS NOT NULL';
    private const string SQL_OUTPUT_INSERT = 'INSERT INTO outputs (id, ledger_id, amount, lock_type, lock_owner, lock_pubkey, lock_custom_data, is_spent, created_by, spent_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
    private const string SQL_OUTPUT_MARK_SPENT = 'UPDATE outputs SET is_spent = 1, spent_by = ? WHERE ledger_id = ? AND id = ?';
    private const string SQL_TX_INSERT = 'INSERT INTO transactions (id, ledger_id, is_coinbase, fee, coinbase_amount) VALUES (?, ?, ?, ?, ?)';
    private const string SQL_LEDGER_UPDATE_TOTALS = 'UPDATE ledgers SET total_unspent = total_unspent + ?, total_fees = total_fees + ?, total_minted = total_minted + ? WHERE id = ?';

    private const string ORIGIN_GENESIS = 'genesis';

    /** @var array<string, PDOStatement> Cached prepared statements */
    private array $stmtCache = [];

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $ledgerId,
    ) {
    }

    public function saveTransaction(
        Tx $tx,
        int $fee,
        array $spentOutputData,
    ): void {
        try {
            $this->pdo->beginTransaction();

            $this->insertOutputs($tx->outputs, $tx->id->value);

            // Mark spent outputs
            $spentStmt = $this->prepare(self::SQL_OUTPUT_MARK_SPENT);
            foreach ($tx->spends as $spendId) {
                $spentStmt->execute([
                    $tx->id->value,
                    $this->ledgerId,
                    $spendId->value,
                ]);
            }

            // Insert transaction record
            $txStmt = $this->prepare(self::SQL_TX_INSERT);
            $txStmt->execute([
                $tx->id->value,
                $this->ledgerId,
                0, // is_coinbase = false
                $fee,
                null, // coinbase_amount
            ]);

            // Update ledger totals
            $outputAmount = $tx->totalOutputAmount();
            $spentAmount = array_sum(array_column($spentOutputData, 'amount'));
            $unspentDelta = $outputAmount - $spentAmount;

            $updateStmt = $this->prepare(self::SQL_LEDGER_UPDATE_TOTALS);
            $updateStmt->execute([
                $unspentDelta,
                $fee,
                0, // minted delta
                $this->ledgerId,
            ]);

            $this->pdo->commit();
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            throw PersistenceException::saveFailed($this->ledgerId, $e->getMessage());
        }
    }

    public function saveCoinbase(CoinbaseTx $coinbase): void
    {
        try {
            $this->pdo->beginTransaction();

            $this->insertOutputs($coinbase->outputs, $coinbase->id->value);

            // Insert transaction record
            $txStmt = $this->prepare(self::SQL_TX_INSERT);
            $mintedAmount = $coinbase->totalOutputAmount();
            $txStmt->execute([
                $coinbase->id->value,
                $this->ledgerId,
                1, // is_coinbase = true
                null, // fee
                $mintedAmount,
            ]);

            // Update ledger totals
            $updateStmt = $this->prepare(self::SQL_LEDGER_UPDATE_TOTALS);
            $updateStmt->execute([
                $mintedAmount, // unspent delta
                0, // fee delta
                $mintedAmount, // minted delta
                $this->ledgerId,
            ]);

            $this->pdo->commit();
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            throw PersistenceException::saveFailed($this->ledgerId, $e->getMessage());
        }
    }

    public function saveGenesis(array $outputs): void
    {
        try {
            $this->pdo->beginTransaction();

            $this->insertOutputs($outputs, self::ORIGIN_GENESIS);

            $totalAmount = array_sum(array_map(
                static fn (Output $o): int => $o->amount,
                $outputs,
            ));

            // Update ledger totals
            $updateStmt = $this->prepare(self::SQL_LEDGER_UPDATE_TOTALS);
            $updateStmt->execute([
                $totalAmount, // unspent delta
                0, // fee delta
                0, // minted delta (genesis is not minting)
                $this->ledgerId,
            ]);

            $this->pdo->commit();
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            throw PersistenceException::saveFailed($this->ledgerId, $e->getMessage());
        }
    }

    public function findSpentOutput(OutputId $id): ?Output
    {
        $row = $this->fetchOutputRow($id);
        if ($row === null || (int) $row['is_spent'] === 0) {
            return null;
        }

        return new Output(
            $id,
            (int) $row['amount'],
            LockFactory::fromArray(LockData::toArrayFromRow($row)),
        );
    }

    public function findOutputHistory(OutputId $id): ?OutputHistory
    {
        $row = $this->fetchOutputRow($id);
        if ($row === null) {
            return null;
        }

        return new OutputHistory(
            id: $id,
            amount: (int) $row['amount'],
            lock: LockFactory::fromArray(LockData::toArrayFromRow($row)),
            createdBy: $row['created_by'],
            spentBy: $row['spent_by'],
            status: OutputStatus::fromSpentBy($row['spent_by']),
        );
    }

    public function findOutputCreatedBy(OutputId $id): ?string
    {
        $row = $this->fetchOutputRow($id);

        return $row['created_by'] ?? null;
    }

    public function findOutputSpentBy(OutputId $id): ?string
    {
        $row = $this->fetchOutputRow($id);

        return $row['spent_by'] ?? null;
    }

    public function findFeeForTx(TxId $id): ?int
    {
        $row = $this->fetchTransactionRow($id);
        if ($row === null || $row['fee'] === null) {
            return null;
        }

        return (int) $row['fee'];
    }

    public function findAllTxFees(): array
    {
        try {
            $stmt = $this->prepare(self::SQL_ALL_TX_FEES);
            $stmt->execute([$this->ledgerId]);

            $fees = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $fees[$row['id']] = (int) $row['fee'];
            }

            return $fees;
        } catch (PDOException $e) {
            throw PersistenceException::queryFailed($e->getMessage());
        }
    }

    public function isCoinbase(TxId $id): bool
    {
        $row = $this->fetchTransactionRow($id);

        return $row !== null && (int) $row['is_coinbase'] === 1;
    }

    public function findCoinbaseAmount(TxId $id): ?int
    {
        $row = $this->fetchTransactionRow($id);
        if ($row === null || $row['coinbase_amount'] === null) {
            return null;
        }

        return (int) $row['coinbase_amount'];
    }

    /**
     * @param list<Output> $outputs
     */
    private function insertOutputs(array $outputs, string $createdBy): void
    {
        $stmt = $this->prepare(self::SQL_OUTPUT_INSERT);
        foreach ($outputs as $output) {
            $lockData = LockData::fromOutput($output);
            $stmt->execute([
                $output->id->value,
                $this->ledgerId,
                $output->amount,
                $lockData->type,
                $lockData->owner,
                $lockData->pubkey,
                $lockData->custom,
                0, // is_spent = false
                $createdBy,
                null, // spent_by
            ]);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchOutputRow(OutputId $id): ?array
    {
        try {
            $stmt = $this->prepare(self::SQL_OUTPUT_BY_ID);
            $stmt->execute([$this->ledgerId, $id->value]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return $row === false ? null : $row;
        } catch (PDOException $e) {
            throw PersistenceException::queryFailed($e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchTransactionRow(TxId $id): ?array
    {
        try {
            $stmt = $this->prepare(self::SQL_TX_BY_ID);
            $stmt->execute([$this->ledgerId, $id->value]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return $row === false ? null : $row;
        } catch (PDOException $e) {
            throw PersistenceException::queryFailed($e->getMessage());
        }
    }

    private function prepare(string $sql): PDOStatement
    {
        return $this->stmtCache[$sql] ??= $this->pdo->prepare($sql);
    }
}
