#!/usr/bin/env bash
# Builds a production-only, reproducible zip for WordPress.org review.
set -euo pipefail

PLUGIN_SLUG="lingowp"
# --strip-comments produces the client build: comments removed from PHP.
STRIP_COMMENTS=0
SUFFIX=""
if [[ "${1:-}" == "--strip-comments" ]]; then
  STRIP_COMMENTS=1
  SUFFIX="-client"
fi
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$ROOT_DIR/lingowp.php" | head -n 1)"
BUILD_DIR="$(mktemp -d)"
STAGE_PARENT="$(mktemp -d)"
STAGE_DIR="$STAGE_PARENT/$PLUGIN_SLUG"
DIST_DIR="$ROOT_DIR/dist"
DEST="$DIST_DIR/${PLUGIN_SLUG}-${VERSION}${SUFFIX}.zip"
MANIFEST="$DIST_DIR/${PLUGIN_SLUG}-${VERSION}${SUFFIX}.sha256"

cleanup() {
  rm -rf "$BUILD_DIR" "$STAGE_PARENT"
}
trap cleanup EXIT

if [[ -n "$(git -C "$ROOT_DIR" status --porcelain --untracked-files=normal)" ]]; then
  echo "Release builds require a clean worktree so the archive matches the tagged source." >&2
  exit 1
fi

if [[ -z "$VERSION" ]]; then
  echo "Could not read the plugin version from lingowp.php." >&2
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
  "$BUILD_DIR"/test \
  "$BUILD_DIR"/src/Admin/ResetPageController.php \
  "$BUILD_DIR"/templates/reset.php

if [[ "$STRIP_COMMENTS" == "1" ]]; then
  echo "==> Stripping PHP comments"
  while read -r file; do
    case "$file" in
      # lingowp.php carries the plugin header WordPress parses; functions.php is
      # the documented public API for theme and add-on authors.
      */lingowp.php|*/functions.php) continue ;;
    esac
    # ponytail: skipping a whole file that has translators: notes keeps POT context.
    if grep -q 'translators:' "$file"; then
      continue
    fi
    php -w "$file" > "$file.stripped" && mv "$file.stripped" "$file"
  done < <(find "$BUILD_DIR" -maxdepth 1 -name '*.php'; find "$BUILD_DIR/src" "$BUILD_DIR/templates" -name '*.php')
fi

for required in lingowp.php readme.txt LICENSE THIRD-PARTY-NOTICES.md assets/admin/build/index.js; do
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
