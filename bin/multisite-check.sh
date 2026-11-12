#!/usr/bin/env bash
# Multisite lifecycle check on the local network sites/linvara-multisite
# (subdirectory network, WP_DEBUG on, local-only — never published):
#
#   1. per-site activation on two fresh sites (the WordPress.org finding:
#      every site after the first used to fail on duplicate FK names),
#      and on a site whose WordPress tables are MyISAM (it must activate,
#      without foreign keys, instead of aborting),
#   2. "Network Activate", with one site created before it (set up on its own
#      first load, never in the activation request) and one after,
#   3. deleting those sites drops every table, Linvara's and core's,
#
# and fails on any debug.log line from the run. Each site must end up with its
# 15 tables, 8 FKs, the default options and the first-scan flag.
#
#   bin/multisite-check.sh            the WordPress.org archive in dist/
#   LINVARA_FROM_TREE=1 bin/...       that archive with this checkout's src/
#                                     and lingowp.php on top (uncommitted work)
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
NET_DIR="${LINVARA_MULTISITE_DIR:-/home/amine/projects/wordpress/sites/linvara-multisite}"
VERSION="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$ROOT_DIR/lingowp.php" | head -n 1)"
ZIP="$ROOT_DIR/dist/linvara-${VERSION}.zip"
PLUGINS="$NET_DIR/site/wp-content/plugins"
LOG="$NET_DIR/site/wp-content/debug.log"
CHECK="$NET_DIR/site/lv-multisite-check.php"

[[ -f "$ZIP" ]] || { echo "No archive at $ZIP — run bin/build-release-zip.sh first." >&2; exit 1; }
[[ -x "$NET_DIR/bin/wp" ]] || { echo "Multisite test network not found: $NET_DIR" >&2; exit 1; }

cd "$NET_DIR"
net_wp() { ./bin/wp "$@"; }

RUNNING="$(docker compose ps --status running --services)"
case "$RUNNING" in
  *wordpress*) ;;
  *) ./bin/start >/dev/null ;;
esac
for _ in $(seq 1 30); do net_wp core is-installed --network 2>/dev/null && break; sleep 2; done
# Read only once the network is up: with the containers stopped this exits
# the script silently under set -e.
MAIN="$(net_wp option get home 2>/dev/null)"
MAIN="${MAIN%/}"

echo "==> Installing $(basename "$ZIP")${LINVARA_FROM_TREE:+ + working-tree src/} (v$VERSION)"
if net_wp plugin is-active linvara --network 2>/dev/null; then net_wp plugin deactivate linvara --network --quiet; fi
for url in $(net_wp site list --field=url 2>/dev/null); do
  net_wp plugin deactivate linvara --url="$url" --quiet 2>/dev/null || true
done
rm -rf "$PLUGINS/linvara"
unzip -q "$ZIP" -d "$PLUGINS"
if [[ -n "${LINVARA_FROM_TREE:-}" ]]; then
  rm -rf "$PLUGINS/linvara/src"
  cp -r "$ROOT_DIR/src" "$PLUGINS/linvara/src"
  cp "$ROOT_DIR/lingowp.php" "$ROOT_DIR/readme.txt" "$PLUGINS/linvara/"
fi

# One line per site: the state every check below reads.
cat > "$CHECK" <<'PHP'
<?php
use LingoWP\Database\ConstraintVerifier;
use LingoWP\Database\TranslationMemorySchema;
use LingoWP\Extraction\Structured\InitialDiscoveryScan;
global $wpdb;
$slugs = array_slice($args, 0);
foreach (get_sites(['number' => 0]) as $s) {
    $slug = trim($s->path, '/');
    if ($slugs !== [] && ! in_array($slug, $slugs, true)) { continue; }
    switch_to_blog((int) $s->blog_id);
    $like   = $wpdb->esc_like($wpdb->prefix . 'lingowp_') . '%';
    $tables = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s", $like));
    $fks    = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL AND TABLE_NAME LIKE %s", $like));
    // A site whose database refused foreign keys runs without them (mode '0').
    $fkMode  = TranslationMemorySchema::usesForeignKeys();
    $missing = $fkMode ? (new ConstraintVerifier($wpdb))->findMissing(TranslationMemorySchema::expectedForeignKeys()) : [];
    // The first-scan flag is consumed (and the scan queued) on the site's first load.
    $wpdb->suppress_errors(true);
    $queued = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE hook = %s", InitialDiscoveryScan::HOOK)) > 0;
    $wpdb->suppress_errors(false);
    $scan = get_option(InitialDiscoveryScan::OPTION_FLAG, null) !== null ? 'flagged' : ($queued ? 'queued' : 'NO');
    $ok = $tables === 15 && $fks === ($fkMode ? 8 : 0) && $missing === []
        && get_option(TranslationMemorySchema::OPTION_DB_VERSION) === TranslationMemorySchema::DB_VERSION
        && get_option('lingowp_cookie_preference', null) !== null
        && $scan !== 'NO';
    printf("%s %s tables=%d fks=%d%s defaults=%s scan=%s\n", $ok ? 'OK  ' : 'FAIL', $slug === '' ? '(main)' : $slug, $tables, $fks, $fkMode ? '' : ' (no-FK mode)',
        get_option('lingowp_cookie_preference', null) !== null ? 'yes' : 'NO',
        $scan);
    restore_current_blog();
}
PHP

FAILED=0
LOG_BEFORE="$(stat -c %s "$LOG" 2>/dev/null || echo 0)"
RUN="$(date +%s)"
A="ms-a-$RUN"; B="ms-b-$RUN"; C="ms-c-$RUN"; D="ms-d-$RUN"; E="ms-e-$RUN"

state() { # $1 a site where Linvara is loaded, rest: slugs to report
  local url="$1"; shift
  net_wp eval-file /var/www/html/lv-multisite-check.php "$@" --url="$url" 2>&1
}
expect_ok() { # $1 label, rest: state lines
  local label="$1"; shift
  printf '%s\n' "$@" | sed 's/^/    /'
  case "$*" in *FAIL*|*Error*|*error*) echo "  ✘ $label"; FAILED=1 ;; *) echo "  ✔ $label" ;; esac
}

echo "==> 1. Per-site activation on two fresh sites"
net_wp site create --slug="$A" --quiet >/dev/null
net_wp site create --slug="$B" --quiet >/dev/null
for s in "$A" "$B"; do
  OUT="$(net_wp plugin activate linvara --url="$MAIN/$s/" 2>&1)"
  EXTRA="$(printf '%s\n' "$OUT" | grep -vE "^(Plugin 'linvara' activated\.|Success: )" || true)"
  [[ -z "$EXTRA" ]] || { echo "  ✘ activation on $s printed: $EXTRA"; FAILED=1; }
done
expect_ok "both sites fully set up" "$(state "$MAIN/$A/" "$A" "$B")"
for s in "$A" "$B"; do net_wp plugin deactivate linvara --url="$MAIN/$s/" --quiet; done

echo "==> 1b. A site whose WordPress tables are MyISAM (no foreign keys possible)"
# The WordPress.org review's case: activation used to abort here with wp_die.
net_wp site create --slug="$E" --quiet >/dev/null
EID="$(net_wp site list --fields=blog_id,url --format=csv 2>/dev/null | grep "/$E/\$" | cut -d, -f1)"
for t in posts term_taxonomy terms options; do
  net_wp db query "ALTER TABLE wp_${EID}_${t} ENGINE=MyISAM" >/dev/null
done
# `|| true`: a failed activation (the old wp_die) must be reported, not end the script under set -e.
OUT="$(net_wp plugin activate linvara --url="$MAIN/$E/" 2>&1)" || true
EXTRA="$(printf '%s\n' "$OUT" | grep -vE "^(Plugin 'linvara' activated\.|Success: )" || true)"
if [[ -z "$EXTRA" ]]; then echo "  ✔ activated cleanly on MyISAM tables"; else echo "  ✘ activation on MyISAM printed: $EXTRA"; FAILED=1; fi
expect_ok "set up without foreign keys (15 tables, 0 FKs)" "$(state "$MAIN/$E/" "$E")"
net_wp plugin deactivate linvara --url="$MAIN/$E/" --quiet

echo "==> 2. Network Activate (one site before, one after)"
net_wp site create --slug="$C" --quiet >/dev/null
net_wp plugin activate linvara --network --quiet
# Activation must not loop over the network (WordPress.org review: a large
# network could time out): an existing site is set up on its own first load.
CBEFORE="$(state "$MAIN/" "$C")"
case "$CBEFORE" in
  *"tables=0 "*) echo "  ✔ $C not set up by Network Activate itself (no loop over sites)" ;;
  *) echo "  ✘ $C was set up during Network Activate: $CBEFORE"; FAILED=1 ;;
esac
net_wp eval 'echo "";' --url="$MAIN/$C/" >/dev/null 2>&1 || true   # its first request
expect_ok "existing site fully set up on its first load" "$(state "$MAIN/" "$C")"
net_wp site create --slug="$D" --quiet >/dev/null
expect_ok "site created afterwards fully set up immediately" "$(state "$MAIN/" "$D")"

echo "==> 3. Deleting the test sites drops every table"
for s in "$A" "$B" "$C" "$D" "$E"; do
  id="$(net_wp site list --fields=blog_id,url --format=csv 2>/dev/null | grep "/$s/\$" | cut -d, -f1)"
  net_wp site delete "$id" --yes --quiet
  LEFT="$(net_wp db query "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'wp\\_${id}\\_%'" --skip-column-names 2>/dev/null)"
  if [[ "$LEFT" == "0" ]]; then echo "  ✔ $s (wp_${id}_*): no tables left"; else echo "  ✘ $s (wp_${id}_*): $LEFT tables left"; FAILED=1; fi
done
net_wp plugin deactivate linvara --network --quiet

NEW_LOG="$(tail -c +"$((LOG_BEFORE + 1))" "$LOG" 2>/dev/null || true)"
if [[ -n "$NEW_LOG" ]]; then
  echo "  ✘ debug.log during the run:"; printf '%s\n' "$NEW_LOG" | cut -c1-300 | sed 's/^/    /'; FAILED=1
else
  echo "  ✔ debug.log: nothing logged during the run"
fi

rm -f "$CHECK"
if [[ "$FAILED" -eq 0 ]]; then echo "==> Multisite: all checks passed"; else echo "==> Multisite: FAILED" >&2; fi
exit "$FAILED"
