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
# Read the listing once: with pipefail, grep -q closing the pipe early would fail unzip with SIGPIPE.
LISTING="$(unzip -Z1 "$ZIP")"
for f in wp-knowledge-chat.pot wp-knowledge-chat-id_ID.mo wp-knowledge-chat-ko_KR.mo; do
  grep -qx "wp-knowledge-chat/languages/$f" <<<"$LISTING" || fail "ZIP is missing languages/$f"
done
ok "ZIP contains the translations and no tests, vendor, node_modules or .env"

mkdir -p "$TARGET"
[ -d "$TARGET/cache" ] || cp -R "$REPO/.wp-test/cache" "$TARGET/" 2>/dev/null || true
"$HERE/setup-wp.sh" "$TARGET" >/dev/null 2>&1 || fail "could not create the WordPress test install"
# setup-wp.sh links the working copy; replace it with the ZIP.
"$WP" plugin deactivate wp-knowledge-chat >/dev/null
rm "$TARGET/wordpress/wp-content/plugins/wp-knowledge-chat"

# No credentials may ship in the ZIP (Gemini keys, private keys, wp-config secrets).
if unzip -p "$ZIP" | LC_ALL=C grep -aEq 'AIza[0-9A-Za-z_-]{30,}|BEGIN [A-Z ]*PRIVATE KEY|WP_KNOWLEDGE_CHAT_(SHARED_SECRET|INDEX_API_TOKEN)'"'"', *'"'"'[A-Za-z0-9+/=_-]{32,}'"'"''; then fail "ZIP contains something that looks like a credential"; fi
ok "ZIP contains no credential-like strings"

"$WP" plugin install "$ZIP" --activate >/dev/null || fail "install/activate failed"
[ -L "$TARGET/wordpress/wp-content/plugins/wp-knowledge-chat" ] && fail "plugin is still a symlink"
ok "installed and activated from ZIP"

"$WP" option get "$OPTION" --format=json | grep -q '"contact_url":"https:\\/\\/inpartnerglobal.com"' || fail "defaults not created on activation"
ok "activation created default settings"

"$WP" option patch update "$OPTION" greetings en "Package check greeting" >/dev/null
"$WP" transient set wkc_rl_package_check 1 300 >/dev/null
"$WP" plugin deactivate wp-knowledge-chat >/dev/null
"$WP" option get "$OPTION" --format=json | grep -q 'Package check greeting' || fail "deactivation lost settings"
ok "deactivation keeps settings"

# Upgrade from 0.2.0: leads stored in the wkc_leads option move to the wkc_lead post type on activation.
"$WP" option update wkc_leads '[{"id":"a1","created_at":"2026-10-14 08:00:00","name":"Legacy Lead","email":"legacy@example.test","company":"","message":"Hi"}]' --format=json >/dev/null
"$WP" option update wkc_metrics '{"total_queries":3}' --format=json >/dev/null
"$WP" plugin activate wp-knowledge-chat >/dev/null || fail "re-activation failed"
"$WP" option get wkc_leads >/dev/null 2>&1 && fail "legacy lead option not migrated"
[ "$("$WP" post list --post_type=wkc_lead --post_status=private --format=count)" = "1" ] || fail "legacy lead missing after migration"
"$WP" plugin deactivate wp-knowledge-chat >/dev/null
[ "$("$WP" post list --post_type=wkc_lead --post_status=private --format=count)" = "1" ] || fail "deactivation removed leads"
ok "0.2.0 leads migrated on activation and kept on deactivation"

"$WP" plugin uninstall wp-knowledge-chat >/dev/null || fail "uninstall failed"
"$WP" option get "$OPTION" >/dev/null 2>&1 && fail "option still present after uninstall"
[ -z "$("$WP" transient get wkc_rl_package_check 2>/dev/null)" ] || fail "transient still present after uninstall"
[ -e "$TARGET/wordpress/wp-content/plugins/wp-knowledge-chat" ] && fail "plugin files still present after uninstall"
[ "$("$WP" post list --post_type=wkc_lead --post_status=any --format=count)" = "0" ] || fail "leads still present after uninstall"
"$WP" option get wkc_metrics >/dev/null 2>&1 && fail "metrics still present after uninstall"
ok "uninstall removed option, leads, metrics, transients and files"

"$WP" plugin install "$ZIP" --activate >/dev/null || fail "reinstall failed"
"$WP" option get "$OPTION" --format=json | grep -q "Hello! How can I help" || fail "reinstall did not recreate defaults"
ok "reinstall recreates defaults"

if grep -E "(plugins|plugin)/wp-knowledge-chat/" "$TARGET/debug.log" 2>/dev/null; then fail "plugin entries in debug.log"; fi
ok "no plugin notices in debug.log"
echo "Package check passed: $(basename "$ZIP")"
