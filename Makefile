RUNTIME ?= frankenphp-classic
RUNTIMES ?= frankenphp-classic frankenphp-worker roadrunner php-fpm freeunit
TARGETS ?= home postgres-orders
MODE ?= ramp

BENCHMARK_COMPOSE := docker compose \
	-p yii3-benchmarks-$(RUNTIME) \
	-f docker/benchmarks.compose.yml \
	--profile $(RUNTIME)

.DEFAULT_GOAL := help

.PHONY: help runtime-up runtime-down bench bench-db bench-all bench-report test composer-update generate-pgsql-dump

help: ## List available commands.
	@awk 'BEGIN { printf "Usage: make <target> [VARIABLE=value]\n\n" } \
	/^[a-zA-Z_-]+:.*##/ { \
		split($$0, parts, "##"); \
		target = parts[1]; sub(/:.*/, "", target); \
		description = parts[2]; sub(/^[[:space:]]+/, "", description); \
		printf "  \033[36m%-22s\033[0m %s\n", target, description; \
	}' $(MAKEFILE_LIST)

runtime-up: ## Build and start RUNTIME for manual inspection on port 9991.
	$(BENCHMARK_COMPOSE) up -d --build --wait

runtime-down: ## Stop RUNTIME and remove its isolated database/cache volumes.
	$(BENCHMARK_COMPOSE) down --volumes --remove-orphans

bench: ## Benchmark / on one RUNTIME.
	RUNTIMES="$(RUNTIME)" TARGETS=home MODE="$(MODE)" ./tools/run-benchmark-suite.sh

bench-db: ## Benchmark /postgres/orders on one RUNTIME.
	RUNTIMES="$(RUNTIME)" TARGETS=postgres-orders MODE="$(MODE)" ./tools/run-benchmark-suite.sh

bench-all: ## Benchmark TARGETS on all selected RUNTIMES and generate one report.
	RUNTIMES="$(RUNTIMES)" TARGETS="$(TARGETS)" MODE="$(MODE)" ./tools/run-benchmark-suite.sh

bench-report: ## Regenerate a report; use INPUT="path [path ...]" and optionally OUTPUT=path.
	./tools/render-benchmark-report.sh $(if $(OUTPUT),--output "$(OUTPUT)") $(INPUT)

test: ## Run the unit test suite using installed Composer dependencies.
	./vendor/bin/codecept run Unit --no-interaction

composer-update: ## Update Composer dependencies, ignoring the host-only missing pdo_pgsql extension.
	composer update --ignore-platform-req=ext-pdo_pgsql

generate-pgsql-dump: ## Regenerate the seeded PostgreSQL benchmark data.
	php tools/generate-pgsql-dump.php
