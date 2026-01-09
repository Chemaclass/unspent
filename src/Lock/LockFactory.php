<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Lock;

use Chemaclass\Unspent\OutputLock;
use InvalidArgumentException;

/**
 * Factory for deserializing OutputLock implementations.
 */
final class LockFactory
{
    /**
     * Creates an OutputLock from its serialized array representation.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): OutputLock
    {
        $type = $data['type'] ?? throw new InvalidArgumentException('Lock type is required');

        return match ($type) {
            'none' => new NoLock(),
            'owner' => new Owner((string) ($data['name'] ?? throw new InvalidArgumentException('Name is required for owner lock'))),
            'pubkey' => new PublicKey((string) ($data['key'] ?? throw new InvalidArgumentException('Key is required for pubkey lock'))),
            default => throw new InvalidArgumentException("Unknown lock type: {$type}"),
        };
    }
}
