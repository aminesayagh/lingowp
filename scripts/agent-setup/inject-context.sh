#!/usr/bin/env bash
#
# inject-context.sh
# Appends project-specific instructions to the agent context files
# (CLAUDE.md for Claude Code, AGENTS.md for Codex) AFTER the MCP tools
# have initialized their base content.
#
# Idempotent: re-running will not duplicate the block — it checks for a marker
# and skips any file that already contains the additions.
#
# Run this AFTER install-mcp.sh, from (or pointing at) your project root.
#
# Usage:
#   ./inject-context.sh [project_dir]     # defaults to current directory
#

set -euo pipefail

log()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
ok()   { printf '\033[1;32m  ✓\033[0m %s\n' "$*"; }
skip() { printf '\033[1;33m  ~\033[0m %s\n' "$*"; }
err()  { printf '\033[1;31m  ✗\033[0m %s\n' "$*" >&2; }

PROJECT_DIR="${1:-.}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MARKER="<!-- BEGIN project-additions -->"

inject() {
  local target="$1" snippet="$2"

  if [[ ! -f "$snippet" ]]; then
    err "Snippet not found next to this script: $snippet"
    exit 1
  fi

  # Already injected? Skip so re-runs don't duplicate the block.
  if [[ -f "$target" ]] && grep -qF "$MARKER" "$target"; then
    skip "$target already contains project additions — leaving it untouched."
    return
  fi

  # If the file exists and doesn't end in a newline, add one before appending.
  if [[ -f "$target" && -n "$(tail -c1 "$target" 2>/dev/null)" ]]; then
    printf '\n' >> "$target"
  fi

  printf '\n' >> "$target"          # blank line separating base content from additions
  cat "$snippet" >> "$target"
  ok "Injected project additions into $target"
}

log "Injecting context additions into: $PROJECT_DIR"
inject "$PROJECT_DIR/CLAUDE.md"  "$SCRIPT_DIR/claude-context-additions.md"
inject "$PROJECT_DIR/AGENTS.md"  "$SCRIPT_DIR/codex-context-additions.md"
log "Done."