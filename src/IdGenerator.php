<?php

declare(strict_types=1);

namespace Chemaclass\Unspent;

final class IdGenerator
{
    private const string HASHING_ALGO = 'sha256';

    private const int ID_LENGTH = 32;

    /** Number of random bytes for output ID entropy (128-bit). */
    private const int RANDOM_BYTES = 16;

    /** Hex characters per output ID (two per random byte). */
    private const int OUTPUT_ID_LENGTH = self::RANDOM_BYTES * 2;

    /** How many output IDs a single CSPRNG read serves. */
    private const int IDS_PER_CSPRNG_READ = 256;

    /** Hex-encoded CSPRNG output not yet handed out. */
    private static string $entropy = '';

    private static int $entropyOffset = 0;

    /**
     * PID that filled the current buffer. A fork must not keep serving its
     * parent's remaining bytes, or both processes would emit identical IDs.
     */
    private static int|false $entropyPid = false;

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
     *
     * Entropy is drawn from the CSPRNG in batches: every ID still gets its own
     * 16 distinct random bytes, but one `random_bytes()` call now serves 256 of
     * them instead of one. The buffer is discarded when the PID changes so a
     * forked child never replays its parent's unused bytes.
     */
    public static function forOutput(): string
    {
        $pid = getmypid();

        if ($pid !== self::$entropyPid
            || self::$entropyOffset + self::OUTPUT_ID_LENGTH > \strlen(self::$entropy)
        ) {
            self::$entropy = bin2hex(random_bytes(self::RANDOM_BYTES * self::IDS_PER_CSPRNG_READ));
            self::$entropyOffset = 0;
            self::$entropyPid = $pid;
        }

        $id = substr(self::$entropy, self::$entropyOffset, self::OUTPUT_ID_LENGTH);
        self::$entropyOffset += self::OUTPUT_ID_LENGTH;

        return $id;
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
