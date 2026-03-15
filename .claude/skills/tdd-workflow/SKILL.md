# TDD Workflow

Follow the Red-Green-Refactor cycle strictly for the Unspent UTXO library.

## Instructions

When invoked with `$ARGUMENTS`, use them as the feature/bug description. Otherwise ask the user what to implement.

### 1. RED — Write failing test

- Create test in the appropriate location (see table below)
- Name: `test_<action>_<scenario>_<expected_outcome>()`
- Use Arrange-Act-Assert pattern
- Run `composer phpunit` to confirm it fails for the right reason

### 2. GREEN — Minimal implementation

- Write the simplest code that makes the test pass
- No premature optimization
- Run `composer phpunit` to confirm it passes

### 3. REFACTOR — Clean up

- Improve code while keeping tests green
- Run `composer test` for full quality check

## Test Locations

| Component | Location |
|-----------|----------|
| Ledger | `tests/Unit/LedgerTest.php` |
| Output | `tests/Unit/OutputTest.php` |
| Lock | `tests/Unit/Lock/{Name}Test.php` |
| Selection | `tests/Unit/Selection/{Name}Test.php` |
| Persistence | `tests/Unit/Persistence/{Adapter}/` |
| Events | `tests/Unit/Event/` |
| Integration | `tests/Feature/{Feature}IntegrationTest.php` |

## Template

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

## Rules

- Never write production code without a failing test
- One failing test at a time
- Test behavior (public API), not implementation
- Use `assertSame()` for strict comparison
- Provide conventional commit message at the end
