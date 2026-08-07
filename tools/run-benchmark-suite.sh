#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_FILE="$ROOT_DIR/docker/benchmarks.compose.yml"
RUNTIMES="${RUNTIMES:-frankenphp-classic frankenphp-worker roadrunner php-fpm freeunit}"
TARGETS="${TARGETS:-home postgres-orders}"
OUTPUT_ROOT="${OUTPUT_ROOT:-$ROOT_DIR/runtime/benchmarks/$(date -u +%Y%m%dT%H%M%SZ)-suite}"

runtime_label() {
    case "$1" in
        frankenphp-classic) echo "FrankenPHP classic" ;;
        frankenphp-worker) echo "FrankenPHP worker" ;;
        roadrunner) echo "RoadRunner" ;;
        php-fpm) echo "PHP-FPM + Nginx" ;;
        freeunit) echo "FreeUnit" ;;
        *) echo "$1" ;;
    esac
}

runtime_services() {
    [[ "$1" == php-fpm ]] && echo "php nginx" || echo "$1"
}

cleanup() {
    if [[ -n "${ACTIVE_PROJECT:-}" ]]; then
        docker compose -p "$ACTIVE_PROJECT" -f "$COMPOSE_FILE" --profile "$ACTIVE_RUNTIME" down --volumes --remove-orphans || true
    fi
}
trap cleanup EXIT

mkdir -p "$OUTPUT_ROOT"

for runtime in $RUNTIMES; do
    ACTIVE_RUNTIME="$runtime"
    ACTIVE_PROJECT="yii3-benchmarks-${runtime}"
    echo "=== Building and starting $(runtime_label "$runtime") ==="
    docker compose -p "$ACTIVE_PROJECT" -f "$COMPOSE_FILE" --profile "$runtime" up -d --build --wait

    for target in $TARGETS; do
        case "$target" in
            home) target_path=/ ; target_name=home ; suffix="" ;;
            postgres-orders) target_path=/postgres/orders ; target_name=postgres-orders ; suffix=" DB" ;;
            *) echo "Unknown target: $target" >&2; exit 1 ;;
        esac

        BASE_URL=http://localhost:9991 \
        TARGET_PATH="$target_path" \
        TARGET_NAME="$target_name" \
        BENCH_NAME="$(runtime_label "$runtime")${suffix}" \
        MODE="${MODE:-ramp}" \
        CAPTURE_METRICS=1 \
        OUTPUT_ROOT="$OUTPUT_ROOT" \
        COMPOSE_PROJECT_NAME="$ACTIVE_PROJECT" \
        DOCKER_STATS_APP_SERVICES="$(runtime_services "$runtime")" \
        "$ROOT_DIR/tools/run-wrkx-benchmark.sh"
    done

    docker compose -p "$ACTIVE_PROJECT" -f "$COMPOSE_FILE" --profile "$runtime" down --volumes --remove-orphans
    ACTIVE_PROJECT=""
done

"$ROOT_DIR/tools/render-benchmark-report.sh" --output "$OUTPUT_ROOT/report.html" "$OUTPUT_ROOT"
echo "Suite report: $OUTPUT_ROOT/report.html"
