#!/usr/bin/env bash
# Builds a production-only, reproducible zip.
#
# Default output is the WordPress.org submission: comments intact, because a
# reviewer reads them and Guideline 4 asks for readable code.
#
# LINGOWP_STRIP_COMMENTS=1 builds the direct-download ("client") variant instead,
# with the plugin's own PHP comments removed so implementation notes do not ship
# to customers. It lands under a "-client" name so the two can never be mixed up.
set -euo pipefail

PLUGIN_SLUG="lingowp"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$ROOT_DIR/lingowp.php" | head -n 1)"
BUILD_DIR="$(mktemp -d)"
STAGE_PARENT="$(mktemp -d)"
STAGE_DIR="$STAGE_PARENT/$PLUGIN_SLUG"
DIST_DIR="$ROOT_DIR/dist"
STRIP_COMMENTS="${LINGOWP_STRIP_COMMENTS:-0}"
VARIANT=""
if [[ "$STRIP_COMMENTS" == "1" ]]; then
  VARIANT="-client"
fi
DEST="$DIST_DIR/${PLUGIN_SLUG}-${VERSION}${VARIANT}.zip"
MANIFEST="$DIST_DIR/${PLUGIN_SLUG}-${VERSION}${VARIANT}.sha256"

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

# Dropped before Composer runs, so the optimized classmap never learns a path
# that is not in the archive. A stale entry is not fatal — Plugin.php guards the
# reset page with class_exists() — but the autoloader would include() the
# missing file and warn on every request, on exactly the WP_DEBUG sites where
# warnings are visible.
#
# All three go together: reset.php is the only thing templates/ holds, and
# ResetPageController is PhpTemplateRenderer's only caller, so leaving the
# renderer behind would ship it pointing at a directory that no longer exists.
echo "==> Removing the debug-only database reset page"
rm -f \
  "$BUILD_DIR"/src/Admin/ResetPageController.php \
  "$BUILD_DIR"/src/Shared/PhpTemplateRenderer.php
rm -rf "$BUILD_DIR"/templates

echo "==> Installing production Composer dependencies"
composer install --no-dev --optimize-autoloader --no-interaction \
  --working-dir="$BUILD_DIR" >/dev/null

# Everything under assets/admin/build/ is minified webpack output, and the
# admin source that produced it is dropped here. WordPress.org allows that only
# because readme.txt's "Human-readable source" section links to the public
# repository and tag holding it — that link is load-bearing, not decoration.
# The SOURCE_URL check further down refuses to build if it stops resolving.
echo "==> Removing development-only files"
rm -rf "$BUILD_DIR"/.[!.]* "$BUILD_DIR"/..?*
rm -rf \
  "$BUILD_DIR"/admin-ui \
  "$BUILD_DIR"/node_modules \
  "$BUILD_DIR"/docs \
  "$BUILD_DIR"/phpcs-compat.xml.dist \
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

for required in \
  lingowp.php \
  readme.txt \
  LICENSE \
  THIRD-PARTY-NOTICES.txt \
  assets/admin/build/index.js \
  vendor/woocommerce/action-scheduler/action-scheduler.php
do
  # Action Scheduler is easy to lose silently: every call site guards with
  # function_exists(), so a release missing it activates cleanly and simply
  # never backfills existing content or crawls the frontend. It went missing
  # once already, when a stale gitlink held vendor/woocommerce/action-scheduler
  # and a committed vendor/composer/installed.json made Composer skip it.
  if [[ ! -f "$BUILD_DIR/$required" ]]; then
    echo "Release is missing required file: $required" >&2
    exit 1
  fi
done

# The archive ships no admin source, so readme.txt's link to it is the only
# thing satisfying the human-readable-source requirement. Check it resolves
# before shipping rather than finding out from a reviewer two weeks later.
SOURCE_URL="$(grep -o 'https://github.com/[^ ]*/tree/[^ ]*' "$BUILD_DIR/readme.txt" | head -n 1 | sed 's/[.,]$//')"
if [[ -z "$SOURCE_URL" ]]; then
  echo "readme.txt has no human-readable source URL for the compiled admin assets." >&2
  exit 1
fi
if [[ "${LINGOWP_ALLOW_UNPUBLISHED_SOURCE:-0}" == "1" ]]; then
  # Escape hatch for dry runs while the repository is still private. The zip it
  # produces is for local testing only — it is not submittable.
  echo "==> WARNING: skipping the source-link check. This archive is NOT submittable."
else
  echo "==> Checking the human-readable source link: $SOURCE_URL"
  if [[ "$(curl -s -o /dev/null -w '%{http_code}' -L --max-time 30 "$SOURCE_URL")" != "200" ]]; then
    echo "The source link in readme.txt does not resolve publicly: $SOURCE_URL" >&2
    echo "Publish the repository and the tag it names before building a release." >&2
    echo "For a local dry run: LINGOWP_ALLOW_UNPUBLISHED_SOURCE=1 $0" >&2
    exit 1
  fi
fi

# node_modules is created by the npm build above rather than by git archive, so
# nothing upstream fails when it is left behind — it just silently adds ~827 MB.
for forbidden in node_modules admin-ui tests templates src/Admin/ResetPageController.php; do
  if [[ -e "$BUILD_DIR/$forbidden" ]]; then
    echo "Release still contains a development-only path: $forbidden" >&2
    exit 1
  fi
done

# Runs last, after every removal, so it only ever sees files that actually ship,
# and before the timestamp normalisation below so the archive stays reproducible.
if [[ "$STRIP_COMMENTS" == "1" ]]; then
  echo "==> Stripping comments (client archive)"
  php "$ROOT_DIR/bin/strip-comments.php" "$BUILD_DIR"
fi

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
