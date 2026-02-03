# New Feature Implementation

Guide for implementing new features following hexagonal architecture and TDD.

## Implementation Steps

### 1. Understand Requirements
- What is the feature?
- Who/what will use it? (driving adapter)
- What external systems does it need? (driven adapters)
- What domain concepts are involved?

### 2. Design the Interface First
- Define the port (interface) if needed
- Consider backward compatibility
- Document expected behavior

### 3. TDD Implementation

```
RED    -> Write failing test
GREEN  -> Minimal implementation
REFACTOR -> Clean up
```

## Feature Type Templates

### A. New Lock Type

1. **Create interface test**:
   ```php
   // tests/Unit/Lock/NewLockTest.php
   public function test_validate_with_valid_auth_passes(): void
   public function test_validate_with_invalid_auth_throws(): void
   public function test_to_array_returns_serializable_format(): void
   ```

2. **Implement lock**:
   ```php
   // src/Lock/NewLock.php
   final readonly class NewLock implements OutputLock
   {
       public function validate(Tx $tx, int $inputIndex): void { }
       public function toArray(): array { }
   }
   ```

3. **Register in factory** (if needed):
   ```php
   LockFactory::register('new_lock', fn($data) => new NewLock(...));
   ```

4. **Add feature test**:
   ```php
   // tests/Feature/NewLockIntegrationTest.php
   ```

### B. New Selection Strategy

1. **Create test**:
   ```php
   // tests/Unit/Selection/NewStrategyTest.php
   public function test_select_returns_optimal_outputs(): void
   ```

2. **Implement strategy**:
   ```php
   // src/Selection/NewStrategy.php
   final readonly class NewStrategy implements SelectionStrategy
   {
       public function select(UnspentSet $unspent, int $amount): array { }
   }
   ```

### C. New Persistence Adapter

1. **Create directory**: `src/Persistence/NewAdapter/`

2. **Implement repositories**:
   ```php
   // Implements LedgerRepository
   // Implements HistoryRepository
   ```

3. **Create schema**:
   ```php
   // Implements DatabaseSchema
   ```

4. **Add tests**:
   ```php
   // tests/Unit/Persistence/NewAdapter/
   ```

### D. New Domain Behavior

1. **Add to interface** (if public API):
   ```php
   // src/LedgerInterface.php
   public function newBehavior(...): LedgerInterface;
   ```

2. **Write unit test**:
   ```php
   // tests/Unit/LedgerTest.php
   public function test_new_behavior_...(): void
   ```

3. **Implement in Ledger**:
   ```php
   // src/Ledger.php
   public function newBehavior(...): LedgerInterface { }
   ```

4. **Add feature test if complex**:
   ```php
   // tests/Feature/NewBehaviorIntegrationTest.php
   ```

## Checklist

- [ ] Feature is well-defined
- [ ] Interface designed (if applicable)
- [ ] Unit tests written first
- [ ] Implementation is minimal
- [ ] Feature tests added
- [ ] Documentation updated
- [ ] `composer test` passes
- [ ] Commit message follows convention

## Architecture Reminders

- Domain must not depend on infrastructure
- New external interactions need a port (interface)
- Value objects should be immutable
- Exceptions should be specific
