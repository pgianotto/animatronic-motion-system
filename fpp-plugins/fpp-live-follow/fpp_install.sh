#!/bin/bash
# FPP Live Follow plugin installer
set -e

PLUGIN_DIR="$(cd "$(dirname "$0")" && pwd)"
LIB_DIR="$PLUGIN_DIR/lib"

echo "Installing Animatronic Live Follow plugin..."

# ── System packages ──────────────────────────────────────────────────────────
sudo apt-get update -qq
sudo apt-get install -y python3-pip python3-opencv libcap2 v4l-utils

# ── Python virtual environment ───────────────────────────────────────────────
sudo apt-get install -y python3-venv python3-full
python3 -m venv "$PLUGIN_DIR/venv" --system-site-packages
"$PLUGIN_DIR/venv/bin/pip" install --quiet flask mediapipe RPi.GPIO 2>/dev/null || \
    "$PLUGIN_DIR/venv/bin/pip" install --quiet flask mediapipe

# ── Copy shared Python core from parent project ──────────────────────────────
# Works whether the plugin is inside the project tree or installed standalone.
PARENT="$(cd "$PLUGIN_DIR/../.." && pwd)"
mkdir -p "$LIB_DIR"

for d in core modes xlights; do
    if [ -d "$PARENT/$d" ]; then
        cp -r "$PARENT/$d" "$LIB_DIR/$d"
        echo "  Copied $d/"
    else
        echo "  WARNING: $PARENT/$d not found — tracking code may not work."
    fi
done

if [ -f "$PARENT/config.yaml" ]; then
    cp "$PARENT/config.yaml" "$LIB_DIR/config.yaml"
fi

# ── systemd service ──────────────────────────────────────────────────────────
cat > /tmp/fpp-live-follow.service << 'EOF'
[Unit]
Description=FPP Animatronic Live Follow Daemon
After=network.target fpp.service

[Service]
Type=simple
User=fpp
WorkingDirectory=PLUGIN_DIR_PLACEHOLDER
ExecStart=PLUGIN_DIR_PLACEHOLDER/venv/bin/python3 PLUGIN_DIR_PLACEHOLDER/daemon.py
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF

sed -i "s|PLUGIN_DIR_PLACEHOLDER|$PLUGIN_DIR|g" /tmp/fpp-live-follow.service
sudo mv /tmp/fpp-live-follow.service /etc/systemd/system/fpp-live-follow.service
sudo systemctl daemon-reload
sudo systemctl enable fpp-live-follow.service
sudo systemctl start  fpp-live-follow.service

echo "Live Follow plugin installed. Daemon running on port 5001."
echo "Access via FPP menu: Plugins > Animatronic Live Follow"
