#!/usr/bin/env bash
# The one delivery command. Runs every suite, then builds the one archive,
# dist/linvara-<v>.zip, and ships that same file everywhere:
#
#   WordPress.org     uploaded by hand (printed at the end)
#   backend           copied to the backend's plugin/ folder
#   public mirror     release/, refreshed with the archive's tree by the build
#
# It carries the human-readable source of both bundles (Guideline 4), and its own
# PHP has its comments stripped. Until 1.6.5 a second, source-free "client"
# archive went to the backend and the mirror; it was dropped after it was
# uploaded to WordPress.org by mistake and failed review for the missing source.
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
# Plugin Check runs after the builds instead (bin/plugin-check.sh), against the
# WordPress.org archive itself on the QA site, not this dev folder.
step "Unit checks (PHP, no WordPress)"     composer run --no-interaction test
step "Unit checks (JS)"                    npm test
step "Lint (JS)"                           npm run lint:js
step "PHP cross-version compatibility"     composer run --no-interaction compat:php
step "Security scanners (needs Docker)"    composer run --no-interaction security
step "Integration checks (needs the wordpress container)" \
  composer run --no-interaction test:db

# Re-runs npm ci and composer install in its own temp dir: the slow part.
step "Release archive (dist/, mirrored to release/)" "$ROOT_DIR/bin/build-release-zip.sh"

# The mistake that cost a review round: an archive without the source reached
# WordPress.org. build-release-zip.sh refuses to package without it; this
# re-checks the finished file, since that is what gets uploaded.
ZIP="$ROOT_DIR/dist/linvara-${VERSION}.zip"
# Listed into a variable and matched with `case`, deliberately: piping into
# `grep -q` under `set -o pipefail` reports a SUCCESSFUL match as a failure,
# because grep exits on the first hit and unzip then dies of SIGPIPE.
ENTRIES="$(unzip -Z1 "$ZIP")"
for src in linvara/admin-ui/index.js linvara/assets/switcher/src/index.js; do
  case "$ENTRIES" in
    *"$src"*) ;;
    *) echo "The archive has no $src — Guideline 4 needs the source." >&2; exit 1 ;;
  esac
done
echo "==> Archive verified: carries the source of both bundles."

# Before anything is copied out: the WordPress.org archive must activate
# cleanly and pass Plugin Check on the QA site, as the review team checks it.
step "Plugin Check + clean activation (QA site)" "$ROOT_DIR/bin/plugin-check.sh"
# Same archive on the local multisite network: per-site and network
# activation, site creation and deletion, nothing in debug.log.
step "Multisite lifecycle (linvara-multisite network)" "$ROOT_DIR/bin/multisite-check.sh"

# The backend parses the version out of the filename with -(\d+(\.\d+)*)\.zip$
# and serves the highest match; a name it cannot parse is silently skipped — so
# the archive is copied to the lingowp-<v>.zip name it expects.
cp -f "$ZIP" "$ARTIFACT_DIR/lingowp-${VERSION}.zip"
echo "==> Deployed: $ARTIFACT_DIR/lingowp-${VERSION}.zip"
echo "==> Publish with: cd $(dirname "$ARTIFACT_DIR") && ./deploy/addons.sh"
echo
echo "===================================================================="
echo "==> Upload THIS file to WordPress.org: $ZIP"
echo "===================================================================="

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
echo "==> Attach $ZIP to the GitHub release by hand."
