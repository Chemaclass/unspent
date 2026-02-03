<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Lock;

use Chemaclass\Unspent\OutputLock;
use InvalidArgumentException;
use ReflectionClass;

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
 * Or use auto-discovery with attributes:
 *
 *     #[LockTypeAttribute('timelock')]
 *     final readonly class TimeLock implements OutputLock { ... }
 *
 *     LockFactory::registerFromClass(TimeLock::class);
 *
 * Custom handlers take precedence over built-in types.
 *
 * IMPORTANT: This class uses static state for handler registration.
 * In tests, call LockFactory::reset() in tearDown() to prevent test pollution:
 *
 *     protected function tearDown(): void
 *     {
 *         LockFactory::reset();
 *     }
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
     * Registers a lock class using its LockTypeAttribute.
     *
     * The class must:
     * 1. Have the #[LockTypeAttribute('type-name')] attribute
     * 2. Implement OutputLock
     * 3. Have a static fromArray(array $data): self method
     *
     * @param class-string<OutputLock> $className
     *
     * @throws InvalidArgumentException If class is not properly configured
     */
    public static function registerFromClass(string $className): void
    {
        $reflection = new ReflectionClass($className);
        $attributes = $reflection->getAttributes(LockTypeAttribute::class);

        if ($attributes === []) {
            throw new InvalidArgumentException(
                \sprintf("Class '%s' must have #[LockTypeAttribute] attribute", $className),
            );
        }

        if (!$reflection->implementsInterface(OutputLock::class)) {
            throw new InvalidArgumentException(
                \sprintf("Class '%s' must implement OutputLock", $className),
            );
        }

        /** @var LockTypeAttribute $attribute */
        $attribute = $attributes[0]->newInstance();

        // Check for fromArray method
        if ($reflection->hasMethod('fromArray')) {
            $method = $reflection->getMethod('fromArray');
            if ($method->isStatic() && $method->isPublic()) {
                self::register($attribute->type, static function (array $data) use ($method): OutputLock {
                    /** @var OutputLock $lock */
                    $lock = $method->invoke(null, $data);

                    return $lock;
                });

                return;
            }
        }

        throw new InvalidArgumentException(
            \sprintf("Class '%s' must have a public static fromArray(array \$data): self method", $className),
        );
    }

    /**
     * Registers multiple lock classes using their LockTypeAttribute.
     *
     * @param class-string<OutputLock> ...$classNames
     */
    public static function registerFromClasses(string ...$classNames): void
    {
        foreach ($classNames as $className) {
            self::registerFromClass($className);
        }
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

        // Built-in types using enum
        return match (LockType::tryFrom($type)) {
            LockType::NONE => new NoLock(),
            LockType::OWNER => new Owner((string) ($data['name'] ?? throw new InvalidArgumentException('Name is required for owner lock'))),
            LockType::PUBLIC_KEY => new PublicKey((string) ($data['key'] ?? throw new InvalidArgumentException('Key is required for pubkey lock'))),
            LockType::TIMELOCK => TimeLock::fromArray($data),
            LockType::MULTISIG => MultisigLock::fromArray($data),
            LockType::HASHLOCK => HashLock::fromArray($data),
            null => throw new InvalidArgumentException("Unknown lock type: {$type}"),
        };
    }
}
