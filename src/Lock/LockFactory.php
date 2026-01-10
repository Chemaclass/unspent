<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Lock;

use Chemaclass\Unspent\OutputLock;
use InvalidArgumentException;

/**
 * Factory for deserializing OutputLock implementations.
 *
 * Register custom lock handlers before deserialization:
 *
 *     LockFactory::register('timelock', fn(array $data) => new TimeLock(
 *         $data['unlockTimestamp'],
 *         $data['owner'],
 *     ));
 *
 * Custom handlers take precedence over built-in types.
 */
class LockFactory
{
    /** @var array<string, callable> */
    private static array $handlers = [];

    /**
     * Registers a custom lock handler.
     *
     * The handler receives the full lock data array and must return an OutputLock.
     *
     * @param string   $type    The lock type (matches 'type' field in serialized data)
     * @param callable $handler Factory callable: fn(array $data): OutputLock
     */
    public static function register(string $type, callable $handler): void
    {
        self::$handlers[$type] = $handler;
    }

    /**
     * Checks if a custom handler is registered for the given type.
     */
    public static function hasHandler(string $type): bool
    {
        return isset(self::$handlers[$type]);
    }

    /**
     * Returns all registered custom handler types.
     *
     * @return list<string>
     */
    public static function registeredTypes(): array
    {
        return array_keys(self::$handlers);
    }

    /**
     * Resets all custom handlers. Primarily for testing.
     */
    public static function reset(): void
    {
        self::$handlers = [];
    }

    /**
     * Creates an OutputLock from its serialized array representation.
     *
     * Custom handlers are checked first, then built-in types.
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidArgumentException If lock type is missing or unknown
     */
    public static function fromArray(array $data): OutputLock
    {
        $type = $data['type'] ?? throw new InvalidArgumentException('Lock type is required');

        // Custom handlers take precedence
        if (isset(self::$handlers[$type])) {
            $lock = (self::$handlers[$type])($data);

            if (!$lock instanceof OutputLock) {
                throw new InvalidArgumentException(
                    \sprintf(
                        "Handler for lock type '%s' must return OutputLock, got %s",
                        $type,
                        get_debug_type($lock),
                    ),
                );
            }

            return $lock;
        }

        // Built-in types
        return match ($type) {
            'none' => new NoLock(),
            'owner' => new Owner((string) ($data['name'] ?? throw new InvalidArgumentException('Name is required for owner lock'))),
            'pubkey' => new PublicKey((string) ($data['key'] ?? throw new InvalidArgumentException('Key is required for pubkey lock'))),
            default => throw new InvalidArgumentException("Unknown lock type: {$type}"),
        };
    }
}
