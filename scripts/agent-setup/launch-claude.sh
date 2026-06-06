#!/usr/bin/env bash
#
# launch-claude.sh
# Launches Claude Code with serena's Claude Code system-prompt override.
#
# Run install-mcp.sh first so that `serena` and `claude` are both available.
#
# Usage:
#   ./launch-claude.sh
#

set -euo pipefail

err() { printf '\033[1;31m  ✗\033[0m %s\n' "$*" >&2; }

require() {
  if ! command -v "$1" >/dev/null 2>&1; then
    err "Required command '$1' not found in PATH. Did you run install-mcp.sh?"
    exit 1
  fi
}

require serena
require claude

# Capture the override first so a serena failure doesn't launch claude with an
# empty/garbled prompt.
SYSTEM_PROMPT="$(serena prompts print-cc-system-prompt-override)"

if [[ -z "${SYSTEM_PROMPT//[[:space:]]/}" ]]; then
  err "serena returned an empty system prompt override; aborting."
  exit 1
fi

exec claude --system-prompt="$SYSTEM_PROMPT"