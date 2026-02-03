# Code Review

Perform a thorough code review focused on architecture, clean code, and best practices.

## Review Checklist

### 1. Architecture Compliance

- [ ] Follows hexagonal architecture (ports & adapters)
- [ ] Dependencies flow inward (infrastructure -> domain)
- [ ] No domain logic in adapters
- [ ] Uses interfaces for external dependencies
- [ ] Value objects are immutable

### 2. SOLID Principles

- [ ] **S**ingle Responsibility: Each class has one reason to change
- [ ] **O**pen/Closed: Open for extension, closed for modification
- [ ] **L**iskov Substitution: Subtypes are substitutable
- [ ] **I**nterface Segregation: No fat interfaces
- [ ] **D**ependency Inversion: Depend on abstractions

### 3. Clean Code

- [ ] Meaningful names (classes, methods, variables)
- [ ] Small, focused functions (max ~20 lines)
- [ ] No magic numbers or strings (use constants)
- [ ] No commented-out code
- [ ] No unnecessary comments (code should be self-documenting)
- [ ] Consistent formatting (enforced by PHP-CS-Fixer)

### 4. PHP 8.4+ Best Practices

- [ ] Uses readonly classes/properties where appropriate
- [ ] Uses constructor property promotion
- [ ] Uses typed properties and return types
- [ ] Uses enums instead of string constants
- [ ] Uses match expressions over switch
- [ ] Uses named arguments for clarity

### 5. Error Handling

- [ ] Specific exceptions (from `src/Exception/` hierarchy)
- [ ] Exceptions thrown early (fail fast)
- [ ] No silent failures or swallowed exceptions
- [ ] Error messages are helpful

### 6. Testing

- [ ] Has corresponding unit tests
- [ ] Tests follow naming convention
- [ ] Tests are independent (no shared state)
- [ ] Tests cover edge cases
- [ ] Tests are readable (Arrange-Act-Assert)

### 7. Security

- [ ] No SQL injection risks (parameterized queries)
- [ ] Input validation at boundaries
- [ ] No hardcoded credentials
- [ ] Safe cryptographic practices (Ed25519)

## Review Output Format

```markdown
## Code Review: {file or feature}

### Summary
{Brief overview of what was reviewed}

### Strengths
- {What's done well}

### Issues

#### Critical
- {Must fix before merge}

#### Major
- {Should fix, impacts quality}

#### Minor
- {Nice to fix, low priority}

### Suggestions
- {Optional improvements}

### Architecture Notes
- {Hexagonal compliance observations}
```

## What to Review

If user provides specific files, review those.
If user asks for general review, focus on:
1. Recent changes (check git diff)
2. Core domain files
3. New features or modifications
