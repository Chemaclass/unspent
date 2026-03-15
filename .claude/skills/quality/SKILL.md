# Code Quality Check

Run all quality tools and report findings with actionable fixes.

## Instructions

Run tools in this order, stop and report on first failure unless `$ARGUMENTS` contains "full" or "all":

1. `composer csrun` — Code style (PSR-12). Auto-fix: `composer csfix`
2. `composer rector-dry` — Code modernization. Auto-fix: `composer rector`
3. `composer stan` — Static analysis (level 2)
4. `composer phpunit` — All tests

Quick alternative: `composer check:quick` (csrun + phpunit only).
Full suite: `composer test` (all four above).

If `$ARGUMENTS` contains "deep" or "mutation", also run:
5. `composer infection` — Mutation testing (90%+ MSI)

## Report Format

| Tool | Status | Issues |
|------|--------|--------|
| CS-Fixer | PASS/FAIL | N |
| Rector | PASS/FAIL | N |
| PHPStan | PASS/FAIL | N |
| PHPUnit | PASS/FAIL | N |

### On Failure
- Style/Rector: auto-fix with `composer csfix && composer rector`, then re-run
- PHPStan: read the file, identify type issue, suggest fix
- Tests: read failing test, suggest minimal fix
