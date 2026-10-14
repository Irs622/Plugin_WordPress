#!/usr/bin/env bash
# Installs the built plugin ZIP into a fresh, separate local WordPress and
# checks the lifecycle: checksum, install, activate (defaults created),
# deactivate (settings kept), uninstall (option and transients removed),
# reinstall. Uses <repo>/.wp-zip-test; never point it at a real site.
#
# Usage: tests/bin/package-check.sh [path/to/wp-knowledge-chat-X.Y.Z.zip]
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/../../../.." && pwd)"
ZIP="${1:-$(ls -t "$REPO"/dist/wp-knowledge-chat-*.zip | head -1)}"
TARGET="$REPO/.wp-zip-test"
WP="$TARGET/wp"
OPTION=wp_knowledge_chat_settings

fail() { echo "FAIL: $*" >&2; exit 1; }
ok() { echo "ok   $*"; }

(cd "$(dirname "$ZIP")" && shasum -a 256 -c "$(basename "$ZIP").sha256" >/dev/null) || fail "checksum mismatch for $ZIP"
ok "sha256 matches $(basename "$ZIP").sha256"

unzip -Z1 "$ZIP" | grep -qE '(^|/)(tests|node_modules|vendor|\.env)(/|$)' && fail "ZIP contains development or secret files"
ok "ZIP contains no tests, vendor, node_modules or .env"

mkdir -p "$TARGET"
[ -d "$TARGET/cache" ] || cp -R "$REPO/.wp-test/cache" "$TARGET/" 2>/dev/null || true
"$HERE/setup-wp.sh" "$TARGET" >/dev/null 2>&1 || fail "could not create the WordPress test install"
# setup-wp.sh links the working copy; replace it with the ZIP.
"$WP" plugin deactivate wp-knowledge-chat >/dev/null
rm "$TARGET/wordpress/wp-content/plugins/wp-knowledge-chat"

"$WP" plugin install "$ZIP" --activate >/dev/null || fail "install/activate failed"
[ -L "$TARGET/wordpress/wp-content/plugins/wp-knowledge-chat" ] && fail "plugin is still a symlink"
ok "installed and activated from ZIP"

"$WP" option get "$OPTION" --format=json | grep -q '"contact_url":"https:\\/\\/inpartnerglobal.com"' || fail "defaults not created on activation"
ok "activation created default settings"

"$WP" option patch update "$OPTION" greeting_message "Package check greeting" >/dev/null
"$WP" transient set wkc_rl_package_check 1 300 >/dev/null
"$WP" plugin deactivate wp-knowledge-chat >/dev/null
"$WP" option get "$OPTION" --format=json | grep -q 'Package check greeting' || fail "deactivation lost settings"
ok "deactivation keeps settings"

"$WP" plugin uninstall wp-knowledge-chat >/dev/null || fail "uninstall failed"
"$WP" option get "$OPTION" >/dev/null 2>&1 && fail "option still present after uninstall"
[ -z "$("$WP" transient get wkc_rl_package_check 2>/dev/null)" ] || fail "transient still present after uninstall"
[ -e "$TARGET/wordpress/wp-content/plugins/wp-knowledge-chat" ] && fail "plugin files still present after uninstall"
ok "uninstall removed option, transients and files"

"$WP" plugin install "$ZIP" --activate >/dev/null || fail "reinstall failed"
"$WP" option get "$OPTION" --format=json | grep -q "Hello! How can I help" || fail "reinstall did not recreate defaults"
ok "reinstall recreates defaults"

if grep -E "(plugins|plugin)/wp-knowledge-chat/" "$TARGET/debug.log" 2>/dev/null; then fail "plugin entries in debug.log"; fi
ok "no plugin notices in debug.log"
echo "Package check passed: $(basename "$ZIP")"
