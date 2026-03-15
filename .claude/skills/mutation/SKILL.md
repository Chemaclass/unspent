# Mutation Testing Analysis

Run mutation testing with Infection and analyze results. `$ARGUMENTS` can specify a file or directory to focus on.

## Steps

1. Run `composer infection` (threshold: 90%+ MSI)
2. Report key metrics: MSI, Covered MSI, escaped mutants count
3. For each escaped mutant: identify file:line, mutation type, and suggest a test to catch it

## Focused Run

If `$ARGUMENTS` specifies a file:
```bash
vendor/bin/infection --filter={file} --min-msi=90 --min-covered-msi=90 --show-mutations
```

## Report Format

| Metric | Value | Threshold |
|--------|-------|-----------|
| MSI | X% | >= 90% |
| Covered MSI | X% | >= 90% |
| Killed | N | - |
| Escaped | N | - |

### Escaped Mutants

For each escaped mutant:
- **File:line** — mutation description
- **Suggested test:**
```php
public function test_{name}(): void { /* test to catch mutation */ }
```

## Common Mutations

| Mutation | How to catch |
|----------|--------------|
| `++` → `--` | Assert exact values |
| `true` → `false` | Test both branches |
| Remove method call | Assert side effects |
| Change return value | Assert return values |
