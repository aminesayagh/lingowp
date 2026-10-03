#!/usr/bin/env bash
# Builds the plugin zip, drops it in the backend's plugin/ folder (where the
# public download route picks up the highest version automatically), then
# publishes the same tree to the public distribution repository in release/.
#
# This builds the comment-stripped "client" variant, not the WordPress.org
# submission — customers download this one, and the implementation notes in the
# source are not for them. The WordPress.org archive is built by running
# build-release-zip.sh on its own, with comments left intact for the reviewer.
#
# An earlier client variant was dropped because stripping every comment also
# removed the ones machines read: the phpcs:ignore annotations that keep
# WordPress.org's Plugin Check quiet about deliberately-unprepared SQL, and the
# translators: notes that give the i18n tooling its context. strip-comments.php
# keeps those, plus the plugin header, and refuses to build if any of them are
# lost — see its header for the full list.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RELEASE_DIR="$ROOT_DIR/release"
ARTIFACT_DIR="/home/amine/projects/translangs/backend/plugin"
VERSION="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$ROOT_DIR/lingowp.php" | head -n 1)"

cd "$ROOT_DIR"

# Checked before the suites rather than after, so a dirty tree costs a second
# instead of the full run. build-release-zip.sh repeats it — it has to, since it
# is also run on its own — but by then several minutes have been spent.
if [[ -n "$(git status --porcelain --untracked-files=normal)" ]]; then
  echo "Refusing to deploy: the working tree has uncommitted changes." >&2
  echo "Commit or stash them so the artifact matches a known commit." >&2
  git status --short >&2
  exit 1
fi

if [[ ! -d "$ARTIFACT_DIR" ]]; then
  echo "Plugin artifact directory not found: $ARTIFACT_DIR" >&2
  exit 1
fi

step() {
  echo
  echo "===================================================================="
  echo "==> $1"
  echo "===================================================================="
  shift
  "$@"
}

# Cheapest first, so a broken unit check fails in seconds rather than after the
# Docker scanners have pulled their images.
#
# `npm run build` is deliberately NOT here: it writes into the tracked
# assets/admin/build/, which would dirty the tree and fail the release script's
# own clean check. build-release-zip.sh rebuilds the assets in its temp dir
# anyway, so a broken admin build still fails the deploy, one step later.
#
# `security:plugin-check` is also absent: it shells out to a bare `wp` and needs
# the Plugin Check plugin installed on the site. Run it by hand before a
# WordPress.org submission.
step "Unit checks (PHP, no WordPress)"     composer run --no-interaction test
step "Unit checks (JS)"                    npm test
step "Lint (JS)"                           npm run lint:js
step "PHP cross-version compatibility"     composer run --no-interaction compat:php
step "Security scanners (needs Docker)"    composer run --no-interaction security
step "Integration checks (needs the wordpress container)" \
  composer run --no-interaction test:db

# Stages the client tree into release/ and writes the zip next to it.
LINGOWP_STRIP_COMMENTS=1 "$ROOT_DIR/bin/build-release-zip.sh"

# The backend parses the version out of the filename with -(\d+(\.\d+)*)\.zip$
# and serves the highest match; a name it cannot parse is silently skipped — so
# the "-client" build is copied to the plain versioned name it expects.
cp -f "$RELEASE_DIR/lingowp-${VERSION}-client.zip" "$ARTIFACT_DIR/lingowp-${VERSION}.zip"
echo "==> Deployed: $ARTIFACT_DIR/lingowp-${VERSION}.zip"
echo "==> Publish with: cd $(dirname "$ARTIFACT_DIR") && ./deploy/addons.sh"

# The build only stages release/; committing and pushing happens here, on a
# message typed at the prompt, so nothing reaches the public remote without a
# person naming the release. Every early exit below is 0 on purpose: the backend
# deploy above has already succeeded, and the tree stays staged for a later
# `git commit` by hand.
echo
echo "===================================================================="
echo "==> Public mirror: $RELEASE_DIR"
echo "===================================================================="

if [[ ! -d "$RELEASE_DIR/.git" ]]; then
  echo "No git repository in release/ yet. Set it up once, then re-run deploy:"
  echo "  git -C $RELEASE_DIR init -b main"
  echo "  git -C $RELEASE_DIR remote add origin <public repository URL>"
  exit 0
fi
if [[ ! -t 0 ]]; then
  echo "Not an interactive terminal; skipping the publish. Tree is staged."
  exit 0
fi

git -C "$RELEASE_DIR" add -A
git -C "$RELEASE_DIR" diff --cached --stat | tail -n 1
read -r -p "Commit message for v${VERSION} (empty to skip publishing): " MESSAGE
if [[ -z "$MESSAGE" ]]; then
  echo "==> Skipped. Publish later with: git -C release commit && git -C release push"
  exit 0
fi

if git -C "$RELEASE_DIR" diff --cached --quiet; then
  echo "==> Nothing changed since the last publish; not committing."
else
  git -C "$RELEASE_DIR" commit -m "$MESSAGE"
fi

if git -C "$RELEASE_DIR" rev-parse -q --verify "refs/tags/v${VERSION}" >/dev/null; then
  echo "==> Tag v${VERSION} already exists; leaving it alone."
else
  git -C "$RELEASE_DIR" tag -a "v${VERSION}" -m "$MESSAGE"
fi

git -C "$RELEASE_DIR" push origin HEAD
git -C "$RELEASE_DIR" push origin "v${VERSION}"
echo "==> Published v${VERSION}."
echo "==> Attach $RELEASE_DIR/lingowp-${VERSION}-client.zip to the GitHub release by hand."
