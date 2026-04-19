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
K6_LOG_OUTPUT="${K6_LOG_OUTPUT:-none}"
PREFLIGHT_TIMEOUT="${PREFLIGHT_TIMEOUT:-10}"
PREFLIGHT_MAX_BODY_BYTES="${PREFLIGHT_MAX_BODY_BYTES:-4096}"

RATE="${RATE:-10000}"
DURATION="${DURATION:-160s}"
START_RATE="${START_RATE:-500}"
TIME_UNIT="${TIME_UNIT:-1s}"
PREALLOCATED_VUS="${PREALLOCATED_VUS:-auto}"
MAX_VUS="${MAX_VUS:-auto}"
AUTO_MAX_VUS_LIMIT="${AUTO_MAX_VUS_LIMIT:-500000}"
if [[ -z "${STAGES:-}" ]]; then
    STAGES='[{"target":5000,"duration":"30s"},{"target":10000,"duration":"30s"},{"target":15000,"duration":"30s"},{"target":20000,"duration":"30s"},{"target":25000,"duration":"30s"},{"target":30000,"duration":"30s"},{"target":40000,"duration":"30s"},{"target":50000,"duration":"30s"}]'
fi
VUS_SIZING_MODE="manual"
PEAK_RATE=""

max_target_rate() {
    if [[ "${BENCH_SCRIPT}" == "bench-ramp.js" ]]; then
        php -r '
            $max = (int) ($argv[1] ?? 0);
            $stages = json_decode((string) ($argv[2] ?? "[]"), true);
            if (is_array($stages)) {
                foreach ($stages as $stage) {
                    $max = max($max, (int) ($stage["target"] ?? 0));
                }
            }
            echo $max;
        ' "${START_RATE}" "${STAGES}"
        return
    fi

    echo "${RATE}"
}

autosize_vus() {
    local peak_rate="${1}"
    local bench_script="${2}"
    local auto_max_vus_limit="${3}"

    php -r '
        $peakRate = max(1, (int) ($argv[1] ?? 1));
        $benchScript = (string) ($argv[2] ?? "");
        $autoMaxVusLimit = max(1000, (int) ($argv[3] ?? 500000));

        if ($benchScript === "bench-ramp.js") {
            $preallocated = max(2000, (int) ceil($peakRate / 5));
            $maxVus = max($preallocated * 8, (int) ceil($peakRate * 2));
        } else {
            $preallocated = max(1000, (int) ceil($peakRate / 10));
            $maxVus = max($preallocated * 6, (int) ceil($peakRate * 2));
        }

        $preallocated = min($autoMaxVusLimit, $preallocated);
        $maxVus = min($autoMaxVusLimit, max($maxVus, $preallocated));

        echo $preallocated, " ", $maxVus;
    ' "${peak_rate}" "${bench_script}" "${auto_max_vus_limit}"
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
    read -r auto_preallocated_vus auto_max_vus <<< "$(autosize_vus "${peak_rate}" "${BENCH_SCRIPT}" "${AUTO_MAX_VUS_LIMIT}")"

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
    echo "  auto_max_vus_limit: ${AUTO_MAX_VUS_LIMIT}"
    echo "  k6_log_output: ${K6_LOG_OUTPUT}"

    if [[ "${BENCH_SCRIPT}" == "bench-ramp.js" ]]; then
        echo "  start_rate: ${START_RATE}"
        echo "  time_unit: ${TIME_UNIT}"
        echo "  stages: ${STAGES}"
    else
        echo "  rate: ${RATE}"
        echo "  duration: ${DURATION}"
    fi
}

print_file_excerpt() {
    local file_path="${1}"
    local max_bytes="${2}"

    if [[ ! -s "${file_path}" ]]; then
        return
    fi

    local file_size
    file_size="$(wc -c < "${file_path}" | tr -d '[:space:]')"

    sed 's/^/    /' < <(head -c "${max_bytes}" "${file_path}")

    if (( file_size > max_bytes )); then
        echo "    ... truncated (${file_size} bytes total)"
    fi
}

preflight_check() {
    local url
    local headers_file
    local body_file
    local error_file
    local status_code
    local curl_exit=0

    if ! command -v curl >/dev/null 2>&1; then
        echo "curl is required for benchmark preflight checks." >&2
        exit 1
    fi

    url="${BASE_URL%/}/${TARGET_PATH#/}"
    headers_file="$(mktemp)"
    body_file="$(mktemp)"
    error_file="$(mktemp)"
    trap 'rm -f "${headers_file}" "${body_file}" "${error_file}"' RETURN

    echo "Running preflight check..."
    status_code="$(curl \
        --silent \
        --show-error \
        --location \
        --max-time "${PREFLIGHT_TIMEOUT}" \
        --dump-header "${headers_file}" \
        --output "${body_file}" \
        --write-out '%{http_code}' \
        "${url}" 2>"${error_file}")" || curl_exit=$?

    if (( curl_exit != 0 )); then
        echo "Preflight check failed before benchmark start." >&2
        echo "  url: ${url}" >&2
        echo "  curl_exit: ${curl_exit}" >&2
        if [[ -s "${error_file}" ]]; then
            echo "  curl_error:" >&2
            sed 's/^/    /' "${error_file}" >&2
        fi
        if [[ -s "${headers_file}" ]]; then
            echo "  response_headers:" >&2
            sed 's/^/    /' "${headers_file}" >&2
        fi
        if [[ -s "${body_file}" ]]; then
            echo "  response_body:" >&2
            print_file_excerpt "${body_file}" "${PREFLIGHT_MAX_BODY_BYTES}" >&2
        fi
        exit 1
    fi

    if [[ "${status_code}" != "200" ]]; then
        echo "Preflight check failed before benchmark start." >&2
        echo "  url: ${url}" >&2
        echo "  status: ${status_code}" >&2
        if [[ -s "${headers_file}" ]]; then
            echo "  response_headers:" >&2
            sed 's/^/    /' "${headers_file}" >&2
        fi
        if [[ -s "${body_file}" ]]; then
            echo "  response_body:" >&2
            print_file_excerpt "${body_file}" "${PREFLIGHT_MAX_BODY_BYTES}" >&2
        fi
        exit 1
    fi

    echo "Preflight check passed: ${status_code}"
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
        echo "Stopping Docker stats sampler..."
        kill "${SAMPLER_PID}" 2>/dev/null || true
        wait "${SAMPLER_PID}" 2>/dev/null || true
        echo "Docker stats sampler stopped."
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
AUTO_MAX_VUS_LIMIT=${AUTO_MAX_VUS_LIMIT}
STAGES=${STAGES}
DOCKER_STATS_INTERVAL=${DOCKER_STATS_INTERVAL}
DOCKER_STATS_SERVICES=${DOCKER_STATS_SERVICES}
COMPOSE_PROJECT_NAME=${COMPOSE_PROJECT_NAME}
K6_LOG_OUTPUT=${K6_LOG_OUTPUT}
EOF
}

compact_k6_metrics() {
    local output_dir="${1}"
    local raw_file="${output_dir}/k6-metrics.raw.json"
    local compact_file="${output_dir}/k6-timeseries.json"

    if [[ ! -f "${raw_file}" ]]; then
        return
    fi

    echo "Compacting k6 metrics..."
    php "${ROOT_DIR}/tools/compact-k6-metrics.php" "${raw_file}" "${compact_file}"
    rm -f "${raw_file}"
    echo "k6 metrics compacted: ${compact_file}"
}

print_config
preflight_check

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
    -e "K6_LOG_OUTPUT=${K6_LOG_OUTPUT}"
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

echo "Running k6 benchmark..."
docker "${docker_args[@]}"
echo "k6 benchmark finished."

if [[ "${CAPTURE_METRICS}" == "1" ]]; then
    stop_stats_sampler
    trap - EXIT
fi

if [[ "${CAPTURE_METRICS}" == "1" ]]; then
    compact_k6_metrics "${OUTPUT_DIR}"
fi
