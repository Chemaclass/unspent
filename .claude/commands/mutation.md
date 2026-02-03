# Mutation Testing Analysis

Run mutation testing with Infection and analyze results.

## Steps

1. **Run Infection**:
   ```bash
   composer infection
   ```

   Thresholds:
   - Minimum MSI: 80%
   - Minimum Covered MSI: 80%

2. **Analyze Results**:

   Key metrics to report:
   - **MSI (Mutation Score Index)**: % of mutants killed
   - **Covered MSI**: MSI for covered code only
   - **Escaped Mutants**: Mutations that tests didn't catch

3. **Investigate Escaped Mutants**:

   For each escaped mutant:
   - Identify the file and line
   - Understand what mutation was applied
   - Determine if test coverage is missing
   - Suggest test to catch the mutation

## Report Format

```markdown
## Mutation Testing Report

### Summary
| Metric | Value | Threshold |
|--------|-------|-----------|
| MSI | X% | >= 80% |
| Covered MSI | X% | >= 80% |
| Mutants Killed | X | - |
| Mutants Escaped | X | - |

### Status: PASS/FAIL

### Escaped Mutants Analysis

#### 1. {File}:{Line}
- **Mutation**: {description of change}
- **Why escaped**: {analysis}
- **Suggested test**:
```php
public function test_{name}(): void
{
    // Test to catch this mutation
}
```

### Recommendations
- {Suggestions for improving test coverage}
```

## Common Mutation Types

| Mutation | What it does | How to catch |
|----------|--------------|--------------|
| `Increment` | `++` -> `--` | Assert exact values |
| `TrueValue` | `true` -> `false` | Test both branches |
| `PublicVisibility` | `public` -> `protected` | Call from outside |
| `MethodCall` | Remove method call | Assert side effects |
| `Return` | Change return value | Assert return values |

## When to Use

- Before merging significant changes
- When test coverage seems low
- To identify weak test assertions
- To ensure critical paths are well-tested
