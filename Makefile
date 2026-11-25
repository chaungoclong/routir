.DEFAULT_GOAL := help
.NOTPARALLEL: bench
SHELL := /bin/bash

HOST_UID := $(shell id -u)
HOST_GID := $(shell id -g)

# Auto-detect PHP 8.4+ on host; fall back to Docker Compose dev container when unavailable.
# Override manually with DOCKER=1 or DOCKER=0.
PHP_OK := $(shell php -r 'exit(version_compare(PHP_VERSION, "8.4.0", ">=") ? 0 : 1);' 2>/dev/null && echo 1 || echo 0)
DOCKER ?= $(if $(filter 1,$(PHP_OK)),0,1)

COMPOSE := env UID=$(HOST_UID) GID=$(HOST_GID) docker compose
EXEC := $(if $(filter 1,$(DOCKER)),$(COMPOSE) exec -T dev,)
EXEC_TTY := $(if $(filter 1,$(DOCKER)),$(COMPOSE) exec dev,)

ROUTES ?= examples/routes.php
OUT ?= tmp/routir
OPT ?= -O2
PORT ?= 8000
HOST ?= $(if $(filter 1,$(DOCKER)),0.0.0.0,127.0.0.1)
ARGS ?=
BENCH_ARGS ?=

##@ General

.PHONY: help
help: ## Show this help message
	@awk 'BEGIN {FS = ":.*##"; printf "\nUsage:\n  make \033[36m<target>\033[0m [DOCKER=0|1] [ARGS=\"...\"]\n"} /^[a-zA-Z0-9_-]+:.*?##/ { printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2 } /^##@/ { printf "\n\033[1m%s\033[0m\n", substr($$0, 5) } ' $(MAKEFILE_LIST)
	@printf "\nMode: DOCKER=$(DOCKER) (host PHP 8.4+ detected: $(PHP_OK))\n\n"

.PHONY: doctor
doctor: ## Check PHP version, Composer, and Docker availability
	@echo "Host PHP:   $$(php -v 2>/dev/null | head -n 1 || echo 'not installed')"
	@echo "PHP >= 8.4: $(PHP_OK)"
	@echo "Mode:       $$(if [ "$(DOCKER)" = "1" ]; then echo 'Docker (docker compose exec dev)'; else echo 'Local'; fi)"
	@echo "Composer:   $$(composer --version 2>/dev/null || echo 'not installed')"
	@echo "Docker:     $$(docker --version 2>/dev/null || echo 'not installed')"

##@ Docker

.PHONY: up
up: ## Start the PHP 8.4 Docker dev container
	$(COMPOSE) up -d

.PHONY: down
down: ## Stop and remove Docker containers
	$(COMPOSE) down

.PHONY: build-docker
build-docker: ## Build (or rebuild) the Docker dev image
	$(COMPOSE) build

.PHONY: ensure-docker
ensure-docker:
ifeq ($(DOCKER),1)
	@if ! $(COMPOSE) ps --status running --services 2>/dev/null | grep -qx dev; then \
		echo "Docker container 'dev' is not running. Starting with 'make up'..."; \
		$(COMPOSE) up -d; \
	fi
endif

.PHONY: shell
shell: ensure-docker ## Open an interactive bash shell in the dev container
	$(COMPOSE) exec dev bash

.PHONY: ps
ps: ## Show Docker container status
	$(COMPOSE) ps

.PHONY: logs
logs: ## Tail Docker container logs
	$(COMPOSE) logs -f

##@ Dependencies

.PHONY: install
install: ensure-docker ## Install Composer dependencies
	$(EXEC) composer install

.PHONY: update
update: ensure-docker ## Update Composer dependencies
	$(EXEC) composer update

.PHONY: autoload
autoload: ensure-docker ## Regenerate optimized Composer autoloader
	$(EXEC) composer dump-autoload -o

##@ Testing

.PHONY: test
test: ensure-docker ## Run full Pest test suite (supports ARGS="...")
	$(EXEC) vendor/bin/pest $(ARGS)

.PHONY: test-unit
test-unit: ensure-docker ## Run Unit testsuite
	$(EXEC) vendor/bin/pest --testsuite=Unit $(ARGS)

.PHONY: test-integration
test-integration: ensure-docker ## Run Integration testsuite
	$(EXEC) vendor/bin/pest --testsuite=Integration $(ARGS)

.PHONY: test-diff
test-diff: ensure-docker ## Run Differential testsuite (Interpreted vs Compiled O0/O1/O2)
	$(EXEC) vendor/bin/pest --testsuite=Differential $(ARGS)

.PHONY: test-golden
test-golden: ensure-docker ## Run Golden snapshot testsuite
	$(EXEC) vendor/bin/pest --testsuite=Golden $(ARGS)

.PHONY: test-fuzz
test-fuzz: ensure-docker ## Run Fuzz testsuite
	$(EXEC) vendor/bin/pest --testsuite=Fuzz $(ARGS)

.PHONY: coverage
coverage: ensure-docker ## Run Pest with PCOV code coverage
	$(EXEC) php -d pcov.enabled=1 vendor/bin/pest --coverage $(ARGS)

##@ Quality & Analysis

.PHONY: phpstan
phpstan: ensure-docker ## Run PHPStan static analysis (Level 9)
	$(EXEC) vendor/bin/phpstan analyse --no-progress $(ARGS)

.PHONY: phpstan-clear
phpstan-clear: ensure-docker ## Clear PHPStan result cache
	$(EXEC) vendor/bin/phpstan clear-result-cache

.PHONY: phpstan-bench
phpstan-bench: ensure-bench-deps ## Run PHPStan level 9 against the comparison benchmark harness
	$(EXEC) vendor/bin/phpstan analyse --no-progress --level=9 \
		--autoload-file=benchmarks/compare/vendor/autoload.php \
		benchmarks/compare/src benchmarks/compare/bench.php benchmarks/compare/worker.php

.PHONY: lint
lint: ensure-docker ## Run PHP syntax check (php -l) across project files
	$(EXEC) bash -c 'find src tests examples benchmarks -name "*.php" -print0 | xargs -0 -n1 -P4 php -l -n >/dev/null && php -l -n bin/routir >/dev/null && echo "All PHP files passed syntax check."'

.PHONY: qa
qa: test phpstan phpstan-bench examples route-check ## Run all quality gates (Pest, PHPStan, examples, route-check)

##@ Examples & Server

.PHONY: examples
examples: example-interpreted example-compiled ## Run both CLI examples

.PHONY: example-interpreted
example-interpreted: ensure-docker ## Run interpreted CLI example
	$(EXEC) php examples/01-interpreted/run.php

.PHONY: example-compiled
example-compiled: ensure-docker ## Run compiled CLI example
	$(EXEC) php examples/02-compiled/run.php

.PHONY: serve-interpreted
serve-interpreted: ensure-docker ## Start PHP built-in server in Interpreted mode (PORT=8000)
	@echo "Serving Interpreted mode on http://$(HOST):$(PORT)"
	$(EXEC_TTY) php -S $(HOST):$(PORT) examples/01-interpreted/server.php

.PHONY: serve-compiled
serve-compiled: ensure-docker ## Start PHP built-in server in Compiled mode (PORT=8000)
	@echo "Serving Compiled mode on http://$(HOST):$(PORT)"
	$(EXEC_TTY) php -S $(HOST):$(PORT) examples/02-compiled/server.php

##@ Routir CLI

.PHONY: route-check
route-check: ensure-docker ## Validate route definitions (ROUTES=examples/routes.php)
	$(EXEC) php bin/routir check $(ROUTES)

.PHONY: route-list
route-list: ensure-docker ## List registered routes (ROUTES=examples/routes.php)
	$(EXEC) php bin/routir list $(ROUTES)

.PHONY: route-build
route-build: ensure-docker ## Compile routes to artifacts (ROUTES=..., OUT=tmp/routir, OPT=-O2)
	$(EXEC) php bin/routir build $(ROUTES) --output=$(OUT) $(OPT)

.PHONY: route-clear
route-clear: ensure-docker ## Clear compiled route artifacts (OUT=tmp/routir)
	$(EXEC) php bin/routir clear --output=$(OUT)

##@ Benchmarks & Cleanup

.PHONY: ensure-bench-deps
ensure-bench-deps: ensure-docker
	@if [ ! -f benchmarks/compare/vendor/autoload.php ]; then \
		echo "Installing benchmark comparison dependencies..."; \
		$(EXEC) composer --working-dir=benchmarks/compare install --no-interaction; \
	fi

.PHONY: bench
bench: bench-opcache bench-jit bench-no-opcache ## Run isolated benchmark in three OPcache/JIT modes

.PHONY: bench-opcache
bench-opcache: ensure-bench-deps ## Benchmark with OPcache optimizer enabled and JIT disabled
	@echo ">>> OPcache ON, JIT OFF"
	$(EXEC) php -d opcache.enable_cli=1 -d opcache.jit=off -d opcache.jit_buffer_size=0 benchmarks/compare/bench.php $(BENCH_ARGS)

.PHONY: bench-jit
bench-jit: ensure-bench-deps ## Benchmark with OPcache and tracing JIT enabled
	@echo ">>> OPcache ON, tracing JIT ON"
	$(EXEC) php -d opcache.enable_cli=1 -d opcache.jit=tracing -d opcache.jit_buffer_size=64M benchmarks/compare/bench.php $(BENCH_ARGS)

.PHONY: bench-no-opcache
bench-no-opcache: ensure-bench-deps ## Benchmark with CLI OPcache and JIT disabled
	@echo ">>> OPcache OFF, JIT OFF"
	$(EXEC) php -d opcache.enable_cli=0 benchmarks/compare/bench.php $(BENCH_ARGS)

.PHONY: bench-quick
bench-quick: ensure-bench-deps ## Run a short smoke benchmark, not suitable for published results
	$(EXEC) php -d opcache.enable_cli=1 -d opcache.jit=off -d opcache.jit_buffer_size=0 benchmarks/compare/bench.php \
		--iterations=1000 --latency-ms=30 --throughput-ms=30 --repeats=3 --warmup-ms=10 $(BENCH_ARGS)

.PHONY: clean
clean: ## Remove temporary artifacts and tool caches
	rm -rf tmp .phpunit.cache .phpstan.cache
