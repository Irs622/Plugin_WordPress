#!/usr/bin/env bash
# Full local end-to-end run: mock Gemini -> backend -> WordPress (php -S) -> HTTP + browser checks.
# Prerequisites: tests/bin/setup-wp.sh has been run; `npm install` in backend/ and plugin tests/.
# Everything runs on 127.0.0.1 with throwaway secrets. Nothing touches production.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TESTS="$(cd "$HERE/.." && pwd)"
REPO="$(cd "$TESTS/../../.." && pwd)"
WPT="${WKC_WP_TEST_DIR:-$REPO/.wp-test}"
LOGS="$WPT/logs"
mkdir -p "$LOGS"

export WKC_SHARED_SECRET="e2e-shared-secret-$(openssl rand -hex 24)"
export E2E_GEMINI_KEY="AIzaE2E-fake-key-$(openssl rand -hex 8)"
export WKC_BACKEND_URL="http://127.0.0.1:8787"
export WP_URL="http://127.0.0.1:8899"
export WP_CLI="$WPT/wp"
export SCREENSHOT_DIR="${SCREENSHOT_DIR:-$REPO/docs/screenshots}"

PIDS=()
cleanup() {
  for p in "${PIDS[@]:-}"; do pkill -P "$p" 2>/dev/null || true; kill "$p" 2>/dev/null || true; done
}
trap cleanup EXIT

wait_for() { for _ in $(seq 1 100); do curl -fsS -o /dev/null "$1" 2>/dev/null && return 0; sleep 0.1; done; echo "Timed out waiting for $1" >&2; return 1; }

(cd "$REPO/backend" && npm run build --silent)
cp "$REPO/backend/data/kb.json" "$WPT/kb-e2e.json"

for port in 8790 8787 8899; do
  if lsof -nP -iTCP:"$port" -sTCP:LISTEN >/dev/null 2>&1; then echo "Port $port is already in use; stop the old process first." >&2; exit 1; fi
done

node "$REPO/backend/dist/scripts/mock-gemini.js" 8790 > "$LOGS/mock-gemini.log" 2>&1 & PIDS+=($!)

(cd "$REPO/backend" && exec env \
  GEMINI_API_KEY="$E2E_GEMINI_KEY" GEMINI_API_BASE_URL="http://127.0.0.1:8790" GEMINI_TIMEOUT_MS=2000 \
  CHAT_SHARED_SECRET="$WKC_SHARED_SECRET" INDEX_API_TOKEN="e2e-index-token-$(openssl rand -hex 24)" \
  KB_PATH="$WPT/kb-e2e.json" PORT=8787 RATE_LIMIT_PER_CLIENT_PER_MINUTE=100 LOG_LEVEL=info \
  node dist/src/server.js > "$LOGS/backend.log" 2>&1) & PIDS+=($!)

WKC_WP_DIR="$WPT/wordpress" PHP_CLI_SERVER_WORKERS=4 php -d error_reporting=24575 -S 127.0.0.1:8899 -t "$WPT/wordpress" "$WPT/router.php" > "$LOGS/php-server.log" 2>&1 & PIDS+=($!)

wait_for "http://127.0.0.1:8790/" || true
wait_for "http://127.0.0.1:8787/api/health"
wait_for "$WP_URL/wp-login.php"

"$WP_CLI" plugin is-active wp-knowledge-chat || "$WP_CLI" plugin activate wp-knowledge-chat
"$WP_CLI" transient delete --all >/dev/null

echo "== HTTP end-to-end checks =="
node --test --test-reporter=spec "$HERE/http-check.mjs"
"$WP_CLI" transient delete --all >/dev/null

echo "== Browser checks (headless Chrome) =="
(cd "$TESTS" && node e2e/browser-check.mjs)

echo "== Log review =="
# Match the plugin's own files (symlinked or real path) and namespace, not any path that merely contains the name.
if grep -E "(plugins|plugin)/wp-knowledge-chat/(wp-knowledge-chat\.php|uninstall\.php|includes/|assets/)|WP_Knowledge_Chat\\\\" "$WPT/debug.log" 2>/dev/null; then
  echo "Plugin-related entries found in debug.log" >&2; exit 1
fi
if grep -F "$WKC_SHARED_SECRET" "$LOGS"/*.log || grep -F "$E2E_GEMINI_KEY" "$LOGS/backend.log"; then
  echo "A secret appeared in logs" >&2; exit 1
fi
echo "No plugin PHP notices in debug.log; no secrets in service logs."
