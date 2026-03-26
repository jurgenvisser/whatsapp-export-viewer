#!/bin/zsh
set -euo pipefail

PORT=8799
PIDS="$(lsof -tiTCP:${PORT} -sTCP:LISTEN || true)"

if [ -n "${PIDS}" ]; then
    kill ${PIDS}
fi
