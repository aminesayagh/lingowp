#!/usr/bin/env bash
# Builds a production-only, reproducible zip for WordPress.org review.
set -euo pipefail

PLUGIN_SLUG="translangs"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$ROOT_DIR/translangs.php" | head -n 1)"
BUILD_DIR="$(mktemp -d)"
STAGE_PARENT="$(mktemp -d)"
STAGE_DIR="$STAGE_PARENT/$PLUGIN_SLUG"
DIST_DIR="$ROOT_DIR/dist"
DEST="$DIST_DIR/${PLUGIN_SLUG}-${VERSION}.zip"
MANIFEST="$DIST_DIR/${PLUGIN_SLUG}-${VERSION}.sha256"

cleanup() {
  rm -rf "$BUILD_DIR" "$STAGE_PARENT"
}
trap cleanup EXIT

if [[ -n "$(git -C "$ROOT_DIR" status --porcelain --untracked-files=normal)" ]]; then
  echo "Release builds require a clean worktree so the archive matches the tagged source." >&2
  exit 1
fi

if [[ -z "$VERSION" ]]; then
  echo "Could not read the plugin version from translangs.php." >&2
  exit 1
fi

STABLE_TAG="$(sed -n 's/^Stable tag:[[:space:]]*//p' "$ROOT_DIR/readme.txt" | head -n 1)"
PACKAGE_VERSION="$(sed -n 's/^[[:space:]]*\"version\":[[:space:]]*\"\([^\"]*\)\".*/\1/p' "$ROOT_DIR/package.json" | head -n 1)"
if [[ "$VERSION" != "$STABLE_TAG" || "$VERSION" != "$PACKAGE_VERSION" ]]; then
  echo "Version mismatch: plugin=$VERSION readme=$STABLE_TAG package=$PACKAGE_VERSION" >&2
  exit 1
fi

echo "==> Exporting tracked source at HEAD"
git -C "$ROOT_DIR" archive --format=tar HEAD | tar -x -C "$BUILD_DIR"

echo "==> Rebuilding human-readable admin source"
npm ci --ignore-scripts --prefix "$BUILD_DIR" >/dev/null
npm run build --prefix "$BUILD_DIR"

echo "==> Installing production Composer dependencies"
composer install --no-dev --optimize-autoloader --no-interaction \
  --working-dir="$BUILD_DIR" >/dev/null

echo "==> Removing development-only files"
rm -rf "$BUILD_DIR"/.[!.]* "$BUILD_DIR"/..?*
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
  "$BUILD_DIR"/webpack.config.js \
  "$BUILD_DIR"/phpcs.xml.dist \
  "$BUILD_DIR"/AGENTS.md \
  "$BUILD_DIR"/CLAUDE.md \
  "$BUILD_DIR"/wp \
  "$BUILD_DIR"/test

for required in translangs.php readme.txt LICENSE THIRD-PARTY-NOTICES.md assets/admin/build/index.js; do
  if [[ ! -f "$BUILD_DIR/$required" ]]; then
    echo "Release is missing required file: $required" >&2
    exit 1
  fi
done

SOURCE_DATE_EPOCH="$(git -C "$ROOT_DIR" show -s --format=%ct HEAD)"
find "$BUILD_DIR" -exec touch -d "@$SOURCE_DATE_EPOCH" {} +
mv "$BUILD_DIR" "$STAGE_DIR"

mkdir -p "$DIST_DIR"
rm -f "$DEST" "$MANIFEST"
echo "==> Creating deterministic archive"
(
  cd "$STAGE_PARENT"
  find "$PLUGIN_SLUG" -type f -print | LC_ALL=C sort | zip -X -q "$DEST" -@
)

sha256sum "$DEST" > "$MANIFEST"

echo "==> Archive: $DEST"
echo "==> Checksum: $MANIFEST"
