#!/usr/bin/env bash
# Installs the built plugin ZIP network-wide in a fresh, separate multisite and
# checks that uninstall removes the plugin's data from EVERY site, not only the
# main one. Uses <repo>/.wp-ms-test; never point it at a real site.
#
# Usage: tests/bin/multisite-check.sh [path/to/wp-knowledge-chat-X.Y.Z.zip]
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/../../../.." && pwd)"
ZIP="${1:-$(ls -t "$REPO"/dist/wp-knowledge-chat-*.zip | head -1)}"
TARGET="$REPO/.wp-ms-test"
WP="$TARGET/wp"
SITES=(http://127.0.0.1:8899/ http://127.0.0.1:8899/two/)

fail() { echo "FAIL: $*" >&2; exit 1; }
ok() { echo "ok   $*"; }
wp() { "$WP" "$@" 2> >(grep -v Deprecated >&2); }

mkdir -p "$TARGET"
[ -d "$TARGET/cache" ] || cp -R "$REPO/.wp-test/cache" "$TARGET/" 2>/dev/null || true
"$HERE/setup-wp.sh" "$TARGET" >/dev/null 2>&1 || fail "could not create the WordPress test install"
wp core multisite-convert >/dev/null || fail "multisite conversion failed"
wp site create --slug=two --title=Two >/dev/null || fail "could not create the second site"
rm -rf "$TARGET/wordpress/wp-content/plugins/wp-knowledge-chat"
wp plugin install "$ZIP" >/dev/null || fail "install failed"
wp plugin activate wp-knowledge-chat --network >/dev/null || fail "network activation failed"
ok "network-activated in a two-site network"

for url in "${SITES[@]}"; do
  wp --url="$url" option update wkc_metrics '{"total_queries":7}' --format=json >/dev/null
  wp --url="$url" post create --post_type=wkc_lead --post_status=private --post_title=lead --porcelain >/dev/null
  wp --url="$url" transient set wkc_rl_check 1 300 >/dev/null
done
for url in "${SITES[@]}"; do
  [ "$(wp --url="$url" post list --post_type=wkc_lead --post_status=any --format=count)" = "1" ] || fail "lead missing before uninstall on $url"
done
ok "settings, metrics, a lead and a transient exist on both sites"

wp plugin deactivate wp-knowledge-chat --network >/dev/null
wp plugin uninstall wp-knowledge-chat >/dev/null || fail "uninstall failed"
for url in "${SITES[@]}"; do
  wp --url="$url" option get wp_knowledge_chat_settings >/dev/null 2>&1 && fail "settings still present on $url"
  wp --url="$url" option get wkc_metrics >/dev/null 2>&1 && fail "metrics still present on $url"
  [ "$(wp --url="$url" post list --post_type=wkc_lead --post_status=any --format=count)" = "0" ] || fail "leads still present on $url"
  [ -z "$(wp --url="$url" transient get wkc_rl_check 2>/dev/null)" ] || fail "transient still present on $url"
done
ok "uninstall removed settings, metrics, leads and transients on every site"
echo "Multisite check passed: $(basename "$ZIP")"
