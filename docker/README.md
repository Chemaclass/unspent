# Docker Development Environment

This directory contains the Docker configuration for developing Unspent.

## Quick Start

```bash
# Build the image
make build

# Install dependencies
make install

# Run tests
make test
```

## Available Commands

Run `make help` to see all available commands:

| Command | Description |
|---------|-------------|
| `make build` | Build Docker image |
| `make install` | Install composer dependencies |
| `make shell` | Open interactive shell |
| `make test` | Run all quality checks |
| `make phpunit` | Run PHPUnit tests only |
| `make coverage` | Generate HTML coverage report |
| `make infection` | Run mutation testing |
| `make stan` | Run PHPStan |
| `make csfix` | Fix code style |

## Environment

The container includes:

- **PHP 8.4** (Alpine-based, minimal image)
- **Composer 2** (latest)
- **PCOV** - Fast code coverage (enabled by default)
- **Xdebug** - Debugging (disabled by default)

## Debugging with Xdebug

To enable Xdebug for step debugging:

```bash
docker compose run -e XDEBUG_MODE=debug --rm php composer test
```

Xdebug is configured to connect to `host.docker.internal:9003`.

## Code Coverage

Coverage uses PCOV for speed. Generate reports:

```bash
# HTML report (opens in coverage/index.html)
make coverage

# Text report
docker compose run --rm php composer coverage:text
```

## Mutation Testing

```bash
make infection
```

This runs Infection with PCOV for coverage generation.

## Configuration Files

| File | Purpose |
|------|---------|
| `docker/php/xdebug.ini` | Xdebug configuration |
| `docker/php/pcov.ini` | PCOV configuration |

## Without Docker

If you have PHP 8.4 with PCOV installed locally:

```bash
composer install
composer test
composer coverage
composer infection
```
