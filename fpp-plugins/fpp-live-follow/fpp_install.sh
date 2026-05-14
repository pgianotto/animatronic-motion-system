#!/bin/bash
# fpp-live-follow installer
PLUGIN_DIR="$(cd "$(dirname "$0")" && pwd)"
echo "Installing FPP Live Follow plugin..."

# python3-opencv is required for face detection
python3 -c "import cv2" 2>/dev/null || sudo apt-get install -y python3-opencv

# smbus2 for direct I2C servo writes
python3 -c "import smbus2" 2>/dev/null || \
    pip3 install --quiet smbus2 2>/dev/null || \
    pip3 install --quiet --break-system-packages smbus2 2>/dev/null || \
    echo "WARNING: smbus2 install failed — install manually: pip3 install --break-system-packages smbus2"

chmod +x "$PLUGIN_DIR/servo_follow_daemon.py"

# ── systemd service (create once, restart on updates) ────────────────────────
SERVICE="/etc/systemd/system/fpp-live-follow.service"
if [ ! -f "$SERVICE" ]; then
    echo "Installing systemd service..."
    cat > /tmp/fpp-live-follow.service << 'EOF'
[Unit]
Description=FPP Live Follow Daemon
After=network.target fpp.service

[Service]
Type=simple
User=fpp
ExecStart=/usr/bin/python3 PLUGIN_DIR_PLACEHOLDER/servo_follow_daemon.py
Restart=on-failure
RestartSec=3

[Install]
WantedBy=multi-user.target
EOF
    sed -i "s|PLUGIN_DIR_PLACEHOLDER|$PLUGIN_DIR|g" /tmp/fpp-live-follow.service
    sudo mv /tmp/fpp-live-follow.service "$SERVICE"
    sudo systemctl daemon-reload
    sudo systemctl enable fpp-live-follow.service
fi

sudo systemctl restart fpp-live-follow.service 2>/dev/null || true

echo "Done. Access via FPP menu: Plugins > Live Follow"
