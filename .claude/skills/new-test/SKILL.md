# Create New Test

Generate a test file following project conventions. `$ARGUMENTS` describes what to test.

## Instructions

1. Determine test type: Unit (`tests/Unit/`) or Feature (`tests/Feature/`)
2. Create test file in the correct location
3. Use naming convention: `test_<action>_<scenario>_<expected_outcome>()`
4. Run `composer phpunit` to verify it fails (RED phase)

## Test Locations

| Testing | Location |
|---------|----------|
| Ledger | `tests/Unit/LedgerTest.php` |
| Output | `tests/Unit/OutputTest.php` |
| Tx | `tests/Unit/TxTest.php` |
| Locks | `tests/Unit/Lock/{Name}Test.php` |
| Selection | `tests/Unit/Selection/{Name}Test.php` |
| Persistence | `tests/Unit/Persistence/{Adapter}/` |
| Events | `tests/Unit/Event/` |
| Workflows | `tests/Feature/{Feature}IntegrationTest.php` |

## Unit Test Template

```php
<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit;

use PHPUnit\Framework\TestCase;

final class {ClassName}Test extends TestCase
{
    public function test_{action}_{scenario}_{expected_outcome}(): void
    {
        // Arrange
        // Act
        // Assert
        self::assertSame($expected, $actual);
    }
}
```

## Feature Test Template

```php
<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Feature;

use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Output;
use PHPUnit\Framework\TestCase;

final class {Feature}IntegrationTest extends TestCase
{
    public function test_{workflow}(): void
    {
        $ledger = Ledger::withGenesis(Output::ownedBy('alice', 1000));
        // workflow steps
        self::assertSame($expected, $actual);
    }
}
```

## After Creating

1. Run `composer phpunit` — confirm RED
2. Implement the feature
3. Run `composer phpunit` — confirm GREEN
4. Run `composer test` — full quality check
