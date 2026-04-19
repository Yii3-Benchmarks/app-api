#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

output_file="$(php "${ROOT_DIR}/tools/render-benchmark-report.php" "$@")"

if [[ ! -f "${output_file}" ]]; then
    echo "Benchmark report was not created: ${output_file}" >&2
    exit 1
fi

echo "Benchmark report: ${output_file}"
