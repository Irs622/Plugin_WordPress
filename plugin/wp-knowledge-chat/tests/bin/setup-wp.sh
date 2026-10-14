#!/usr/bin/env bash
# Creates a disposable local WordPress install (SQLite, no MySQL needed) for
# integration and end-to-end tests. Never point this at a production site.
#
# Usage: tests/bin/setup-wp.sh [target-dir]   (default: <repo>/.wp-test)
set -euo pipefail

WP_VERSION="${WP_VERSION:-7.1.3}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$HERE/../.." && pwd)"
REPO_DIR="$(cd "$PLUGIN_DIR/../.." && pwd)"
TARGET="${1:-$REPO_DIR/.wp-test}"
WP_DIR="$TARGET/wordpress"
WP_URL="${WP_URL:-http://127.0.0.1:8899}"

mkdir -p "$TARGET/cache"
cd "$TARGET"

if [ ! -f cache/wp-cli.phar ]; then
  curl -fsSL -o cache/wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
fi
WP="php -d error_reporting=24575 $TARGET/cache/wp-cli.phar --path=$WP_DIR"

if [ ! -f "cache/wordpress-$WP_VERSION.zip" ]; then
  curl -fsSL -o "cache/wordpress-$WP_VERSION.zip" "https://wordpress.org/wordpress-$WP_VERSION.zip"
fi
if [ ! -f cache/sqlite-database-integration.zip ]; then
  curl -fsSL -o cache/sqlite-database-integration.zip https://downloads.wordpress.org/plugin/sqlite-database-integration.zip
fi

rm -rf "$WP_DIR"
mkdir -p "$TARGET/extract"
unzip -q -o "cache/wordpress-$WP_VERSION.zip" -d "$TARGET/extract"
mv "$TARGET/extract/wordpress" "$WP_DIR"
unzip -q -o cache/sqlite-database-integration.zip -d "$WP_DIR/wp-content/plugins"
rm -rf "$TARGET/extract"

SQLITE_DIR="$WP_DIR/wp-content/plugins/sqlite-database-integration"
sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$SQLITE_DIR#" -e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
  "$SQLITE_DIR/db.copy" > "$WP_DIR/wp-content/db.php"

cp "$WP_DIR/wp-config-sample.php" "$WP_DIR/wp-config.php"
php -r '
$f = $argv[1]; $c = file_get_contents($f);
$salts = "";
foreach (["AUTH_KEY","SECURE_AUTH_KEY","LOGGED_IN_KEY","NONCE_KEY","AUTH_SALT","SECURE_AUTH_SALT","LOGGED_IN_SALT","NONCE_SALT"] as $k) {
  $c = preg_replace("/define\\(\\s*.$k.,\\s*.put your unique phrase here.\\s*\\);/", "define( \x27$k\x27, \x27" . bin2hex(random_bytes(32)) . "\x27 );", $c);
}
$c = str_replace("define( \x27WP_DEBUG\x27, false );", "define( \x27WP_DEBUG\x27, true );", $c);
$extra = "define( \x27DB_DIR\x27, __DIR__ . \x27/../db\x27 );\ndefine( \x27WP_DEBUG_LOG\x27, __DIR__ . \x27/../debug.log\x27 );\ndefine( \x27WP_DEBUG_DISPLAY\x27, false );\ndefine( \x27WP_ENVIRONMENT_TYPE\x27, \x27local\x27 );\n";
$extra .= "if ( getenv( \x27WKC_BACKEND_URL\x27 ) ) { define( \x27WP_KNOWLEDGE_CHAT_BACKEND_URL\x27, getenv( \x27WKC_BACKEND_URL\x27 ) ); }\n";
$extra .= "if ( getenv( \x27WKC_SHARED_SECRET\x27 ) ) { define( \x27WP_KNOWLEDGE_CHAT_SHARED_SECRET\x27, getenv( \x27WKC_SHARED_SECRET\x27 ) ); }\n";
$c = str_replace("/* That\x27s all, stop editing!", $extra . "/* That\x27s all, stop editing!", $c);
file_put_contents($f, $c);
' "$WP_DIR/wp-config.php"

mkdir -p "$TARGET/db"
ln -sfn "$PLUGIN_DIR" "$WP_DIR/wp-content/plugins/wp-knowledge-chat"

cat > "$TARGET/router.php" <<'PHP'
<?php
// Router for `php -S`: serve static files directly, everything else through WordPress.
$root = getenv('WKC_WP_DIR') ?: __DIR__ . '/wordpress';
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
// Reject traversal; the plugin is symlinked in, so realpath() prefix checks would refuse its assets.
$file = str_contains($path, '..') ? false : $root . $path;
if ($file && is_file($file) && !str_ends_with($file, '.php')) {
    return false;
}
if ($file && is_file($file) && str_ends_with($file, '.php')) {
    $_SERVER['SCRIPT_FILENAME'] = $file;
    $_SERVER['SCRIPT_NAME'] = $path;
    $_SERVER['PHP_SELF'] = $path;
    chdir(dirname($file));
    require $file;
    return;
}
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
chdir($root);
require $root . '/index.php';
PHP

$WP core install --url="$WP_URL" --title="WKC Test Site" --admin_user=admin --admin_password=admin-pass-123 --admin_email=admin@example.test --skip-email
$WP rewrite structure '/%postname%/' --hard >/dev/null
$WP plugin activate sqlite-database-integration >/dev/null 2>&1 || true
$WP user create editor editor@example.test --role=editor --user_pass=editor-pass-123 >/dev/null
$WP user create subscriber subscriber@example.test --role=subscriber --user_pass=subscriber-pass-123 >/dev/null
$WP post create --post_type=page --post_title="Contact" --post_name=contact --post_status=publish >/dev/null

echo "WordPress $($WP core version) ready at $WP_DIR (url $WP_URL)"
