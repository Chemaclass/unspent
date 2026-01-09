# Contributing

Want to help? Cool.

## Setup

```bash
git clone https://github.com/chemaclass/unspent.git
cd unspent
composer install
```

## Tests

```bash
vendor/bin/phpunit                    # everything
vendor/bin/phpunit --testsuite Unit   # unit only
vendor/bin/phpunit --testsuite Feature # integration only
```

## Examples

```bash
php example/demo.php              # All features demo
php example/bitcoin-simulation.php # Bitcoin-like scenario
```

The demo shows all features. The simulation walks through a realistic Bitcoin use case.

## Project Layout

```
src/
├── Exception/          # All the ways things can go wrong
├── Ledger.php          # The main thing
├── Output.php          # Value objects
├── Spend.php           # Transactions
└── UnspentSet.php      # Collection of outputs

tests/
├── Unit/               # Test individual pieces
└── Feature/            # Test the whole flow
```

## Before You PR

1. Write tests
2. Make sure `vendor/bin/phpunit` passes
3. Don't break the API without good reason

## Questions?

Open an issue.
