#!/bin/bash
set -uo pipefail
# FPP Performance Capture plugin uninstaller — reverses fpp_install.sh
PLUGIN_DIR="$(cd "$(dirname "$0")" && pwd)"

echo "Removing Animatronic Performance Capture plugin..."

# ── systemd service ─────────────────────────────────────────────────────────
if systemctl list-unit-files fpp-performance-capture.service &>/dev/null; then
    systemctl disable --now fpp-performance-capture.service 2>/dev/null || true
fi
rm -f /etc/systemd/system/fpp-performance-capture.service
systemctl daemon-reload 2>/dev/null || true

# ── Apache proxy ────────────────────────────────────────────────────────────
rm -f /etc/apache2/conf-enabled/fpp-capture-proxy.conf
rm -f /etc/apache2/conf-available/fpp-capture-proxy.conf
systemctl reload apache2 2>/dev/null || true

# ── safe.directory entry added at install time (two levels up from plugin dir) ─
SAFE_DIR="$(cd "$PLUGIN_DIR/../.." && pwd)"
git config --system --unset-all safe.directory "$SAFE_DIR" 2>/dev/null || true

# Note: /home/fpp/media/animatronic (the shared core library clone) is left in
# place — it's shared with fpp-live-follow and possibly other plugins, not
# something this plugin's install created for itself alone.

echo "Done. FPP's Plugin Manager removes the plugin directory itself."
