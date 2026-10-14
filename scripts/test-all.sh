#!/usr/bin/env bash
# Runs every automated check. Requires: Node >= 20.11, PHP >= 8.1 with pdo_sqlite, Composer,
# Google Chrome (for browser checks), and a local test WordPress (tests/bin/setup-wp.sh).
set -euo pipefail
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
T="$REPO/plugin/wp-knowledge-chat/tests"

step() { printf '\n\033[1m== %s ==\033[0m\n' "$1"; }

step "Backend unit + integration tests"
(cd "$REPO/backend" && npm test)

step "Knowledge base validation (approved pages, no excluded content)"
(cd "$REPO/backend" && npm run --silent kb:validate)

step "Backend offline evaluation: design set, then held-out set"
(cd "$REPO/backend" && npm run --silent eval:retrieval && node dist/eval/run-eval.js --mode=retrieval --dataset=eval/holdout.json)

step "Plugin static analysis (WordPress security sniffs + PHP compatibility)"
(cd "$T" && vendor/bin/phpcs --standard=phpcs.xml.dist)

step "Plugin PHPUnit (real WordPress)"
(cd "$T" && vendor/bin/phpunit -c phpunit.xml.dist)

step "Widget tests (jsdom)"
(cd "$T" && npm run --silent test:widget)

step "End-to-end: WordPress + backend + mock Gemini, HTTP and headless Chrome"
"$T/e2e/run.sh"

step "Package plugin ZIP"
"$REPO/scripts/package-plugin.sh" > /dev/null && ls -l "$REPO/dist/"

step "Package lifecycle: checksum, install from ZIP, activate, deactivate, uninstall, reinstall"
"$T/bin/package-check.sh"

printf '\nAll checks passed.\n'
