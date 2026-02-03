# Refactoring Assistant

Help with safe refactoring while maintaining test coverage and architecture.

## Refactoring Principles

1. **Tests First**: Ensure tests cover the code being refactored
2. **Small Steps**: Make incremental changes
3. **Run Tests**: After each change, run `composer phpunit`
4. **No Behavior Change**: Refactoring changes structure, not behavior

## Common Refactoring Patterns

### Extract Method
When a method is too long or has multiple responsibilities:
```php
// Before
public function process(): void
{
    // validation logic
    // processing logic
    // notification logic
}

// After
public function process(): void
{
    $this->validate();
    $this->executeProcessing();
    $this->notify();
}
```

### Extract Class
When a class has too many responsibilities:
```php
// Identify cohesive groups of methods
// Move them to a new class
// Inject the new class as a dependency
```

### Replace Conditional with Polymorphism
When you have type-checking conditionals:
```php
// Before
if ($lock instanceof Owner) { ... }
elseif ($lock instanceof PublicKey) { ... }

// After: Use OutputLock interface
$lock->validate($tx, $inputIndex);
```

### Introduce Parameter Object
When methods have many parameters:
```php
// Before
function create(string $owner, int $amount, string $type, bool $locked)

// After
function create(OutputParams $params)
```

## Refactoring Checklist

- [ ] Identify code smell
- [ ] Write/verify tests for current behavior
- [ ] Apply refactoring in small steps
- [ ] Run tests after each step
- [ ] Run `composer stan` for type safety
- [ ] Run `composer test` for full quality check
- [ ] Update documentation if needed

## Code Smells to Watch For

| Smell | Solution |
|-------|----------|
| Long method | Extract method |
| Large class | Extract class |
| Feature envy | Move method |
| Primitive obsession | Introduce value object |
| Parallel inheritance | Use composition |
| Lazy class | Inline or merge |
| Speculative generality | Remove unused abstraction |
| Duplicate code | Extract to shared method/class |

## Safe Refactoring Steps

1. **Before starting**:
   ```bash
   composer phpunit  # Verify tests pass
   git status        # Clean working directory
   ```

2. **During refactoring**:
   - Make one change at a time
   - Run tests frequently
   - Commit at stable points

3. **After refactoring**:
   ```bash
   composer test     # Full quality check
   ```

## What NOT to Do

- Don't refactor and add features simultaneously
- Don't skip running tests
- Don't make multiple unrelated changes
- Don't remove tests to make refactoring "pass"
