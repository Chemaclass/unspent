# Code Review

Perform a thorough code review. If `$ARGUMENTS` specifies files or a scope, review those. Otherwise review recent changes via `git diff`.

## Checklist

### Architecture
- Hexagonal compliance (no domain → infrastructure dependencies)
- Dependencies flow inward
- External interactions through interfaces
- Value objects are immutable

### SOLID
- SRP: one class = one reason to change
- OCP: extension via interfaces, not modification
- LSP: all implementations are interchangeable
- ISP: focused interfaces
- DIP: domain depends on abstractions

### Clean Code
- Meaningful names
- Small methods (< 20 lines)
- No magic numbers/strings
- No commented-out code
- `declare(strict_types=1)` present

### PHP 8.4+
- `readonly` classes/properties
- Constructor property promotion
- Typed properties and return types
- Enums over string constants
- Match over switch

### Testing
- Corresponding tests exist
- Naming: `test_<action>_<scenario>_<outcome>()`
- Arrange-Act-Assert pattern
- Edge cases covered

## Output Format

```markdown
## Review: {scope}

### Critical (must fix)
### Major (should fix)
### Minor (nice to fix)
### Strengths
```
