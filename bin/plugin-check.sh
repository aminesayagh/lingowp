#!/usr/bin/env bash
# Checks the WordPress.org archive the way the review team does, on the local
# QA site (sites/lingowp-qa, WP_DEBUG on):
#
#   1. unpacks dist/linvara-<v>.zip into the QA site's plugins/linvara/,
#   2. activates it and fails on any output or any debug.log line that
#      comes from Linvara ("activates cleanly with WP_DEBUG"),
#   3. runs Plugin Check (new-submission mode) and fails on any ERROR.
#
# Run by deploy.sh after the archives are built; also runs on its own once
# dist/ has a zip. Report: security-reports/plugin-check.csv.
#
# The zip is unpacked from the host rather than through `wp plugin install`:
# the plugins folder is owned by the host user, so www-data in the container
# cannot write it. The pre-rename `lingowp` copy is only deactivated, never
# deleted — `wp plugin delete` would run its uninstall.php and wipe the QA data.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
QA_DIR="${LINVARA_QA_DIR:-/home/amine/projects/wordpress/sites/lingowp-qa}"
VERSION="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$ROOT_DIR/lingowp.php" | head -n 1)"
ZIP="$ROOT_DIR/dist/linvara-${VERSION}.zip"
REPORT="$ROOT_DIR/security-reports/plugin-check.csv"
PLUGINS="$QA_DIR/site/wp-content/plugins"
LOG="$QA_DIR/site/wp-content/debug.log"

[[ -f "$ZIP" ]] || { echo "No archive at $ZIP — run bin/build-release-zip.sh first." >&2; exit 1; }
[[ -x "$QA_DIR/bin/wp" ]] || { echo "QA site not found: $QA_DIR" >&2; exit 1; }

cd "$QA_DIR"
qa_wp() { ./bin/wp "$@"; }

# Start the QA site if it is down, then wait for the database.
RUNNING="$(docker compose ps --status running --services)"
case "$RUNNING" in
  *wordpress*) ;;
  *) ./bin/start >/dev/null ;;
esac
for _ in $(seq 1 30); do
  qa_wp core is-installed 2>/dev/null && break
  sleep 2
done
qa_wp core is-installed || { echo "QA site did not come up." >&2; exit 1; }

echo "==> Installing $(basename "$ZIP") on the QA site"
if qa_wp plugin is-active lingowp 2>/dev/null; then
  qa_wp plugin deactivate lingowp --quiet  # same LingoWP\ namespace as linvara
fi
if qa_wp plugin is-active linvara 2>/dev/null; then
  qa_wp plugin deactivate linvara --quiet
fi
rm -rf "$PLUGINS/linvara"
unzip -q "$ZIP" -d "$PLUGINS"

echo "==> Activating with WP_DEBUG on"
LOG_BEFORE="$(stat -c %s "$LOG" 2>/dev/null || echo 0)"
ACTIVATE_OUT="$(qa_wp plugin activate linvara 2>&1)" || { echo "$ACTIVATE_OUT" >&2; exit 1; }
# Anything besides WP-CLI's own lines is output the plugin printed.
EXTRA="$(printf '%s\n' "$ACTIVATE_OUT" | grep -vE "^(Plugin 'linvara' activated\.|Success: )" || true)"
# One front-end and one admin-side bootstrap, so runtime notices surface too.
qa_wp eval 'wp_remote_get(home_url("/"), ["sslverify" => false]);' >/dev/null 2>&1 || true
qa_wp eval 'set_current_screen("dashboard"); do_action("admin_init");' >/dev/null 2>&1 || true
NEW_LOG="$(tail -c +"$((LOG_BEFORE + 1))" "$LOG" 2>/dev/null | grep -i "plugins/linvara/" || true)"

if [[ -n "$EXTRA" || -n "$NEW_LOG" ]]; then
  echo "Activation was not clean:" >&2
  [[ -n "$EXTRA" ]] && printf 'Unexpected output:\n%s\n' "$EXTRA" >&2
  [[ -n "$NEW_LOG" ]] && printf 'debug.log:\n%s\n' "$NEW_LOG" >&2
  exit 1
fi
echo "==> Activated cleanly: no output, no debug.log lines from Linvara"

echo "==> Plugin Check (new-submission mode)"
if ! qa_wp plugin is-active plugin-check 2>/dev/null; then
  qa_wp plugin activate plugin-check --quiet
fi
mkdir -p "$(dirname "$REPORT")"
qa_wp plugin check linvara --mode=new --format=csv --fields=type,code,file,line,message > "$REPORT" 2>&1 || true

ERRORS="$(grep -c '^ERROR,' "$REPORT" || true)"
WARNINGS="$(grep -c '^WARNING,' "$REPORT" || true)"
grep -E '^(ERROR|WARNING),' "$REPORT" || true
echo "==> Plugin Check: ${ERRORS} error(s), ${WARNINGS} warning(s). Report: $REPORT"
[[ "$ERRORS" -eq 0 ]]
