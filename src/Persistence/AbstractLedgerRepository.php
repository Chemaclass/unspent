<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Persistence;

use Chemaclass\Unspent\Lock\LockFactory;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;

/**
 * Abstract base class for ledger repository implementations.
 *
 * Provides shared logic for lock data normalization and output conversion
 * that can be reused across different database backends (SQLite, MySQL, PostgreSQL, etc.).
 *
 * Extend this class to implement custom database persistence:
 *
 *     class MySQLLedgerRepository extends AbstractLedgerRepository
 *     {
 *         public function save(string $id, LedgerInterface $ledger): void
 *         {
 *             // MySQL-specific SQL
 *             // Use: $this->extractLockData($output) for normalization
 *         }
 *
 *         public function find(string $id): ?LedgerInterface
 *         {
 *             // MySQL-specific SQL
 *             // Use: Ledger::fromArray($this->buildLedgerDataArray(...))
 *         }
 *     }
 */
abstract class AbstractLedgerRepository implements QueryableLedgerRepository
{
    /**
     * Schema version for serialization compatibility.
     * Increment when making breaking changes to the storage format.
     */
    public const int SCHEMA_VERSION = 1;

    /**
     * Extract lock data into normalized columns for database storage.
     *
     * Converts an Output's lock into separate columns:
     * - type: 'owner', 'pubkey', 'none', or custom type name
     * - owner: For owner locks, the owner name
     * - pubkey: For pubkey locks, the base64 key
     * - custom: For custom locks, JSON-encoded lock data
     */
    protected function extractLockData(Output $output): LockData
    {
        return LockData::fromOutput($output);
    }

    /**
     * Convert database rows to Output objects.
     *
     * Expects rows with these columns:
     * - id: Output ID
     * - amount: Integer amount
     * - lock_type, lock_owner, lock_pubkey, lock_custom_data: Lock columns
     *
     * @param array<int, array<string, mixed>> $rows Database rows
     *
     * @return list<Output>
     */
    protected function rowsToOutputs(array $rows): array
    {
        return array_values(array_map(
            static fn (array $row): Output => new Output(
                new OutputId($row['id']),
                (int) $row['amount'],
                LockFactory::fromArray(LockData::toArrayFromRow($row)),
            ),
            $rows,
        ));
    }

    /**
     * Build a ledger data array from database query results.
     *
     * This helper constructs the array format expected by Ledger::fromArray().
     *
     * @param array<int, array<string, mixed>> $unspentRows     Rows for unspent outputs
     * @param array<int, array<string, mixed>> $spentRows       Rows for spent outputs
     * @param array<int, array<string, mixed>> $transactionRows Rows for transactions
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
    protected function buildLedgerDataArray(
        array $unspentRows,
        array $spentRows,
        array $transactionRows,
    ): array {
        $unspent = [];
        $outputCreatedBy = [];
        $outputSpentBy = [];
        $spentOutputs = [];

        foreach ($unspentRows as $row) {
            $unspent[$row['id']] = [
                'amount' => (int) $row['amount'],
                'lock' => LockData::toArrayFromRow($row),
            ];
            $outputCreatedBy[$row['id']] = $row['created_by'];
        }

        foreach ($spentRows as $row) {
            $spentOutputs[$row['id']] = [
                'amount' => (int) $row['amount'],
                'lock' => LockData::toArrayFromRow($row),
            ];
            $outputCreatedBy[$row['id']] = $row['created_by'];
            if ($row['spent_by'] !== null) {
                $outputSpentBy[$row['id']] = $row['spent_by'];
            }
        }

        $appliedTxs = [];
        $txFees = [];
        $coinbaseAmounts = [];

        foreach ($transactionRows as $row) {
            $appliedTxs[] = $row['id'];
            if ($row['is_coinbase']) {
                $coinbaseAmounts[$row['id']] = (int) $row['coinbase_amount'];
            } elseif ($row['fee'] !== null) {
                $txFees[$row['id']] = (int) $row['fee'];
            }
        }

        return [
            'version' => self::SCHEMA_VERSION,
            'unspent' => $unspent,
            'appliedTxs' => $appliedTxs,
            'txFees' => $txFees,
            'coinbaseAmounts' => $coinbaseAmounts,
            'outputCreatedBy' => $outputCreatedBy,
            'outputSpentBy' => $outputSpentBy,
            'spentOutputs' => $spentOutputs,
        ];
    }
}
