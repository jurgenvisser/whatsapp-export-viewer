#!/bin/zsh
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PORT=8799
URL="http://127.0.0.1:${PORT}/index.php"
LOG_FILE="${SCRIPT_DIR}/.php-server.log"

if lsof -nP -iTCP:${PORT} -sTCP:LISTEN >/dev/null 2>&1; then
    open "${URL}"
    exit 0
fi

cd "${SCRIPT_DIR}"
nohup php -S 127.0.0.1:${PORT} > "${LOG_FILE}" 2>&1 &
sleep 1
open "${URL}"
