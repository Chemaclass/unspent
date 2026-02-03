# Create New Test

Generate a new test file following project conventions.

## Instructions

Ask the user:
1. What component/feature to test?
2. Unit test or Feature test?
3. What scenarios to cover?

## Test Structure

### Unit Test Template

```php
<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit\{Subnamespace};

use Chemaclass\Unspent\{ClassUnderTest};
use PHPUnit\Framework\TestCase;

final class {ClassName}Test extends TestCase
{
    public function test_{action}_{scenario}_{expected_outcome}(): void
    {
        // Arrange
        $sut = /* create system under test */;

        // Act
        $result = $sut->methodUnderTest();

        // Assert
        self::assertSame($expected, $result);
    }
}
```

### Feature Test Template

```php
<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Feature;

use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Output;
use PHPUnit\Framework\TestCase;

final class {FeatureName}IntegrationTest extends TestCase
{
    public function test_{complete_workflow}(): void
    {
        // Setup ledger with initial state
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 1000),
        );

        // Execute complete workflow
        $ledger->transfer('alice', 'bob', 500);

        // Verify end state
        self::assertSame(500, $ledger->totalUnspentByOwner('alice'));
        self::assertSame(500, $ledger->totalUnspentByOwner('bob'));
    }
}
```

## Naming Guidelines

### Test Method Names

```
test_<action>_<scenario>_<expected_outcome>

Examples:
- test_transfer_with_exact_amount_spends_single_output
- test_transfer_with_insufficient_balance_throws_exception
- test_credit_with_zero_amount_throws_exception
- test_apply_with_double_spend_throws_exception
```

### Test Class Locations

| Testing | Location |
|---------|----------|
| Ledger core | `tests/Unit/LedgerTest.php` |
| Output | `tests/Unit/OutputTest.php` |
| Transactions | `tests/Unit/TxTest.php` |
| Locks | `tests/Unit/Lock/{LockName}Test.php` |
| Selection | `tests/Unit/Selection/{Strategy}Test.php` |
| Persistence | `tests/Unit/Persistence/{Adapter}Test.php` |
| Events | `tests/Unit/Event/{Event}Test.php` |
| Workflows | `tests/Feature/{Feature}IntegrationTest.php` |

## Common Assertions

```php
// Value assertions
self::assertSame($expected, $actual);
self::assertEquals($expected, $actual);  // for objects
self::assertTrue($condition);
self::assertFalse($condition);

// Exception assertions
$this->expectException(SpecificException::class);
$this->expectExceptionMessage('Expected message');

// Collection assertions
self::assertCount(3, $collection);
self::assertEmpty($collection);
self::assertContains($item, $collection);
```

## After Creating Test

1. Run `composer phpunit` to see it fail (RED)
2. Implement the feature
3. Run `composer phpunit` to see it pass (GREEN)
4. Run `composer test` for full quality check
