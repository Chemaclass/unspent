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
use Chemaclass\Unspent\UnspentSet;
use PDO;
use PDOException;

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
 *
 * @phpstan-import-type TOutputRow from AbstractLedgerRepository
 * @phpstan-import-type TTransactionRow from AbstractLedgerRepository
 */
final class SqliteLedgerRepository extends AbstractLedgerRepository
{
    use PdoQueryWrapper;
    use PdoStatementCache;
    use PdoTransactionalWrite;

    private const string SQL_LEDGER_EXISTS = 'SELECT 1 FROM ledgers WHERE id = ?';
    private const string SQL_LEDGER_SELECT = 'SELECT id FROM ledgers WHERE id = ?';
    private const string SQL_LEDGER_DELETE = 'DELETE FROM ledgers WHERE id = ?';
    private const string SQL_LEDGER_INSERT = 'INSERT INTO ledgers (id, version, total_unspent, total_fees, total_minted) VALUES (?, ?, ?, ?, ?)';

    private const string SQL_OUTPUT_SELECT_ALL = 'SELECT *, is_spent FROM outputs WHERE ledger_id = ?';
    private const string SQL_OUTPUT_SELECT_UNSPENT = 'SELECT * FROM outputs WHERE ledger_id = ? AND is_spent = 0';
    private const string SQL_LEDGER_TOTALS = 'SELECT total_fees, total_minted FROM ledgers WHERE id = ?';
    private const string SQL_OUTPUT_BY_OWNER = 'SELECT * FROM outputs WHERE ledger_id = ? AND lock_owner = ? AND is_spent = 0';
    private const string SQL_OUTPUT_BY_LOCK_TYPE = 'SELECT * FROM outputs WHERE ledger_id = ? AND lock_type = ? AND is_spent = 0';
    private const string SQL_OUTPUT_BY_CREATED = 'SELECT * FROM outputs WHERE ledger_id = ? AND created_by = ?';
    private const string SQL_OUTPUT_COUNT_UNSPENT = 'SELECT COUNT(*) FROM outputs WHERE ledger_id = ? AND is_spent = 0';
    private const string SQL_OUTPUT_SUM_BY_OWNER = 'SELECT COALESCE(SUM(amount), 0) FROM outputs WHERE ledger_id = ? AND lock_owner = ? AND is_spent = 0';

    private const string SQL_TX_SELECT_ALL = 'SELECT * FROM transactions WHERE ledger_id = ?';
    private const string SQL_TX_COINBASE = 'SELECT id FROM transactions WHERE ledger_id = ? AND is_coinbase = 1';

    private const string ORIGIN_GENESIS = 'genesis';

    /**
     * Upper bound on bound parameters per statement, kept well under SQLite's
     * default SQLITE_MAX_VARIABLE_NUMBER so batched inserts stay portable.
     */
    private const int MAX_BIND_PARAMS = 900;

    /** @var list<string> */
    private const array OUTPUT_COLUMNS = [
        'id', 'ledger_id', 'amount', 'lock_type', 'lock_owner',
        'lock_pubkey', 'lock_custom_data', 'is_spent', 'created_by', 'spent_by',
    ];

    /** @var list<string> */
    private const array TX_COLUMNS = ['id', 'ledger_id', 'is_coinbase', 'fee', 'coinbase_amount'];

    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    public function save(string $id, LedgerInterface $ledger): void
    {
        // Serialize once and reuse across every insert step.
        $ledgerArray = $ledger->toArray();

        $this->runInTransaction($id, function () use ($id, $ledger, $ledgerArray): void {
            $this->deleteLedgerData($id);
            $this->insertLedger($id, $ledger);
            $this->insertOutputs($id, $ledgerArray, $ledger);
            $this->insertTransactions($id, $ledgerArray);
        });
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
            $stmt = $this->prepare(self::SQL_LEDGER_TOTALS);
            $stmt->execute([$id]);
            $totalsRow = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($totalsRow === false) {
                return null;
            }

            $stmt = $this->prepare(self::SQL_OUTPUT_SELECT_UNSPENT);
            $stmt->execute([$id]);
            /** @var list<TOutputRow> $rows */
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
        return $this->tryQuery(function () use ($ledgerId): array {
            $stmt = $this->prepare(self::SQL_TX_COINBASE);
            $stmt->execute([$ledgerId]);

            return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id');
        });
    }

    public function findTransactionsByFeeRange(string $ledgerId, int $min, ?int $max = null): array
    {
        return $this->tryQuery(function () use ($ledgerId, $min, $max): array {
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

            /** @var list<array{id: string, fee: int|string, ...}> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return array_map(TransactionInfo::fromRow(...), $rows);
        });
    }

    /**
     * Execute a query that returns Output objects.
     *
     * @param list<int|string> $params
     *
     * @return list<Output>
     */
    private function executeOutputQuery(string $sql, array $params): array
    {
        return $this->tryQuery(function () use ($sql, $params): array {
            $stmt = $this->prepare($sql);
            $stmt->execute($params);

            /** @var list<TOutputRow> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return $this->rowsToOutputs($rows);
        });
    }

    /**
     * Execute a query that returns a single integer value.
     *
     * @param list<int|string> $params
     */
    private function executeScalarQuery(string $sql, array $params): int
    {
        return $this->tryQuery(function () use ($sql, $params): int {
            $stmt = $this->prepare($sql);
            $stmt->execute($params);

            return (int) $stmt->fetchColumn();
        });
    }

    /**
     * Build a range query with optional max bound.
     *
     * @return array{0: string, 1: list<int|string>} SQL and parameters
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

    /**
     * @param TLedgerArray $ledgerArray
     */
    private function insertOutputs(string $id, array $ledgerArray, LedgerInterface $ledger): void
    {
        $outputCreatedBy = $ledgerArray['outputCreatedBy'];
        $outputSpentBy = $ledgerArray['outputSpentBy'];
        $rows = [];

        foreach ($ledger->unspent() as $outputId => $output) {
            $rows[] = $this->outputRow(
                $id,
                $outputId,
                $output,
                $outputCreatedBy[$outputId] ?? self::ORIGIN_GENESIS,
                null,
            );
        }

        foreach ($ledgerArray['spentOutputs'] as $outputId => $outputData) {
            $output = new Output(
                new OutputId((string) $outputId),
                $outputData['amount'],
                LockFactory::fromArray($outputData['lock']),
            );
            $rows[] = $this->outputRow(
                $id,
                (string) $outputId,
                $output,
                $outputCreatedBy[$outputId] ?? self::ORIGIN_GENESIS,
                $outputSpentBy[$outputId] ?? null,
            );
        }

        $this->batchInsert('outputs', self::OUTPUT_COLUMNS, $rows);
    }

    /**
     * @return list<int|string|null>
     */
    private function outputRow(
        string $ledgerId,
        string $outputId,
        Output $output,
        string $createdBy,
        ?string $spentBy,
    ): array {
        $lockData = $this->extractLockData($output);

        return [
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
        ];
    }

    /**
     * @param TLedgerArray $ledgerArray
     */
    private function insertTransactions(string $id, array $ledgerArray): void
    {
        $txFees = $ledgerArray['txFees'];
        $coinbaseAmounts = $ledgerArray['coinbaseAmounts'];
        $rows = [];

        foreach ($ledgerArray['appliedTxs'] as $txId) {
            $isCoinbase = isset($coinbaseAmounts[$txId]);
            $rows[] = [
                $txId,
                $id,
                $isCoinbase ? 1 : 0,
                $txFees[$txId] ?? null,
                $isCoinbase ? $coinbaseAmounts[$txId] : null,
            ];
        }

        $this->batchInsert('transactions', self::TX_COLUMNS, $rows);
    }

    /**
     * Insert many rows using chunked multi-row INSERT statements, keeping the
     * bound-parameter count per statement under SQLite's limit.
     *
     * @param list<string>                $columns
     * @param list<list<int|string|null>> $rows
     */
    private function batchInsert(string $table, array $columns, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $columnCount = \count($columns);
        $rowsPerChunk = max(1, intdiv(self::MAX_BIND_PARAMS, $columnCount));
        $columnList = implode(', ', $columns);
        $rowPlaceholder = '(' . implode(', ', array_fill(0, $columnCount, '?')) . ')';

        foreach (array_chunk($rows, $rowsPerChunk) as $chunk) {
            $placeholders = implode(', ', array_fill(0, \count($chunk), $rowPlaceholder));
            $sql = "INSERT INTO {$table} ({$columnList}) VALUES {$placeholders}";
            $this->prepare($sql)->execute(array_merge(...$chunk));
        }
    }

    /**
     * Fetch ledger data using optimized combined query.
     *
     * @return TLedgerArray
     */
    private function fetchLedgerData(string $id): array
    {
        // Single query for all outputs, partitioned below by is_spent,
        // avoiding two separate round trips for unspent vs. spent rows.
        $stmt = $this->prepare(self::SQL_OUTPUT_SELECT_ALL);
        $stmt->execute([$id]);
        /** @var list<TOutputRow> $allOutputs */
        $allOutputs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $unspentRows = [];
        $spentRows = [];
        foreach ($allOutputs as $row) {
            if ((int) $row['is_spent'] === 0) {
                $unspentRows[] = $row;
            } else {
                $spentRows[] = $row;
            }
        }

        $stmt = $this->prepare(self::SQL_TX_SELECT_ALL);
        $stmt->execute([$id]);
        /** @var list<TTransactionRow> $txRows */
        $txRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->buildLedgerDataArray($unspentRows, $spentRows, $txRows);
    }
}
