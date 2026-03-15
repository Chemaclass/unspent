# Refactoring Assistant

Guide safe refactoring while maintaining tests and architecture. `$ARGUMENTS` describes what to refactor.

## Steps

1. **Verify tests exist** for the code being refactored. If not, write them first.
2. Run `composer phpunit` to confirm green baseline.
3. **Make incremental changes** — one refactoring at a time.
4. Run `composer phpunit` after each change.
5. Run `composer test` when done.
6. Provide conventional commit message.

## Refactoring Patterns

| Smell | Pattern |
|-------|---------|
| Method > 20 lines | Extract method |
| Class > 200 lines | Extract class |
| `string` used as ID | Introduce value object |
| Method uses other class's data | Move method |
| Switch on type | Replace with polymorphism |
| Many parameters | Introduce parameter object |
| Duplicate code | Extract shared method |
| Unused abstraction | Inline or remove |

## Rules

- **No behavior change** — refactoring changes structure only
- **Small steps** — one change at a time, test between each
- **Don't mix** — never refactor and add features simultaneously
- **Never remove tests** to make refactoring "pass"
- Tests must stay green throughout
