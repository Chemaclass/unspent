<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Persistence;

use Chemaclass\Unspent\Lock\LockFactory;
use Chemaclass\Unspent\Lock\LockType;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use RuntimeException;

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
 *         public function save(string $id, Ledger $ledger): void
 *         {
 *             // MySQL-specific SQL
 *             // Use: $this->extractLockData($output) for normalization
 *         }
 *
 *         public function load(string $id): ?Ledger
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
     * Convert a database row to a lock array for LockFactory.
     *
     * Expects rows with these columns:
     * - lock_type: 'owner', 'pubkey', 'none', or custom type
     * - lock_owner: Owner name (for owner locks)
     * - lock_pubkey: Base64 key (for pubkey locks)
     * - lock_custom_data: JSON string (for custom locks)
     *
     * @param array<string, mixed> $row Database row with lock columns
     *
     * @return array<string, mixed> Lock array for LockFactory::fromArray()
     */
    protected function rowToLockArray(array $row): array
    {
        $type = $row['lock_type'];

        // Custom locks stored as JSON
        if ($row['lock_custom_data'] !== null) {
            return json_decode($row['lock_custom_data'], true, 512, JSON_THROW_ON_ERROR);
        }

        return match (LockType::tryFrom($type)) {
            LockType::NONE => ['type' => LockType::NONE->value],
            LockType::OWNER => ['type' => LockType::OWNER->value, 'name' => $row['lock_owner']],
            LockType::PUBLIC_KEY => ['type' => LockType::PUBLIC_KEY->value, 'key' => $row['lock_pubkey']],
            null => throw new RuntimeException("Unknown lock type: {$type}"),
        };
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
            fn (array $row): Output => new Output(
                new OutputId($row['id']),
                (int) $row['amount'],
                LockFactory::fromArray($this->rowToLockArray($row)),
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
     *     unspent: list<array{id: string, amount: int, lock: array<string, mixed>}>,
     *     appliedTxs: list<string>,
     *     txFees: array<string, int>,
     *     coinbaseAmounts: array<string, int>,
     *     outputCreatedBy: array<string, string>,
     *     outputSpentBy: array<string, string>,
     *     spentOutputs: array<string, array{id: string, amount: int, lock: array<string, mixed>}>
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
            $unspent[] = [
                'id' => $row['id'],
                'amount' => (int) $row['amount'],
                'lock' => $this->rowToLockArray($row),
            ];
            $outputCreatedBy[$row['id']] = $row['created_by'];
        }

        foreach ($spentRows as $row) {
            $spentOutputs[$row['id']] = [
                'id' => $row['id'],
                'amount' => (int) $row['amount'],
                'lock' => $this->rowToLockArray($row),
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
