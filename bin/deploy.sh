#!/usr/bin/env bash
# Builds the client zip: same production build as the WordPress.org release,
# with PHP comments stripped, then drops it in the backend's plugin/ folder,
# where the public download route picks up the highest version automatically.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ARTIFACT_DIR="/home/amine/projects/translangs/translangs_back/plugin"
VERSION="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$ROOT_DIR/lingowp.php" | head -n 1)"

if [[ ! -d "$ARTIFACT_DIR" ]]; then
  echo "Plugin artifact directory not found: $ARTIFACT_DIR" >&2
  exit 1
fi

"$ROOT_DIR/bin/build-release-zip.sh" --strip-comments

# The backend parses the version out of the filename with -(\d+(\.\d+)*)\.zip$
# and serves the highest match; a name it cannot parse is silently skipped.
mv -f "$ROOT_DIR/dist/lingowp-${VERSION}-client.zip" "$ARTIFACT_DIR/lingowp-${VERSION}.zip"
echo "==> Deployed: $ARTIFACT_DIR/lingowp-${VERSION}.zip"
echo "==> Publish with: cd $(dirname "$ARTIFACT_DIR") && ./deploy/addons.sh"
