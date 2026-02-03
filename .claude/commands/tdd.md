# TDD Workflow Assistant

You are helping with Test-Driven Development. Follow the Red-Green-Refactor cycle strictly.

## Instructions

1. **Understand the requirement**: Ask clarifying questions if the feature/fix is unclear

2. **RED Phase - Write failing test first**:
   - Create or update test in appropriate location:
     - `tests/Unit/` for isolated component tests
     - `tests/Feature/` for integration tests
   - Use naming: `test_<action>_<scenario>_<expected_outcome>`
   - Run: `composer phpunit` to verify it fails for the right reason

3. **GREEN Phase - Minimal implementation**:
   - Write the simplest code that makes the test pass
   - No premature optimization or over-engineering
   - Run: `composer phpunit` to verify it passes

4. **REFACTOR Phase - Clean up**:
   - Improve code while keeping tests green
   - Apply SOLID principles
   - Run: `composer test` to verify all quality checks pass

## Test File Locations

| Component Type | Test Location |
|----------------|---------------|
| Ledger behavior | `tests/Unit/LedgerTest.php` |
| Output behavior | `tests/Unit/OutputTest.php` |
| Transaction | `tests/Unit/TxTest.php` |
| Lock types | `tests/Unit/Lock/` |
| Persistence | `tests/Unit/Persistence/` |
| Selection strategies | `tests/Unit/Selection/` |
| Events | `tests/Unit/Event/` |
| Integration | `tests/Feature/` |

## Test Template

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
    }
}
```

## What to do

If user provides a feature request or bug:
1. First write a test that captures the expected behavior
2. Run the test to show it fails
3. Implement the minimal solution
4. Run tests to show it passes
5. Refactor if needed
6. Run `composer test` for full quality check

Provide the conventional commit message at the end.
