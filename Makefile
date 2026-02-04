.PHONY: help build up down shell test coverage infection stan csfix rector clean

# Default target
help:
	@echo "Unspent Development Commands"
	@echo "============================"
	@echo ""
	@echo "Setup:"
	@echo "  make build      Build Docker image"
	@echo "  make install    Install dependencies"
	@echo ""
	@echo "Development:"
	@echo "  make shell      Open shell in container"
	@echo "  make up         Start container in background"
	@echo "  make down       Stop container"
	@echo ""
	@echo "Testing:"
	@echo "  make test       Run all quality checks"
	@echo "  make phpunit    Run PHPUnit tests only"
	@echo "  make coverage   Generate code coverage report"
	@echo "  make infection  Run mutation testing"
	@echo ""
	@echo "Code Quality:"
	@echo "  make stan       Run PHPStan"
	@echo "  make csfix      Run PHP CS Fixer"
	@echo "  make rector     Run Rector"
	@echo ""
	@echo "Cleanup:"
	@echo "  make clean      Remove build artifacts"

# Docker commands
build:
	docker compose build

up:
	docker compose up -d php

down:
	docker compose down

shell:
	docker compose run --rm php /bin/sh

install:
	docker compose run --rm php composer install

# Testing commands
test:
	docker compose run --rm php composer test

phpunit:
	docker compose run --rm php composer phpunit

coverage:
	docker compose run --rm php composer coverage

infection:
	docker compose run --rm php composer infection

# Code quality commands
stan:
	docker compose run --rm php composer stan

csfix:
	docker compose run --rm php composer csfix

csrun:
	docker compose run --rm php composer csrun

rector:
	docker compose run --rm php composer rector

rector-dry:
	docker compose run --rm php composer rector-dry

# Cleanup
clean:
	docker compose down -v
	rm -rf .infection-cache
	rm -rf .php-cs-fixer.cache
	rm -rf .phpunit.cache
	rm -rf coverage
	rm -f infection.log
