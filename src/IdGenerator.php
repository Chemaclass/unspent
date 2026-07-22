<?php

declare(strict_types=1);

namespace Chemaclass\Unspent;

final class IdGenerator
{
    private const string HASHING_ALGO = 'sha256';

    private const int ID_LENGTH = 32;

    /** Number of random bytes for output ID entropy (128-bit). */
    private const int RANDOM_BYTES = 16;

    /**
     * Generate a deterministic ID from transaction content (spends + outputs).
     * Same content always produces the same ID.
     *
     * @param list<string> $spendIds
     * @param list<Output> $outputs
     */
    public static function forTx(array $spendIds, array $outputs): string
    {
        $data = implode('|', $spendIds) . '||' . self::serializeOutputs($outputs);

        return self::hash($data);
    }

    /**
     * Generate a deterministic ID from coinbase content (outputs only).
     * Same content always produces the same ID.
     *
     * @param list<Output> $outputs
     */
    public static function forCoinbase(array $outputs): string
    {
        return self::hash(self::serializeOutputs($outputs));
    }

    /**
     * Generate a unique ID for an output.
     * Each call produces a different ID (128 bits of randomness, 32 hex chars).
     */
    public static function forOutput(): string
    {
        return bin2hex(random_bytes(self::RANDOM_BYTES));
    }

    /**
     * @param list<Output> $outputs
     */
    private static function serializeOutputs(array $outputs): string
    {
        return implode('|', array_map(
            static fn (Output $o): string => $o->id->value . ':' . $o->amount,
            $outputs,
        ));
    }

    private static function hash(string $data): string
    {
        return substr(hash(self::HASHING_ALGO, $data), 0, self::ID_LENGTH);
    }
}
