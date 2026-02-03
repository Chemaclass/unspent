# Code Quality Check

Run all quality tools and report findings.

## Steps

1. **Run PHP-CS-Fixer (dry run)**:
   ```bash
   composer csrun
   ```
   - Check for code style violations
   - If violations found, offer to fix with `composer csfix`

2. **Run Rector (dry run)**:
   ```bash
   composer rector-dry
   ```
   - Check for code modernization opportunities
   - If changes needed, offer to apply with `composer rector`

3. **Run PHPStan**:
   ```bash
   composer stan
   ```
   - Static analysis at level 2
   - Report any type errors or issues

4. **Run PHPUnit**:
   ```bash
   composer phpunit
   ```
   - All tests must pass
   - Report any failures with context

5. **Summary Report**:
   Present results in a table:

   | Tool | Status | Issues |
   |------|--------|--------|
   | PHP-CS-Fixer | Pass/Fail | count |
   | Rector | Pass/Fail | count |
   | PHPStan | Pass/Fail | count |
   | PHPUnit | Pass/Fail | count |

## Optional Deep Analysis

If user requests deep analysis, also run:

```bash
composer infection
```

Report mutation testing results:
- MSI (Mutation Score Index)
- Covered MSI
- Escaped mutants that need attention
