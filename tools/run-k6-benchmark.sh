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

RATE="${RATE:-10000}"
DURATION="${DURATION:-160s}"
START_RATE="${START_RATE:-1000}"
TIME_UNIT="${TIME_UNIT:-1s}"
PREALLOCATED_VUS="${PREALLOCATED_VUS:-auto}"
MAX_VUS="${MAX_VUS:-auto}"
if [[ -z "${STAGES:-}" ]]; then
    STAGES='[{"target":5000,"duration":"15s"},{"target":15000,"duration":"15s"},{"target":25000,"duration":"15s"},{"target":35000,"duration":"15s"},{"target":45000,"duration":"15s"},{"target":55000,"duration":"15s"},{"target":65000,"duration":"15s"},{"target":80000,"duration":"15s"}]'
fi
VUS_SIZING_MODE="manual"
PEAK_RATE=""

max_target_rate() {
    if [[ "${BENCH_SCRIPT}" == "bench-ramp.js" ]]; then
        php -r '
            $max = (int) getenv("START_RATE");
            $stages = json_decode((string) getenv("STAGES"), true);
            if (is_array($stages)) {
                foreach ($stages as $stage) {
                    $max = max($max, (int) ($stage["target"] ?? 0));
                }
            }
            echo $max;
        '
        return
    fi

    echo "${RATE}"
}

autosize_vus() {
    local peak_rate="${1}"

    php -r '
        $peakRate = max(1, (int) ($argv[1] ?? 1));
        $preallocated = max(50, (int) ceil($peakRate / 20));
        $maxVus = max($preallocated * 4, (int) ceil($peakRate / 5));

        echo $preallocated, " ", $maxVus;
    ' "${peak_rate}"
}

resolve_vus_configuration() {
    local peak_rate

    if [[ "${PREALLOCATED_VUS}" != "auto" && "${MAX_VUS}" != "auto" ]]; then
        PEAK_RATE="$(max_target_rate)"
        if (( MAX_VUS < PREALLOCATED_VUS )); then
            echo "MAX_VUS must be greater than or equal to PREALLOCATED_VUS." >&2
            exit 1
        fi
        return
    fi

    peak_rate="$(max_target_rate)"
    read -r auto_preallocated_vus auto_max_vus <<< "$(autosize_vus "${peak_rate}")"

    if [[ "${PREALLOCATED_VUS}" == "auto" ]]; then
        PREALLOCATED_VUS="${auto_preallocated_vus}"
        VUS_SIZING_MODE="auto"
    fi

    if [[ "${MAX_VUS}" == "auto" ]]; then
        MAX_VUS="${auto_max_vus}"
        VUS_SIZING_MODE="auto"
    fi

    if (( MAX_VUS < PREALLOCATED_VUS )); then
        MAX_VUS="${PREALLOCATED_VUS}"
    fi

    PEAK_RATE="${peak_rate}"
}

resolve_vus_configuration

print_config() {
    echo "Benchmark configuration:"
    echo "  base_url: ${BASE_URL}"
    echo "  bench_name: ${BENCH_NAME}"
    echo "  target_name: ${TARGET_NAME}"
    echo "  target_path: ${TARGET_PATH}"
    echo "  script: ${BENCH_SCRIPT}"
    echo "  peak_rate: ${PEAK_RATE}"
    echo "  vus_sizing: ${VUS_SIZING_MODE}"
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
VUS_SIZING_MODE=${VUS_SIZING_MODE}
PEAK_RATE=${PEAK_RATE}
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
