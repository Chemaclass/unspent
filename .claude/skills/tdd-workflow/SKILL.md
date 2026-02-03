# TDD Workflow Skill

## Activation Triggers
- Creating or modifying test files
- Running PHPUnit tests
- Discussing testing strategy
- Implementing new features (test first!)

## The TDD Cycle

```
┌─────────────────────────────────────────────────────────────┐
│                                                             │
│    ┌─────────┐      ┌─────────┐      ┌──────────┐         │
│    │   RED   │ ───► │  GREEN  │ ───► │ REFACTOR │ ──┐     │
│    │  Write  │      │  Write  │      │ Improve  │   │     │
│    │ Failing │      │ Minimal │      │   Code   │   │     │
│    │  Test   │      │  Code   │      │          │   │     │
│    └─────────┘      └─────────┘      └──────────┘   │     │
│         ▲                                           │     │
│         └───────────────────────────────────────────┘     │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

## Test Directory Structure

```
tests/
├── Unit/
│   ├── LedgerTest.php              # Core ledger behavior
│   ├── OutputTest.php              # Output immutability
│   ├── TxTest.php                  # Transaction building
│   ├── UnspentSetTest.php          # UnspentSet operations
│   ├── Lock/
│   │   ├── OwnerTest.php
│   │   ├── PublicKeyTest.php
│   │   └── NoLockTest.php
│   ├── Selection/
│   │   ├── FifoStrategyTest.php
│   │   ├── LargestFirstStrategyTest.php
│   │   └── SmallestFirstStrategyTest.php
│   ├── Persistence/
│   │   └── Sqlite/
│   └── Event/
└── Feature/
    ├── LedgerIntegrationTest.php
    ├── CustomLockIntegrationTest.php
    └── EventDispatchingLedgerIntegrationTest.php
```

## Test Templates

### Unit Test - Domain Entity

```php
<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit;

use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use PHPUnit\Framework\TestCase;

final class OutputTest extends TestCase
{
    public function test_creates_output_with_owner_and_amount(): void
    {
        $output = Output::ownedBy('alice', 1000);

        self::assertSame(1000, $output->amount());
    }
}
```

### Unit Test - Lock Implementation

```php
<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit\Lock;

use Chemaclass\Unspent\Lock\Owner;
use Chemaclass\Unspent\Tx;
use Chemaclass\Unspent\Exception\AuthorizationException;
use PHPUnit\Framework\TestCase;

final class OwnerTest extends TestCase
{
    public function test_validates_correct_owner(): void
    {
        $lock = new Owner('alice');
        $tx = $this->createTxSignedBy('alice');

        $lock->validate($tx, 0); // Should not throw

        $this->expectNotToPerformAssertions();
    }

    public function test_rejects_incorrect_owner(): void
    {
        $lock = new Owner('alice');
        $tx = $this->createTxSignedBy('bob');

        $this->expectException(AuthorizationException::class);
        $lock->validate($tx, 0);
    }
}
```

### Unit Test - Selection Strategy

```php
<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Unit\Selection;

use Chemaclass\Unspent\Selection\FifoStrategy;
use Chemaclass\Unspent\UnspentSet;
use Chemaclass\Unspent\Output;
use PHPUnit\Framework\TestCase;

final class FifoStrategyTest extends TestCase
{
    public function test_selects_oldest_outputs_first(): void
    {
        $strategy = new FifoStrategy();
        $unspent = UnspentSet::from([
            Output::ownedBy('alice', 100), // oldest
            Output::ownedBy('alice', 200),
            Output::ownedBy('alice', 300), // newest
        ]);

        $selected = $strategy->select($unspent, 150);

        self::assertCount(2, $selected);
        // First two outputs selected (100 + 200 = 300)
    }
}
```

### Feature Test - Integration

```php
<?php

declare(strict_types=1);

namespace Chemaclass\UnspentTests\Feature;

use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Output;
use PHPUnit\Framework\TestCase;

final class LedgerIntegrationTest extends TestCase
{
    public function test_complete_transfer_workflow(): void
    {
        // Arrange: Create ledger with initial state
        $ledger = Ledger::withGenesis(
            Output::ownedBy('alice', 1000),
            Output::ownedBy('bob', 500),
        );

        // Act: Execute transfers
        $ledger
            ->transfer('alice', 'bob', 300)
            ->transfer('bob', 'alice', 100);

        // Assert: Verify final state
        self::assertSame(800, $ledger->totalUnspentByOwner('alice'));
        self::assertSame(700, $ledger->totalUnspentByOwner('bob'));
    }
}
```

## Running Tests

```bash
composer phpunit                           # All tests
composer phpunit -- --filter LedgerTest    # By class name
composer phpunit -- tests/Unit/Lock/       # By directory
composer test                              # Full quality suite
```

## Best Practices

| Practice | Description |
|----------|-------------|
| Descriptive names | `test_transfer_with_insufficient_balance_throws_exception` |
| One concept per test | Don't test multiple behaviors |
| AAA pattern | Arrange → Act → Assert |
| Use factories | `Output::ownedBy()`, `Ledger::withGenesis()` |
| Assert strictly | Use `assertSame()` over `assertEquals()` |
| Test edge cases | Zero amounts, empty sets, boundary conditions |

## Common Assertions

```php
// Value assertions
self::assertSame(expected, actual);      // Strict comparison
self::assertEquals(expected, actual);    // For objects
self::assertTrue(condition);
self::assertFalse(condition);
self::assertNull(value);
self::assertInstanceOf(Class::class, obj);

// Exception assertions
$this->expectException(SpecificException::class);
$this->expectExceptionMessage('Expected message');

// Collection assertions
self::assertCount(3, $collection);
self::assertEmpty($collection);
self::assertContains($item, $collection);
```
