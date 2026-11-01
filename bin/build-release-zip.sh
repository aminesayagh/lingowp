#!/usr/bin/env bash
# Builds a production-only, reproducible zip.
#
# Default output is the WordPress.org submission: comments intact, because a
# reviewer reads them and Guideline 4 asks for readable code.
#
# LINGOWP_STRIP_COMMENTS=1 builds the direct-download ("client") variant instead,
# with the plugin's own PHP comments removed so implementation notes do not ship
# to customers. It lands under a "-client" name so the two can never be mixed up.
#
# The two variants differ in more than comments, and deliberately:
#
#   WordPress.org (default) -> dist/      carries admin-ui/ and the build config,
#                                         because WordPress.org requires the source
#                                         that assets/admin/build/ was compiled from.
#   client (STRIP_COMMENTS) -> release/   carries neither. It is not distributed by
#                                         WordPress.org, so it has no source
#                                         obligation, and release/ doubles as the
#                                         working copy of the public distribution
#                                         repository (its own .git, ignored by this
#                                         repository's).
set -euo pipefail

PLUGIN_SLUG="linvara"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$ROOT_DIR/lingowp.php" | head -n 1)"
BUILD_DIR="$(mktemp -d)"
STAGE_PARENT="$(mktemp -d)"
STAGE_DIR="$STAGE_PARENT/$PLUGIN_SLUG"
DIST_DIR="$ROOT_DIR/dist"
RELEASE_DIR="$ROOT_DIR/release"
STRIP_COMMENTS="${LINGOWP_STRIP_COMMENTS:-0}"
VARIANT=""
OUT_DIR="$DIST_DIR"
if [[ "$STRIP_COMMENTS" == "1" ]]; then
  VARIANT="-client"
  OUT_DIR="$RELEASE_DIR"
fi
DEST="$OUT_DIR/${PLUGIN_SLUG}-${VERSION}${VARIANT}.zip"
MANIFEST="$OUT_DIR/${PLUGIN_SLUG}-${VERSION}${VARIANT}.sha256"

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

# Composer's bin proxies (php-parse, export-plural-rules, import-cldr-data) are
# command-line tools nothing in WordPress ever loads. Executables in a plugin
# archive draw questions from reviewers for no benefit.
rm -rf "$BUILD_DIR"/vendor/bin

echo "==> Removing development-only files"
rm -rf "$BUILD_DIR"/.[!.]* "$BUILD_DIR"/..?*
rm -rf \
  "$BUILD_DIR"/node_modules \
  "$BUILD_DIR"/docs \
  "$BUILD_DIR"/phpcs-compat.xml.dist \
  "$BUILD_DIR"/tests \
  "$BUILD_DIR"/bin \
  "$BUILD_DIR"/scripts \
  "$BUILD_DIR"/security-reports \
  "$BUILD_DIR"/phpcs.xml.dist \
  "$BUILD_DIR"/AGENTS.md \
  "$BUILD_DIR"/CLAUDE.md \
  "$BUILD_DIR"/wp \
  "$BUILD_DIR"/test

# Everything under assets/admin/build/ is minified webpack output, and
# assets/switcher/build/ is minified Vite output (source: assets/switcher/src/). WordPress.org
# requires the human-readable source it was compiled from, so the default build
# ships admin-ui/ and the four files needed to rebuild it, and the required-file
# check below refuses to package without them. An earlier release linked to a
# public repository instead; the link 404'd and the submission was pended for it,
# which is why the source now travels inside the archive and depends on no URL.
#
# The client archive drops them: it is served from the plugin's own backend, not
# WordPress.org, and its mirror in release/ is a distribution repository.
#
# composer.json and composer.lock stay in both variants. They describe vendor/,
# which ships everywhere, so a tree carrying nikic/, mck89/ and gettext/ with
# no manifest saying why is exactly what a reader objects to.
if [[ "$STRIP_COMMENTS" == "1" ]]; then
  echo "==> Removing the admin source (client archive)"
  rm -rf \
    "$BUILD_DIR"/admin-ui \
    "$BUILD_DIR"/package.json \
    "$BUILD_DIR"/package-lock.json \
    "$BUILD_DIR"/postcss.config.js \
    "$BUILD_DIR"/webpack.config.js \
    "$BUILD_DIR"/assets/switcher/src \
    "$BUILD_DIR"/vite.config.mjs
fi

REQUIRED=(
  lingowp.php
  readme.txt
  LICENSE
  THIRD-PARTY-NOTICES.txt
  composer.json
  composer.lock
  assets/admin/build/index.js
  assets/switcher/build/switcher.js
  assets/switcher/build/switcher.css
  vendor/woocommerce/action-scheduler/action-scheduler.php
)

# Replaces the old check that curled readme.txt's GitHub link: the source is in
# the archive now, so this verifies it locally and cannot rot.
if [[ "$STRIP_COMMENTS" != "1" ]]; then
  REQUIRED+=(
    admin-ui/index.js
    package.json
    package-lock.json
    webpack.config.js
    postcss.config.js
    assets/switcher/src/index.js
    vite.config.mjs
  )
fi

for required in "${REQUIRED[@]}"
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

# node_modules is created by the npm build above rather than by git archive, so
# nothing upstream fails when it is left behind — it just silently adds ~827 MB.
FORBIDDEN=(node_modules tests templates src/Admin/ResetPageController.php)
if [[ "$STRIP_COMMENTS" == "1" ]]; then
  FORBIDDEN+=(admin-ui assets/switcher/src)
fi

for forbidden in "${FORBIDDEN[@]}"; do
  if [[ -e "$BUILD_DIR/$forbidden" ]]; then
    echo "Release still contains a development-only path: $forbidden" >&2
    exit 1
  fi
done

# WordPress.org does not accept plugins that download or install code from
# anywhere but WordPress.org (Guideline 8, "not accepted plugins" list). An
# add-on installer shipped once and was removed before the first submission;
# this keeps it from coming back unnoticed. Only the plugin's own code is
# checked — vendor/ is Composer's and Action Scheduler legitimately has none.
echo "==> Checking for remote code installation"
if grep -rnE '\b(unzip_file|activate_plugin)\s*\(' "$BUILD_DIR/src" "$BUILD_DIR/lingowp.php" "$BUILD_DIR/functions.php"; then
  echo "Release contains remote-install primitives (see above). WordPress.org rejects these." >&2
  exit 1
fi

# Runs last, after every removal, so it only ever sees files that actually ship,
# and before the timestamp normalisation below so the archive stays reproducible.
if [[ "$STRIP_COMMENTS" == "1" ]]; then
  echo "==> Stripping comments (client archive)"
  php "$ROOT_DIR/bin/strip-comments.php" "$BUILD_DIR"
fi

# The public distribution repository. Its contents are replaced wholesale on
# every build so `git status` in there shows exactly what changed between
# releases; .git is the one thing that survives, because it holds the history
# and the remote. This repository ignores release/, so the two never interleave.
if [[ "$STRIP_COMMENTS" == "1" ]]; then
  echo "==> Refreshing the public mirror: $RELEASE_DIR"
  mkdir -p "$RELEASE_DIR"
  find "$RELEASE_DIR" -mindepth 1 -maxdepth 1 ! -name .git -exec rm -rf {} +
  cp -a "$BUILD_DIR"/. "$RELEASE_DIR"/
  cat > "$RELEASE_DIR/.gitignore" <<'MIRROR_IGNORE'
# The archive is attached to a GitHub release, not committed — a binary of this
# size in every commit would outgrow the source it ships within a few releases.
*.zip
*.sha256
MIRROR_IGNORE
fi

SOURCE_DATE_EPOCH="$(git -C "$ROOT_DIR" show -s --format=%ct HEAD)"
find "$BUILD_DIR" -exec touch -d "@$SOURCE_DATE_EPOCH" {} +
mv "$BUILD_DIR" "$STAGE_DIR"

mkdir -p "$OUT_DIR"
rm -f "$DEST" "$MANIFEST"
echo "==> Creating deterministic archive"
(
  cd "$STAGE_PARENT"
  find "$PLUGIN_SLUG" -type f -print | LC_ALL=C sort | zip -X -q "$DEST" -@
)

sha256sum "$DEST" > "$MANIFEST"

echo "==> Archive: $DEST"
echo "==> Checksum: $MANIFEST"
