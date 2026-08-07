#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BASE_URL="${BASE_URL:-http://localhost:9991}"
TARGET_PATH="${TARGET_PATH:?TARGET_PATH is required}"
TARGET_NAME="${TARGET_NAME:?TARGET_NAME is required}"
BENCH_NAME="${BENCH_NAME:-FrankenPHP classic}"
MODE="${MODE:-ramp}"
CAPTURE_METRICS="${CAPTURE_METRICS:-1}"
OUTPUT_ROOT="${OUTPUT_ROOT:-$ROOT_DIR/runtime/benchmarks}"
COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:?COMPOSE_PROJECT_NAME is required}"
DOCKER_STATS_INTERVAL="${DOCKER_STATS_INTERVAL:-1}"
DOCKER_STATS_APP_SERVICES="${DOCKER_STATS_APP_SERVICES:-app}"
DOCKER_STATS_SERVICES="${DOCKER_STATS_SERVICES:-postgres valkey}"
PREFLIGHT_TIMEOUT="${PREFLIGHT_TIMEOUT:-10}"
RATE="${RATE:-10000}"
DURATION="${DURATION:-160s}"
THREADS="${THREADS:-$(nproc)}"
CONNECTIONS="${CONNECTIONS:-256}"
WRKX_IMAGE="${WRKX_IMAGE:-yii3-benchmarks-wrkx}"
WRKX_REF="${WRKX_REF:-bec57539360771bedc2fc63a48e3746f1b7a9975}"
STAGES="${STAGES:-[{\"target\":5000,\"duration\":\"30s\"},{\"target\":10000,\"duration\":\"30s\"},{\"target\":15000,\"duration\":\"30s\"},{\"target\":20000,\"duration\":\"30s\"},{\"target\":25000,\"duration\":\"30s\"},{\"target\":30000,\"duration\":\"30s\"},{\"target\":40000,\"duration\":\"30s\"},{\"target\":50000,\"duration\":\"30s\"}]}"

duration_seconds() {
    php -r '$v=$argv[1]; preg_match("/^([0-9]+)(ms|s|m|h)$/",$v,$m) || exit(1); echo match($m[2]){"ms"=>max(1,(int)round($m[1]/1000)),"s"=>(int)$m[1],"m"=>(int)$m[1]*60,"h"=>(int)$m[1]*3600};' "$1"
}

sanitize_name() { local value="${1//\//-}"; echo "${value// /-}"; }

stop_sampler() {
    if [[ -n "${SAMPLER_PID:-}" ]] && kill -0 "$SAMPLER_PID" 2>/dev/null; then
        kill "$SAMPLER_PID" 2>/dev/null || true
        wait "$SAMPLER_PID" 2>/dev/null || true
    fi
}

start_sampler() {
    local targets=() service id
    for service in $DOCKER_STATS_APP_SERVICES $DOCKER_STATS_SERVICES; do
        while IFS= read -r id; do
            [[ -n "$id" ]] && targets+=("${service}=${id}")
        done < <(docker ps -q --filter "label=com.docker.compose.project=${COMPOSE_PROJECT_NAME}" --filter "label=com.docker.compose.service=${service}")
    done
    if ((${#targets[@]})); then
        php "$ROOT_DIR/tools/sample-docker-stats.php" "$OUTPUT_DIR/docker-stats.csv" "$DOCKER_STATS_INTERVAL" "${targets[@]}" &
        SAMPLER_PID=$!
    else
        printf 'timestamp,service,cpu_percent,memory_usage_bytes\n' > "$OUTPUT_DIR/docker-stats.csv"
    fi
}

URL="${BASE_URL%/}/${TARGET_PATH#/}"
echo "Preflight: $URL"
[[ "$(curl --silent --show-error --location --max-time "$PREFLIGHT_TIMEOUT" --output /dev/null --write-out '%{http_code}' "$URL")" == 200 ]] || { echo "Preflight failed" >&2; exit 1; }

echo "Building wrkx ${WRKX_REF}..."
docker build --build-arg "WRKX_REF=${WRKX_REF}" -t "$WRKX_IMAGE" -f "$ROOT_DIR/benchmark/Dockerfile.wrkx" "$ROOT_DIR/benchmark"

OUTPUT_DIR=""
SAMPLER_PID=""
specifications=()
if [[ "$CAPTURE_METRICS" == 1 ]]; then
    OUTPUT_DIR="$OUTPUT_ROOT/$(date -u +%Y%m%dT%H%M%SZ)-$(sanitize_name "$BENCH_NAME")-$(sanitize_name "$TARGET_NAME")-${MODE}"
    mkdir -p "$OUTPUT_DIR"
    cat > "$OUTPUT_DIR/metadata.env" <<EOF
BASE_URL=${BASE_URL}
TARGET_NAME=${TARGET_NAME}
TARGET_PATH=${TARGET_PATH}
BENCH_NAME=${BENCH_NAME}
MODE=${MODE}
CAPTURE_METRICS=${CAPTURE_METRICS}
RATE=${RATE}
DURATION=${DURATION}
THREADS=${THREADS}
CONNECTIONS=${CONNECTIONS}
STAGES=${STAGES}
COMPOSE_PROJECT_NAME=${COMPOSE_PROJECT_NAME}
WRKX_REF=${WRKX_REF}
EOF
    start_sampler
    trap stop_sampler EXIT
fi

run_stage() {
    local rate="$1" duration="$2" index="$3" output_file
    output_file="${OUTPUT_DIR:-$(mktemp -d)}/wrkx-${index}.log"
    echo "Running wrkx: rate=$rate duration=$duration threads=$THREADS connections=$CONNECTIONS"
    docker run --rm --network=host -v "$ROOT_DIR/benchmark/wrkx-report.lua:/wrkx-report.lua:ro" "$WRKX_IMAGE" \
        -t "$THREADS" -c "$CONNECTIONS" -d "$duration" -R "$rate" --latency -s /wrkx-report.lua "$URL" | tee "$output_file"
    specifications+=("${rate}:$(duration_seconds "$duration"):${CONNECTIONS}:${output_file}")
}

if [[ "$MODE" == ramp ]]; then
    while IFS=$'\t' read -r rate duration index; do run_stage "$rate" "$duration" "$index"; done < <(
        php -r '$s=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR); foreach($s as $i=>$v){echo (int)$v["target"],"\t",$v["duration"],"\t",$i,"\n";}' "$STAGES"
    )
else
    run_stage "$RATE" "$DURATION" 0
fi

if [[ "$CAPTURE_METRICS" == 1 ]]; then
    stop_sampler
    trap - EXIT
    php "$ROOT_DIR/tools/compile-wrkx-results.php" "$OUTPUT_DIR" "${specifications[@]}"
    echo "Results: $OUTPUT_DIR"
fi
