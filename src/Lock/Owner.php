<?php

declare(strict_types=1);

namespace Chemaclass\Unspent\Lock;

use Chemaclass\Unspent\Exception\AuthorizationException;
use Chemaclass\Unspent\OutputLock;
use Chemaclass\Unspent\Spend;

/**
 * A lock that restricts spending to a specific owner.
 *
 * The spend must have `signedBy` matching the owner name.
 */
final readonly class Owner implements OutputLock
{
    public function __construct(
        public string $name,
    ) {}

    public function validate(Spend $spend, int $inputIndex): void
    {
        if ($spend->signedBy !== $this->name) {
            throw AuthorizationException::notOwner($this->name, $spend->signedBy);
        }
    }

    /**
     * @return array{type: string, name: string}
     */
    public function toArray(): array
    {
        return ['type' => 'owner', 'name' => $this->name];
    }
}
