#!/bin/bash
set -uo pipefail
# fpp-servo-calibrator uninstaller — reverses fpp_install.sh
PLUGIN_DIR="$(cd "$(dirname "$0")" && pwd)"

echo "Removing Animatronic Servo Calibrator plugin..."

# ── systemd service ─────────────────────────────────────────────────────────
if systemctl list-unit-files fpp-servo-calibrator.service &>/dev/null; then
    systemctl disable --now fpp-servo-calibrator.service 2>/dev/null || true
fi
rm -f /etc/systemd/system/fpp-servo-calibrator.service
systemctl daemon-reload 2>/dev/null || true

echo "Done. FPP's Plugin Manager removes the plugin directory itself."
