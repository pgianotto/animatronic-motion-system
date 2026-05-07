#!/bin/bash
# Deploy animatronic plugins to FPP on this Raspberry Pi.
# Run once after cloning or pulling the repo on the Pi.
# Usage: bash pi_deploy.sh

set -e

REPO_DIR="$(cd "$(dirname "$0")" && pwd)"
FPP_PLUGINS="/home/fpp/media/plugins"

echo "=== Animatronic Pi Deploy ==="
echo "Repo: $REPO_DIR"

mkdir -p "$FPP_PLUGINS"

for plugin in fpp-live-follow fpp-performance-capture; do
    SRC="$REPO_DIR/fpp-plugins/$plugin"
    DST="$FPP_PLUGINS/$plugin"

    if [ ! -d "$SRC" ]; then
        echo "  SKIP: $plugin not found in repo"
        continue
    fi

    if [ -L "$DST" ]; then
        echo "  OK (symlink exists): $plugin"
    elif [ -d "$DST" ]; then
        echo "  WARNING: $DST is a real directory (not a symlink). Skipping."
        continue
    else
        ln -s "$SRC" "$DST"
        echo "  Linked: $DST -> $SRC"
    fi

    echo "  Running install script for $plugin..."
    bash "$SRC/fpp_install.sh"
done

echo ""
echo "Deploy complete."
echo "  Live Follow daemon  : http://$(hostname -I | awk '{print $1}'):5001"
echo "  Performance Capture : http://$(hostname -I | awk '{print $1}'):5002"
