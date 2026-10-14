#!/usr/bin/env bash
# Builds the installable plugin ZIP: dist/wp-knowledge-chat-<version>.zip
# Only runtime files are included (no tests, tooling or dev dependencies).
set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="$REPO/plugin/wp-knowledge-chat"
VERSION="$(sed -n 's/^ \* Version: *//p' "$SRC/wp-knowledge-chat.php" | tr -d '[:space:]')"
OUT="$REPO/dist"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$STAGE/wp-knowledge-chat" "$OUT"
cp "$SRC/wp-knowledge-chat.php" "$SRC/uninstall.php" "$SRC/readme.txt" "$STAGE/wp-knowledge-chat/"
cp -R "$SRC/includes" "$SRC/assets" "$SRC/languages" "$STAGE/wp-knowledge-chat/"
find "$STAGE" -name '.DS_Store' -delete

for f in "$STAGE"/wp-knowledge-chat/*.php "$STAGE"/wp-knowledge-chat/includes/*.php "$STAGE"/wp-knowledge-chat/languages/*.php; do php -l "$f" >/dev/null; done

ZIP="$OUT/wp-knowledge-chat-$VERSION.zip"
rm -f "$ZIP"
(cd "$STAGE" && zip -qrX "$ZIP" wp-knowledge-chat)
(cd "$OUT" && shasum -a 256 "$(basename "$ZIP")" > "$(basename "$ZIP").sha256")
echo "Built $ZIP"
unzip -l "$ZIP"
