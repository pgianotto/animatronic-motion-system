#!/bin/bash
# Reinstall service and Apache proxy if missing after an FPP OS upgrade.
# FPP calls this before fppd starts on every boot.
PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
if ! systemctl is-enabled fpp-performance-capture.service &>/dev/null 2>&1; then
    echo "[fpp-performance-capture] Service missing — reinstalling after OS upgrade..."
    sudo -u fpp git -C "$PLUGIN_DIR" pull --quiet 2>/dev/null || true
    bash "$PLUGIN_DIR/fpp-plugins/fpp-performance-capture/fpp_install.sh"
fi
