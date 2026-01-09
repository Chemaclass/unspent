<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Lock;

use Chemaclass\Unspent\Exception\AuthorizationException;
use Chemaclass\Unspent\OutputLock;
use Chemaclass\Unspent\Spend;

/**
 * A lock that restricts spending to a specific owner.
 *
 * The spend must have `authorizedBy` matching the owner string.
 */
final readonly class OwnerLock implements OutputLock
{
    public function __construct(
        public string $owner,
    ) {}

    public function validate(Spend $spend, int $inputIndex): void
    {
        if ($spend->authorizedBy !== $this->owner) {
            throw AuthorizationException::notOwner($this->owner, $spend->authorizedBy);
        }
    }

    /**
     * @return array{type: string, owner: string}
     */
    public function toArray(): array
    {
        return ['type' => 'owner', 'owner' => $this->owner];
    }
}
