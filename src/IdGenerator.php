<?php

declare(strict_types=1);

namespace Chemaclass\Unspent;

final class IdGenerator
{
    private const string HASHING_ALGO = 'sha256';

    private const int ID_LENGTH = 32;

    /**
     * Generate a deterministic ID from spend content (inputs + outputs).
     * Same content always produces the same ID.
     *
     * @param list<string> $inputIds
     * @param list<Output> $outputs
     */
    public static function forSpend(array $inputIds, array $outputs): string
    {
        $data = implode('|', $inputIds) . '||' . self::serializeOutputs($outputs);

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
     * Each call produces a different ID (includes random bytes).
     */
    public static function forOutput(int $amount): string
    {
        $data = $amount . '|' . bin2hex(random_bytes(8));

        return self::hash($data);
    }

    /**
     * @param list<Output> $outputs
     */
    private static function serializeOutputs(array $outputs): string
    {
        return implode('|', array_map(
            static fn(Output $o): string => $o->id->value . ':' . $o->amount,
            $outputs,
        ));
    }

    private static function hash(string $data): string
    {
        return substr(hash(self::HASHING_ALGO, $data), 0, self::ID_LENGTH);
    }
}
