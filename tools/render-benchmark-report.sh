#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

php "${ROOT_DIR}/tools/render-benchmark-report.php" "$@"
