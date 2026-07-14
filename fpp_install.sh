#!/bin/bash
# Root-level entry point for FPP's plugin manager.
# The actual plugin lives in fpp-plugins/fpp-performance-capture/.
set -e
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

# Allow root (used by FPP's plugin manager) to run git in this directory.
# Without this, git 2.35+ rejects pull/fetch from root in fpp-owned dirs.
sudo git config --global --add safe.directory "$SCRIPT_DIR" 2>/dev/null || true

chmod +x "$SCRIPT_DIR/scripts/preStart.sh" 2>/dev/null || true

exec "$SCRIPT_DIR/fpp-plugins/fpp-performance-capture/fpp_install.sh"
