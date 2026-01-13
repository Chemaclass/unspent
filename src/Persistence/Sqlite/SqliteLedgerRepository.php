<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Persistence\Sqlite;

use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\LedgerInterface;
use Chemaclass\Unspent\Lock\LockFactory;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\Persistence\AbstractLedgerRepository;
use Chemaclass\Unspent\Persistence\PersistenceException;
use Chemaclass\Unspent\Persistence\TransactionInfo;
use Chemaclass\Unspent\TxId;
use Chemaclass\Unspent\UnspentSet;
use PDO;
use PDOException;
use PDOStatement;

/**
 * SQLite implementation of the ledger repository.
 *
 * Uses normalized column-based storage for efficient querying.
 * Extends AbstractLedgerRepository for shared lock normalization logic.
 *
 * Performance optimizations:
 * - Prepared statement caching for repeated queries
 * - Combined queries to reduce N+1 patterns
 * - Match expressions for query building
 */
final class SqliteLedgerRepository extends AbstractLedgerRepository
{
    // SQL Query Constants
    private const string SQL_LEDGER_EXISTS = 'SELECT 1 FROM ledgers WHERE id = ?';
    private const string SQL_LEDGER_SELECT = 'SELECT id FROM ledgers WHERE id = ?';
    private const string SQL_LEDGER_DELETE = 'DELETE FROM ledgers WHERE id = ?';
    private const string SQL_LEDGER_INSERT = 'INSERT INTO ledgers (id, version, total_unspent, total_fees, total_minted) VALUES (?, ?, ?, ?, ?)';

    private const string SQL_OUTPUT_INSERT = 'INSERT INTO outputs (id, ledger_id, amount, lock_type, lock_owner, lock_pubkey, lock_custom_data, is_spent, created_by, spent_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
    private const string SQL_OUTPUT_SELECT_ALL = 'SELECT *, is_spent FROM outputs WHERE ledger_id = ?';
    private const string SQL_OUTPUT_SELECT_UNSPENT = 'SELECT * FROM outputs WHERE ledger_id = ? AND is_spent = 0';
    private const string SQL_LEDGER_TOTALS = 'SELECT total_fees, total_minted FROM ledgers WHERE id = ?';
    private const string SQL_OUTPUT_BY_OWNER = 'SELECT * FROM outputs WHERE ledger_id = ? AND lock_owner = ? AND is_spent = 0';
    private const string SQL_OUTPUT_BY_LOCK_TYPE = 'SELECT * FROM outputs WHERE ledger_id = ? AND lock_type = ? AND is_spent = 0';
    private const string SQL_OUTPUT_BY_CREATED = 'SELECT * FROM outputs WHERE ledger_id = ? AND created_by = ?';
    private const string SQL_OUTPUT_COUNT_UNSPENT = 'SELECT COUNT(*) FROM outputs WHERE ledger_id = ? AND is_spent = 0';
    private const string SQL_OUTPUT_SUM_BY_OWNER = 'SELECT COALESCE(SUM(amount), 0) FROM outputs WHERE ledger_id = ? AND lock_owner = ? AND is_spent = 0';

    private const string SQL_TX_INSERT = 'INSERT INTO transactions (id, ledger_id, is_coinbase, fee, coinbase_amount) VALUES (?, ?, ?, ?, ?)';
    private const string SQL_TX_SELECT_ALL = 'SELECT * FROM transactions WHERE ledger_id = ?';
    private const string SQL_TX_COINBASE = 'SELECT id FROM transactions WHERE ledger_id = ? AND is_coinbase = 1';

    private const string ORIGIN_GENESIS = 'genesis';

    /** @var array<string, PDOStatement> Cached prepared statements */
    private array $stmtCache = [];

    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    public function save(string $id, LedgerInterface $ledger): void
    {
        try {
            $this->pdo->beginTransaction();

            $this->deleteLedgerData($id);
            $this->insertLedger($id, $ledger);
            $this->insertOutputs($id, $ledger);
            $this->insertTransactions($id, $ledger);

            $this->pdo->commit();
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            throw PersistenceException::saveFailed($id, $e->getMessage());
        }
    }

    public function find(string $id): ?LedgerInterface
    {
        try {
            $stmt = $this->prepare(self::SQL_LEDGER_SELECT);
            $stmt->execute([$id]);

            if ($stmt->fetch() === false) {
                return null;
            }

            return Ledger::fromArray($this->fetchLedgerData($id));
        } catch (PDOException $e) {
            throw PersistenceException::findFailed($id, $e->getMessage());
        }
    }

    public function delete(string $id): void
    {
        try {
            $this->deleteLedgerData($id);
        } catch (PDOException $e) {
            throw PersistenceException::deleteFailed($id, $e->getMessage());
        }
    }

    public function exists(string $id): bool
    {
        $stmt = $this->prepare(self::SQL_LEDGER_EXISTS);
        $stmt->execute([$id]);

        return $stmt->fetch() !== false;
    }

    /**
     * Load only the unspent outputs for a ledger (for hybrid mode).
     *
     * Returns the UnspentSet and cached totals without loading history.
     *
     * @return array{unspentSet: UnspentSet, totalFees: int, totalMinted: int}|null
     */
    public function findUnspentOnly(string $id): ?array
    {
        try {
            // Check if ledger exists and get totals
            $stmt = $this->prepare(self::SQL_LEDGER_TOTALS);
            $stmt->execute([$id]);
            $totalsRow = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($totalsRow === false) {
                return null;
            }

            // Get only unspent outputs
            $stmt = $this->prepare(self::SQL_OUTPUT_SELECT_UNSPENT);
            $stmt->execute([$id]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $outputs = $this->rowsToOutputs($rows);
            $unspentSet = UnspentSet::fromOutputs(...$outputs);

            return [
                'unspentSet' => $unspentSet,
                'totalFees' => (int) $totalsRow['total_fees'],
                'totalMinted' => (int) $totalsRow['total_minted'],
            ];
        } catch (PDOException $e) {
            throw PersistenceException::findFailed($id, $e->getMessage());
        }
    }

    public function findUnspentByOwner(string $ledgerId, string $owner): array
    {
        return $this->executeOutputQuery(
            self::SQL_OUTPUT_BY_OWNER,
            [$ledgerId, $owner],
        );
    }

    public function findUnspentByAmountRange(string $ledgerId, int $min, ?int $max = null): array
    {
        [$sql, $params] = $this->buildRangeQuery(
            baseTable: 'outputs',
            ledgerId: $ledgerId,
            column: 'amount',
            min: $min,
            max: $max,
            additionalWhere: 'is_spent = 0',
        );

        return $this->executeOutputQuery($sql, $params);
    }

    public function findUnspentByLockType(string $ledgerId, string $lockType): array
    {
        return $this->executeOutputQuery(
            self::SQL_OUTPUT_BY_LOCK_TYPE,
            [$ledgerId, $lockType],
        );
    }

    public function findOutputsCreatedBy(string $ledgerId, string $txId): array
    {
        return $this->executeOutputQuery(
            self::SQL_OUTPUT_BY_CREATED,
            [$ledgerId, $txId],
        );
    }

    public function countUnspent(string $ledgerId): int
    {
        return $this->executeScalarQuery(
            self::SQL_OUTPUT_COUNT_UNSPENT,
            [$ledgerId],
        );
    }

    public function sumUnspentByOwner(string $ledgerId, string $owner): int
    {
        return $this->executeScalarQuery(
            self::SQL_OUTPUT_SUM_BY_OWNER,
            [$ledgerId, $owner],
        );
    }

    public function findCoinbaseTransactions(string $ledgerId): array
    {
        try {
            $stmt = $this->prepare(self::SQL_TX_COINBASE);
            $stmt->execute([$ledgerId]);

            return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id');
        } catch (PDOException $e) {
            throw PersistenceException::queryFailed($e->getMessage());
        }
    }

    public function findTransactionsByFeeRange(string $ledgerId, int $min, ?int $max = null): array
    {
        try {
            [$sql, $params] = $this->buildRangeQuery(
                baseTable: 'transactions',
                ledgerId: $ledgerId,
                column: 'fee',
                min: $min,
                max: $max,
                additionalWhere: 'is_coinbase = 0',
                selectColumns: 'id, fee',
            );

            $stmt = $this->prepare($sql);
            $stmt->execute($params);

            return array_values(array_map(
                TransactionInfo::fromRow(...),
                $stmt->fetchAll(PDO::FETCH_ASSOC),
            ));
        } catch (PDOException $e) {
            throw PersistenceException::queryFailed($e->getMessage());
        }
    }

    /**
     * Get a cached prepared statement or create a new one.
     */
    private function prepare(string $sql): PDOStatement
    {
        return $this->stmtCache[$sql] ??= $this->pdo->prepare($sql);
    }

    /**
     * Execute a query that returns Output objects.
     *
     * @param list<mixed> $params
     *
     * @return list<Output>
     */
    private function executeOutputQuery(string $sql, array $params): array
    {
        try {
            $stmt = $this->prepare($sql);
            $stmt->execute($params);

            return $this->rowsToOutputs($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (PDOException $e) {
            throw PersistenceException::queryFailed($e->getMessage());
        }
    }

    /**
     * Execute a query that returns a single integer value.
     *
     * @param list<mixed> $params
     */
    private function executeScalarQuery(string $sql, array $params): int
    {
        try {
            $stmt = $this->prepare($sql);
            $stmt->execute($params);

            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            throw PersistenceException::queryFailed($e->getMessage());
        }
    }

    /**
     * Build a range query with optional max bound.
     *
     * @return array{0: string, 1: list<mixed>} SQL and parameters
     */
    private function buildRangeQuery(
        string $baseTable,
        string $ledgerId,
        string $column,
        int $min,
        ?int $max,
        string $additionalWhere = '',
        string $selectColumns = '*',
    ): array {
        $whereClause = $additionalWhere !== '' ? " AND {$additionalWhere}" : '';

        return match ($max !== null) {
            true => [
                "SELECT {$selectColumns} FROM {$baseTable} WHERE ledger_id = ?{$whereClause} AND {$column} >= ? AND {$column} <= ?",
                [$ledgerId, $min, $max],
            ],
            false => [
                "SELECT {$selectColumns} FROM {$baseTable} WHERE ledger_id = ?{$whereClause} AND {$column} >= ?",
                [$ledgerId, $min],
            ],
        };
    }

    private function deleteLedgerData(string $id): void
    {
        $stmt = $this->prepare(self::SQL_LEDGER_DELETE);
        $stmt->execute([$id]);
    }

    private function insertLedger(string $id, LedgerInterface $ledger): void
    {
        $stmt = $this->prepare(self::SQL_LEDGER_INSERT);
        $stmt->execute([
            $id,
            self::SCHEMA_VERSION,
            $ledger->totalUnspentAmount(),
            $ledger->totalFeesCollected(),
            $ledger->totalMinted(),
        ]);
    }

    private function insertOutputs(string $id, LedgerInterface $ledger): void
    {
        $stmt = $this->prepare(self::SQL_OUTPUT_INSERT);

        // Pre-fetch all metadata to avoid N+1 queries
        $ledgerArray = $ledger->toArray();
        $outputCreatedBy = $ledgerArray['outputCreatedBy'];
        $outputSpentBy = $ledgerArray['outputSpentBy'];

        // Insert unspent outputs
        foreach ($ledger->unspent() as $outputId => $output) {
            $createdBy = $outputCreatedBy[$outputId] ?? self::ORIGIN_GENESIS;
            $this->executeOutputInsert($stmt, $id, $outputId, $output, $createdBy, null);
        }

        // Insert spent outputs
        foreach ($ledgerArray['spentOutputs'] as $outputId => $outputData) {
            $output = new Output(
                new OutputId($outputId),
                $outputData['amount'],
                LockFactory::fromArray($outputData['lock']),
            );
            $createdBy = $outputCreatedBy[$outputId] ?? self::ORIGIN_GENESIS;
            $spentBy = $outputSpentBy[$outputId] ?? null;
            $this->executeOutputInsert($stmt, $id, $outputId, $output, $createdBy, $spentBy);
        }
    }

    private function executeOutputInsert(
        PDOStatement $stmt,
        string $ledgerId,
        string $outputId,
        Output $output,
        string $createdBy,
        ?string $spentBy,
    ): void {
        $lockData = $this->extractLockData($output);
        $stmt->execute([
            $outputId,
            $ledgerId,
            $output->amount,
            $lockData->type,
            $lockData->owner,
            $lockData->pubkey,
            $lockData->custom,
            $spentBy !== null ? 1 : 0,
            $createdBy,
            $spentBy,
        ]);
    }

    private function insertTransactions(string $id, LedgerInterface $ledger): void
    {
        $stmt = $this->prepare(self::SQL_TX_INSERT);
        $allFees = $ledger->allTxFees();

        foreach ($ledger->toArray()['appliedTxs'] as $txId) {
            $txIdObj = new TxId($txId);
            $isCoinbase = $ledger->isCoinbase($txIdObj);

            $stmt->execute([
                $txId,
                $id,
                $isCoinbase ? 1 : 0,
                $allFees[$txId] ?? null,
                $isCoinbase ? $ledger->coinbaseAmount($txIdObj) : null,
            ]);
        }
    }

    /**
     * Fetch ledger data using optimized combined query.
     *
     * @return array{
     *     version: int,
     *     unspent: array<string, array{amount: int, lock: array<string, mixed>}>,
     *     appliedTxs: list<string>,
     *     txFees: array<string, int>,
     *     coinbaseAmounts: array<string, int>,
     *     outputCreatedBy: array<string, string>,
     *     outputSpentBy: array<string, string>,
     *     spentOutputs: array<string, array{amount: int, lock: array<string, mixed>}>
     * }
     */
    private function fetchLedgerData(string $id): array
    {
        // Single query for all outputs, partitioned by is_spent
        $stmt = $this->prepare(self::SQL_OUTPUT_SELECT_ALL);
        $stmt->execute([$id]);
        $allOutputs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Partition outputs by spent status
        $unspentRows = [];
        $spentRows = [];
        foreach ($allOutputs as $row) {
            if ((int) $row['is_spent'] === 0) {
                $unspentRows[] = $row;
            } else {
                $spentRows[] = $row;
            }
        }

        // Get transactions
        $stmt = $this->prepare(self::SQL_TX_SELECT_ALL);
        $stmt->execute([$id]);
        $txRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->buildLedgerDataArray($unspentRows, $spentRows, $txRows);
    }
}
