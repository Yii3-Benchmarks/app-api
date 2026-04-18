#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

BASE_URL="${BASE_URL:-http://localhost:9991}"
TARGET_PATH="${TARGET_PATH:?TARGET_PATH is required}"
TARGET_NAME="${TARGET_NAME:?TARGET_NAME is required}"
BENCH_NAME="${BENCH_NAME:-FrankenPHP classic}"
BENCH_SCRIPT="${BENCH_SCRIPT:-bench.js}"
CAPTURE_METRICS="${CAPTURE_METRICS:-0}"
OUTPUT_ROOT="${OUTPUT_ROOT:-$ROOT_DIR/runtime/benchmarks}"
DOCKER_STATS_INTERVAL="${DOCKER_STATS_INTERVAL:-1}"
DOCKER_STATS_SERVICES="${DOCKER_STATS_SERVICES:-app postgres valkey}"
COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:?COMPOSE_PROJECT_NAME is required}"

RATE="${RATE:-5000}"
DURATION="${DURATION:-160s}"
START_RATE="${START_RATE:-1000}"
TIME_UNIT="${TIME_UNIT:-1s}"
PREALLOCATED_VUS="${PREALLOCATED_VUS:-200}"
MAX_VUS="${MAX_VUS:-2000}"
if [[ -z "${STAGES:-}" ]]; then
    STAGES='[{"target":5000,"duration":"30s"},{"target":10000,"duration":"30s"},{"target":15000,"duration":"30s"},{"target":20000,"duration":"30s"}]'
fi

print_config() {
    echo "Benchmark configuration:"
    echo "  base_url: ${BASE_URL}"
    echo "  bench_name: ${BENCH_NAME}"
    echo "  target_name: ${TARGET_NAME}"
    echo "  target_path: ${TARGET_PATH}"
    echo "  script: ${BENCH_SCRIPT}"
    echo "  preallocated_vus: ${PREALLOCATED_VUS}"
    echo "  max_vus: ${MAX_VUS}"

    if [[ "${BENCH_SCRIPT}" == "bench-ramp.js" ]]; then
        echo "  start_rate: ${START_RATE}"
        echo "  time_unit: ${TIME_UNIT}"
        echo "  stages: ${STAGES}"
    else
        echo "  rate: ${RATE}"
        echo "  duration: ${DURATION}"
    fi
}

sanitize_name() {
    local value="${1}"
    value="${value//\//-}"
    value="${value// /-}"
    echo "${value}"
}

resolve_container_targets() {
    local service
    local container_id
    local resolved=()

    for service in ${DOCKER_STATS_SERVICES}; do
        container_id="$(docker ps -q \
            --filter "label=com.docker.compose.project=${COMPOSE_PROJECT_NAME}" \
            --filter "label=com.docker.compose.service=${service}" | head -n 1)"
        if [[ -n "${container_id}" ]]; then
            resolved+=("${service}=${container_id}")
        fi
    done

    printf '%s\n' "${resolved[@]}"
}

start_stats_sampler() {
    local output_dir="${1}"
    mapfile -t CONTAINER_TARGETS < <(resolve_container_targets)

    if [[ "${#CONTAINER_TARGETS[@]}" -eq 0 ]]; then
        echo "No running compose containers found for project ${COMPOSE_PROJECT_NAME}; skipping docker stats capture." >&2
        return
    fi

    php "${ROOT_DIR}/tools/sample-docker-stats.php" \
        "${output_dir}/docker-stats.csv" \
        "${DOCKER_STATS_INTERVAL}" \
        "${CONTAINER_TARGETS[@]}" &
    SAMPLER_PID=$!
}

stop_stats_sampler() {
    if [[ -n "${SAMPLER_PID:-}" ]] && kill -0 "${SAMPLER_PID}" 2>/dev/null; then
        kill "${SAMPLER_PID}" 2>/dev/null || true
        wait "${SAMPLER_PID}" 2>/dev/null || true
    fi
}

write_metadata() {
    local output_dir="${1}"
    cat > "${output_dir}/metadata.env" <<EOF
BASE_URL=${BASE_URL}
TARGET_NAME=${TARGET_NAME}
TARGET_PATH=${TARGET_PATH}
BENCH_NAME=${BENCH_NAME}
BENCH_SCRIPT=${BENCH_SCRIPT}
CAPTURE_METRICS=${CAPTURE_METRICS}
RATE=${RATE}
DURATION=${DURATION}
START_RATE=${START_RATE}
TIME_UNIT=${TIME_UNIT}
PREALLOCATED_VUS=${PREALLOCATED_VUS}
MAX_VUS=${MAX_VUS}
STAGES=${STAGES}
DOCKER_STATS_INTERVAL=${DOCKER_STATS_INTERVAL}
DOCKER_STATS_SERVICES=${DOCKER_STATS_SERVICES}
COMPOSE_PROJECT_NAME=${COMPOSE_PROJECT_NAME}
EOF
}

compact_k6_metrics() {
    local output_dir="${1}"
    local raw_file="${output_dir}/k6-metrics.raw.json"
    local compact_file="${output_dir}/k6-timeseries.json"

    if [[ ! -f "${raw_file}" ]]; then
        return
    fi

    php "${ROOT_DIR}/tools/compact-k6-metrics.php" "${raw_file}" "${compact_file}"
    rm -f "${raw_file}"
}

print_config

OUTPUT_DIR=""
SAMPLER_PID=""
K6_ARGS=(run)

if [[ "${CAPTURE_METRICS}" == "1" ]]; then
    mkdir -p "${OUTPUT_ROOT}"
    OUTPUT_DIR="${OUTPUT_ROOT}/$(date -u +%Y%m%dT%H%M%SZ)-$(sanitize_name "${BENCH_NAME}")-$(sanitize_name "${TARGET_NAME}")-$(basename "${BENCH_SCRIPT}" .js)"
    mkdir -p "${OUTPUT_DIR}"
    chmod 0777 "${OUTPUT_DIR}"
    write_metadata "${OUTPUT_DIR}"
    start_stats_sampler "${OUTPUT_DIR}"
    trap stop_stats_sampler EXIT
    echo "  output_dir: ${OUTPUT_DIR}"
    K6_ARGS+=(
        --summary-export "/results/summary.json"
        --out "json=/results/k6-metrics.raw.json"
    )
fi

docker_args=(
    run --rm -i --network=host
    -e "BASE_URL=${BASE_URL}"
    -e "TARGET_PATH=${TARGET_PATH}"
    -e "TARGET_NAME=${TARGET_NAME}"
    -e "BENCH_NAME=${BENCH_NAME}"
    -e "RATE=${RATE}"
    -e "DURATION=${DURATION}"
    -e "START_RATE=${START_RATE}"
    -e "TIME_UNIT=${TIME_UNIT}"
    -e "PREALLOCATED_VUS=${PREALLOCATED_VUS}"
    -e "MAX_VUS=${MAX_VUS}"
    -e "STAGES=${STAGES}"
    -v "${ROOT_DIR}/benchmark:/benchmark"
)

if [[ "${CAPTURE_METRICS}" == "1" ]]; then
    docker_args+=(-v "${OUTPUT_DIR}:/results")
fi

docker_args+=(
    grafana/k6
    "${K6_ARGS[@]}"
    "/benchmark/${BENCH_SCRIPT}"
)

docker "${docker_args[@]}"

if [[ "${CAPTURE_METRICS}" == "1" ]]; then
    compact_k6_metrics "${OUTPUT_DIR}"
fi
