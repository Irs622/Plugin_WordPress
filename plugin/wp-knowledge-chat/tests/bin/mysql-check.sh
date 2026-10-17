#!/usr/bin/env bash
# Runs the plugin's PHPUnit suite against a real MySQL 8.0 server (Docker), so
# option storage, the wkc_lead post type, transients and uninstall are checked
# on the database the production host uses, not only on SQLite.
# Uses a throwaway container (wkc-mysql-test, port 33306) and <repo>/.wp-mysql-test.
#
# Usage: tests/bin/mysql-check.sh        (needs Docker running and network for the first image pull)
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TESTS="$(cd "$HERE/.." && pwd)"
REPO="$(cd "$HERE/../../../.." && pwd)"
TARGET="$REPO/.wp-mysql-test"
NAME=wkc-mysql-test
IMAGE="${WKC_MYSQL_IMAGE:-mysql:8.0}"

fail() { echo "FAIL: $*" >&2; exit 1; }
ok() { echo "ok   $*"; }

docker info >/dev/null 2>&1 || fail "Docker is not running"
cleanup() { docker rm -f "$NAME" >/dev/null 2>&1 || true; }
trap cleanup EXIT
cleanup
docker run -d --name "$NAME" -e MYSQL_ROOT_PASSWORD=test -e MYSQL_DATABASE=wkc -p 127.0.0.1:33306:3306 "$IMAGE" >/dev/null
for _ in $(seq 1 60); do
  docker exec "$NAME" mysqladmin ping -h 127.0.0.1 -ptest --silent >/dev/null 2>&1 && break
  sleep 2
done
docker exec "$NAME" mysqladmin ping -h 127.0.0.1 -ptest --silent >/dev/null 2>&1 || fail "MySQL did not become ready"
VERSION="$(docker exec "$NAME" mysql -ptest -N -e 'select version()' 2>/dev/null)"
ok "MySQL $VERSION is running"

mkdir -p "$TARGET"
[ -d "$TARGET/cache" ] || cp -R "$REPO/.wp-test/cache" "$TARGET/" 2>/dev/null || true
WKC_DB=mysql "$HERE/setup-wp.sh" "$TARGET" >/dev/null 2>&1 || fail "could not create the WordPress install on MySQL"
"$TARGET/wp" eval 'global $wpdb; echo defined( "SQLITE_DB_DROPIN_VERSION" ) ? "sqlite" : "mysql " . $wpdb->db_version();' | grep -q '^mysql 8' || fail "WordPress is not using MySQL"
ok "WordPress installed on MySQL"

(cd "$TESTS" && WKC_WP_DIR="$TARGET/wordpress" vendor/bin/phpunit -c phpunit.xml.dist) || fail "PHPUnit failed on MySQL"
ok "PHPUnit passed on MySQL $VERSION"

# Settings, a lead and uninstall on the real database.
"$TARGET/wp" option patch update wp_knowledge_chat_settings greetings en "MySQL check" >/dev/null
"$TARGET/wp" post create --post_type=wkc_lead --post_status=private --post_title=lead >/dev/null
"$TARGET/wp" plugin deactivate wp-knowledge-chat >/dev/null
rm "$TARGET/wordpress/wp-content/plugins/wp-knowledge-chat"
ZIP="$(ls -t "$REPO"/dist/wp-knowledge-chat-*.zip | head -1)"
"$TARGET/wp" plugin install "$ZIP" --activate >/dev/null || fail "ZIP install failed on MySQL"
"$TARGET/wp" plugin deactivate wp-knowledge-chat >/dev/null
"$TARGET/wp" plugin uninstall wp-knowledge-chat >/dev/null || fail "uninstall failed on MySQL"
"$TARGET/wp" option get wp_knowledge_chat_settings >/dev/null 2>&1 && fail "settings still present after uninstall"
[ "$("$TARGET/wp" post list --post_type=wkc_lead --post_status=any --format=count)" = "0" ] || fail "leads still present after uninstall"
ok "ZIP install and uninstall clean on MySQL"
echo "MySQL check passed ($VERSION)"
