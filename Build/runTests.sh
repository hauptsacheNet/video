#!/usr/bin/env bash
set -euo pipefail

#
# Run the e2e tests.
#
# Usage:
#   Build/runTests.sh                # e2e via Docker (or local fallback)
#   Build/runTests.sh -n             # local mode (host PHP + SQLite + local Playwright)
#   Build/runTests.sh -- --headed    # pass extra args to playwright
#

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
TEST_SUITE="e2e"
NO_DOCKER=0
EXTRA_ARGS=()

RUN_ID="typo3-video-tests-$$"
NETWORK_NAME="${RUN_ID}-net"

usage() {
    cat <<EOF
Usage: $(basename "$0") [options] [-- playwright-args]

Options:
    -s <suite>      Test suite (only e2e is supported, default)
    -n, --no-docker Run e2e locally without Docker
    -h, --help      Show this help

Any arguments after -- are passed directly to playwright.

Examples:
    $(basename "$0")                  # E2E via Docker (or local fallback)
    $(basename "$0") -n               # E2E locally
    $(basename "$0") -- --headed      # Headed playwright run
EOF
    exit 0
}

while [ $# -gt 0 ]; do
    case "$1" in
        -s)
            TEST_SUITE="$2"
            shift 2
            ;;
        -n|--no-docker)
            NO_DOCKER=1
            shift
            ;;
        -h|--help)
            usage
            ;;
        --)
            shift
            EXTRA_ARGS+=("$@")
            break
            ;;
        *)
            EXTRA_ARGS+=("$1")
            shift
            ;;
    esac
done

case "${TEST_SUITE}" in
    e2e) ;;
    *) echo "Error: only the 'e2e' test suite is supported." >&2; exit 1 ;;
esac

# Auto-fall back to local mode if Docker is unavailable.
if [ "${NO_DOCKER}" -eq 0 ]; then
    if ! command -v docker >/dev/null 2>&1 || ! docker info >/dev/null 2>&1; then
        echo "Note: docker unavailable, running e2e tests locally (--no-docker)." >&2
        NO_DOCKER=1
    fi
fi

LOCAL_WEB_PID=""
cleanup() {
    set +e
    if [ -n "${LOCAL_WEB_PID}" ]; then
        kill "${LOCAL_WEB_PID}" >/dev/null 2>&1
    fi
    if command -v docker >/dev/null 2>&1; then
        docker rm -f "${RUN_ID}-web" "${RUN_ID}-db" "${RUN_ID}-pw" >/dev/null 2>&1
        docker network rm "${NETWORK_NAME}" >/dev/null 2>&1
    fi
    set -e
}
trap cleanup EXIT

if [ "${NO_DOCKER}" -eq 1 ]; then
    echo "Running E2E tests locally (host PHP + SQLite + Playwright)..."

    command -v php >/dev/null 2>&1 || { echo "Error: php is required for --no-docker mode." >&2; exit 1; }
    command -v composer >/dev/null 2>&1 || { echo "Error: composer is required for --no-docker mode." >&2; exit 1; }
    command -v npx >/dev/null 2>&1 || { echo "Error: npx (Node.js) is required for --no-docker mode." >&2; exit 1; }
    php -r 'exit(extension_loaded("pdo_sqlite")?0:1);' \
        || { echo "Error: PHP extension pdo_sqlite is required for --no-docker mode." >&2; exit 1; }

    cd "${ROOT_DIR}"
    rm -rf var/cache var/log config/system/settings.php config/system/additional.php var/*.db 2>/dev/null || true

    echo "Installing composer dependencies..."
    composer install --no-interaction --prefer-dist --no-scripts -q

    echo "Setting up TYPO3 (SQLite)..."
    vendor/bin/typo3 setup \
        --driver=sqlite \
        --dbname="${ROOT_DIR}/var/sqlite.db" \
        --admin-username=admin \
        --admin-user-password=Admin123! \
        --admin-email=admin@example.com \
        --project-name=video-e2e \
        --server-type=other \
        --no-interaction \
        --force >/dev/null

    php -r '$s=include"config/system/settings.php";$s["SYS"]["trustedHostsPattern"]=".*";$s["SYS"]["devIPmask"]="*";file_put_contents("config/system/settings.php","<?php\nreturn ".var_export($s,true).";\n");'
    rm -rf var/cache

    LOCAL_WEB_HOST="127.0.0.1"
    LOCAL_WEB_PORT="${TYPO3_E2E_PORT:-8080}"
    LOCAL_WEB_URL="http://${LOCAL_WEB_HOST}:${LOCAL_WEB_PORT}"

    echo "Starting PHP built-in web server at ${LOCAL_WEB_URL}..."
    mkdir -p var/log
    php -S "${LOCAL_WEB_HOST}:${LOCAL_WEB_PORT}" -t public/ Build/router.php >"${ROOT_DIR}/var/log/typo3-e2e-web.log" 2>&1 &
    LOCAL_WEB_PID=$!

    echo "Waiting for TYPO3..."
    for i in $(seq 1 60); do
        if ! kill -0 "${LOCAL_WEB_PID}" 2>/dev/null; then
            echo "Web server exited unexpectedly. Logs:" >&2
            tail -30 "${ROOT_DIR}/var/log/typo3-e2e-web.log" >&2
            exit 1
        fi
        if curl -sf "${LOCAL_WEB_URL}/typo3/" -o /dev/null 2>&1; then
            echo "TYPO3 is ready."
            break
        fi
        if [ "$i" -eq 60 ]; then
            echo "TYPO3 web server timeout. Logs:" >&2
            tail -30 "${ROOT_DIR}/var/log/typo3-e2e-web.log" >&2
            exit 1
        fi
        sleep 1
    done

    echo "Running Playwright tests..."
    cd "${ROOT_DIR}/Build"
    if [ ! -d node_modules ]; then
        npm ci
    fi
    npx playwright install chromium
    TYPO3_BASE_URL="${LOCAL_WEB_URL}" CI="${CI:-}" \
        npx playwright test ${EXTRA_ARGS[@]+"${EXTRA_ARGS[@]}"}
    exit 0
fi

echo "Running E2E tests (Docker: MySQL + PHP web server + Playwright)..."

docker run --rm -v "${ROOT_DIR}:/app" -w /app alpine sh -c \
    'rm -rf var/cache var/log config/system/settings.php config/system/additional.php var/*.db' 2>/dev/null || true

docker network create "${NETWORK_NAME}" >/dev/null 2>&1

echo "Starting MySQL..."
docker run --rm -d \
    --name "${RUN_ID}-db" \
    --network "${NETWORK_NAME}" \
    --network-alias db \
    -e MYSQL_ROOT_PASSWORD=root \
    -e MYSQL_DATABASE=typo3 \
    --tmpfs /var/lib/mysql:rw,noexec,nosuid \
    mysql:8.0 >/dev/null

for i in $(seq 1 30); do
    if docker exec "${RUN_ID}-db" mysqladmin ping -h localhost --silent 2>/dev/null; then
        echo "MySQL is ready."
        break
    fi
    [ "$i" -eq 30 ] && { echo "MySQL timeout" >&2; exit 1; }
    sleep 1
done

echo "Starting TYPO3 web server..."
WEB_BOOT_SCRIPT='set -e
rm -rf var/cache var/log
composer install --no-interaction --prefer-dist --no-scripts -q 2>&1 || composer update --no-interaction --prefer-dist --no-scripts -q 2>&1
mkdir -p public
# Wait for MySQL TCP to accept connections (mysqladmin ping on the DB container
# can succeed via socket before the network listener is fully up).
for i in $(seq 1 60); do
    if php -r "exit(@(new mysqli(\"db\", \"root\", \"root\", \"typo3\", 3306))->connect_errno ? 1 : 0);"; then
        break
    fi
    sleep 1
done
vendor/bin/typo3 setup --driver=mysqli --host=db --port=3306 --dbname=typo3 --username=root --password=root --admin-username=admin --admin-user-password=Admin123! --admin-email=admin@example.com --project-name=video-e2e --server-type=other --no-interaction --force
php -r '"'"'$s=include"config/system/settings.php";$s["SYS"]["trustedHostsPattern"]=".*";$s["SYS"]["devIPmask"]="*";file_put_contents("config/system/settings.php","<?php\nreturn ".var_export($s,true).";\n");'"'"'
rm -rf var/cache
exec php -S 0.0.0.0:8080 -t public/ Build/router.php'

docker run --rm -d \
    --name "${RUN_ID}-web" \
    --network "${NETWORK_NAME}" \
    --network-alias web \
    -v "${ROOT_DIR}:/app" \
    -w /app \
    -e "TYPO3_CONTEXT=Development" \
    "chialab/php:8.4" \
    bash -c "$WEB_BOOT_SCRIPT" >/dev/null

echo "Waiting for TYPO3..."
sleep 1
for i in $(seq 1 120); do
    STATUS="$(docker inspect -f '{{.State.Status}}' "${RUN_ID}-web" 2>/dev/null || echo missing)"
    if [ "${STATUS}" = "exited" ] || [ "${STATUS}" = "missing" ]; then
        echo "Web container is ${STATUS}. Logs:" >&2
        docker logs "${RUN_ID}-web" 2>&1 | tail -50 || true
        exit 1
    fi
    if docker exec "${RUN_ID}-web" curl -sf http://localhost:8080/typo3/ -o /dev/null 2>&1; then
        echo "TYPO3 is ready."
        break
    fi
    if [ "$i" -eq 120 ]; then
        echo "TYPO3 web server timeout. Logs:" >&2
        docker logs "${RUN_ID}-web" 2>&1 | tail -50
        exit 1
    fi
    sleep 2
done

echo "Running Playwright tests..."
mkdir -p "${ROOT_DIR}/Build/node_modules"
PLAYWRIGHT_CMD="npm ci --no-audit --no-fund && npx playwright test"
if [ ${#EXTRA_ARGS[@]} -gt 0 ]; then
    # Quote each extra arg for safe expansion inside the shell command string.
    for arg in "${EXTRA_ARGS[@]}"; do
        PLAYWRIGHT_CMD+=" $(printf '%q' "$arg")"
    done
fi
docker run --rm \
    --name "${RUN_ID}-pw" \
    --network "${NETWORK_NAME}" \
    -v "${ROOT_DIR}/Build:/app" \
    -w /app \
    -e "TYPO3_BASE_URL=http://web:8080" \
    -e "CI=${CI:-}" \
    "mcr.microsoft.com/playwright:v1.52.0-noble" \
    /bin/bash -c "${PLAYWRIGHT_CMD}"
