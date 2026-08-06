#!/bin/bash
# Root-level entry point for FPP's plugin manager.
# The actual plugin lives in fpp-plugins/fpp-performance-capture/.
set -e
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

exec bash "$SCRIPT_DIR/fpp-plugins/fpp-performance-capture/fpp_uninstall.sh"
