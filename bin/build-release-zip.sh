#!/usr/bin/env bash
# Builds a production-only zip of the plugin for the WordPress.org marketplace.
set -euo pipefail

PLUGIN_SLUG="translangs"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="$(grep -m1 -oP "(?<=Version:\s)[\d.]+" "$ROOT_DIR/translangs.php")"
BUILD_DIR="$(mktemp -d)"
DEST="$ROOT_DIR/${PLUGIN_SLUG}-${VERSION}.zip"

echo "==> Exporting tracked files at HEAD (ignores untracked/gitignored junk)"
git -C "$ROOT_DIR" archive --format=tar HEAD | tar -x -C "$BUILD_DIR"

echo "==> Installing production-only composer deps (no dev tooling in vendor/)"
composer install --no-dev --optimize-autoloader --no-interaction \
  --working-dir="$BUILD_DIR" >/dev/null

echo "==> Stripping dotfiles/dotfolders (AI tooling, IDE config, CI, git metadata — never needed in a WP.org zip)"
rm -rf "$BUILD_DIR"/.[!.]* "$BUILD_DIR"/..?*

echo "==> Stripping dev-only sources not needed at runtime"
rm -rf \
  "$BUILD_DIR"/admin-ui \
  "$BUILD_DIR"/tests \
  "$BUILD_DIR"/bin \
  "$BUILD_DIR"/scripts \
  "$BUILD_DIR"/security-reports \
  "$BUILD_DIR"/composer.json \
  "$BUILD_DIR"/composer.lock \
  "$BUILD_DIR"/package.json \
  "$BUILD_DIR"/package-lock.json \
  "$BUILD_DIR"/postcss.config.js \
  "$BUILD_DIR"/phpcs.xml.dist \
  "$BUILD_DIR"/AGENTS.md \
  "$BUILD_DIR"/CLAUDE.md

echo "==> Zipping (top-level folder = ${PLUGIN_SLUG}, as WordPress.org expects)"
rm -f "$DEST"
PARENT="$(dirname "$BUILD_DIR")"
mv "$BUILD_DIR" "$PARENT/$PLUGIN_SLUG"
(cd "$PARENT" && zip -rq "$DEST" "$PLUGIN_SLUG" -x '*.DS_Store')

rm -rf "$PARENT/$PLUGIN_SLUG"
echo "==> Done: $DEST"
