.PHONY: help \
	build up down shell install \
	test test-fast test-unit test-feature phpunit coverage infection benchmark \
	check check-quick fix stan csfix csrun rector rector-dry \
	examples examples-list examples-reset \
	clean

# ----------------------------------------------------------------------------
# Defaults
# ----------------------------------------------------------------------------

# Runs on the host when it has PHP 8.4+, otherwise through docker compose.
# Force either way with DOCKER=0 or DOCKER=1.
HOST_PHP_OK := $(shell php -r 'echo PHP_VERSION_ID >= 80400 ? 1 : 0;' 2>/dev/null)
HOST_COVERAGE_OK := $(shell php -r 'echo extension_loaded("pcov") || extension_loaded("xdebug") ? 1 : 0;' 2>/dev/null)
DOCKER ?= $(if $(filter 1,$(HOST_PHP_OK)),0,1)
COMPOSE = docker compose run --rm php

ifeq ($(DOCKER),0)
	RUN =
else
	RUN = $(COMPOSE)
endif

# Coverage and mutation testing need pcov or xdebug; use the container when the host has neither.
ifeq ($(DOCKER)$(HOST_COVERAGE_OK),01)
	COVERAGE_RUN =
else
	COVERAGE_RUN = $(COMPOSE)
endif

# ----------------------------------------------------------------------------
# Help
# ----------------------------------------------------------------------------

help:  ## Show this help
	@echo "Unspent — Development Commands"
	@echo "=============================="
	@echo ""
	@echo "Running on: $(if $(RUN),docker,host). Force with DOCKER=0 or DOCKER=1."
	@echo ""
	@awk 'BEGIN {FS = ":.*?## "} \
		/^# ==/ {next} \
		/^[a-zA-Z0-9_-]+:.*?## / {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2} \
		/^##@/ {printf "\n\033[1m%s\033[0m\n", substr($$0, 5)}' $(MAKEFILE_LIST)

##@ Setup
build:  ## Build the Docker image
	docker compose build

up:  ## Start the container in background
	docker compose up -d php

down:  ## Stop the container
	docker compose down

shell:  ## Open an interactive shell in the container
	docker compose run --rm php /bin/sh

install:  ## Install composer dependencies
	$(RUN) composer install

##@ Quality gates
test:  ## Full quality gate (csrun + rector-dry + stan + phpunit)
	$(RUN) composer test

check: test  ## Alias for test

check-quick:  ## Fast pre-commit gate (csrun + phpunit)
	$(RUN) composer check:quick

fix:  ## Apply CS-Fixer and Rector auto-fixes
	$(RUN) composer fix

stan:  ## Run PHPStan
	$(RUN) composer stan

csfix:  ## Apply CS-Fixer changes
	$(RUN) composer csfix

csrun:  ## CS-Fixer dry-run
	$(RUN) composer csrun

rector:  ## Apply Rector changes
	$(RUN) composer rector

rector-dry:  ## Rector dry-run
	$(RUN) composer rector-dry

##@ Tests
phpunit:  ## Run all PHPUnit suites
	$(RUN) composer phpunit

test-fast:  ## Unit tests, stop on first failure
	$(RUN) composer test:fast

test-unit:  ## All unit tests
	$(RUN) composer test:unit

test-feature:  ## Integration tests
	$(RUN) composer test:feature

coverage:  ## Generate HTML coverage report under coverage/
	$(COVERAGE_RUN) composer coverage

infection:  ## Run mutation testing (100% MSI, matches CI)
	$(COVERAGE_RUN) composer infection

benchmark:  ## Run PHPBench benchmarks
	$(RUN) composer benchmark

##@ Examples
examples:  ## List runnable examples
	$(RUN) php example/run

examples-list: examples  ## Alias for examples

##@ Cleanup
clean:  ## Remove caches, coverage, and stop containers
	-docker compose down -v
	rm -rf .infection-cache .php-cs-fixer.cache .phpunit.cache .phpstan-cache .rector-cache
	rm -rf coverage
	rm -f infection.log
