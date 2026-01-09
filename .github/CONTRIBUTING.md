# Contributing

## Setup

```bash
git clone https://github.com/chemaclass/unspent.git
cd unspent
composer install
```

## Development

```bash
composer test                          # Run all checks (cs, stan, tests)
vendor/bin/phpunit                     # Tests only
vendor/bin/phpstan analyse             # Static analysis
vendor/bin/php-cs-fixer fix            # Fix code style
```

## Project Structure

```
src/
├── Ledger.php         # Main state container
├── Tx.php             # Transactions (spend inputs, create outputs)
├── CoinbaseTx.php     # Minting transactions
├── Output.php         # Value with ownership lock
├── UnspentSet.php     # UTXO collection
├── Lock/              # Authorization (Owner, PublicKey, NoLock)
└── Exception/         # Domain errors

tests/
├── Unit/              # Individual components
└── Feature/           # Integration scenarios
```

## Before PR

1. Add tests for new functionality
2. Run `composer test` - all checks must pass
3. Keep backward compatibility unless discussed

## Questions

Open an issue.
