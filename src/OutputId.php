<?php

declare(strict_types=1);

namespace Chemaclass\Unspent;

final readonly class OutputId implements Id
{
    use IdValue;

    public function __construct(
        public string $value,
    ) {
        self::assertValidIdValue($value, 'OutputId');
    }
}
