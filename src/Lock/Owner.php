<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Lock;

use Chemaclass\Unspent\Exception\AuthorizationException;
use Chemaclass\Unspent\OutputLock;
use Chemaclass\Unspent\Tx;
use InvalidArgumentException;

/**
 * A lock that restricts spending to a specific owner.
 *
 * The tx must have `signedBy` matching the owner name.
 */
final readonly class Owner implements OutputLock
{
    public function __construct(
        public string $name,
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Owner name cannot be empty or whitespace');
        }
    }

    public function validate(Tx $tx, int $inputIndex): void
    {
        if ($tx->signedBy !== $this->name) {
            throw AuthorizationException::notOwner($this->name, $tx->signedBy);
        }
    }

    /**
     * @return array{type: string, name: string}
     */
    public function toArray(): array
    {
        return ['type' => LockType::OWNER->value, 'name' => $this->name];
    }
}
