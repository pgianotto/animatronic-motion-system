#!/bin/bash
# fpp-servo-calibrator installer
PLUGIN_DIR="$(cd "$(dirname "$0")" && pwd)"
echo "Installing Animatronic Servo Calibrator plugin..."

python3 -c "import smbus2" 2>/dev/null || \
    pip3 install --quiet --break-system-packages smbus2 2>/dev/null || \
    echo "WARNING: could not install smbus2 — install manually with: pip3 install --break-system-packages smbus2"
chmod +x "$PLUGIN_DIR/servo_ctl.py"
chmod +x "$PLUGIN_DIR/servo_daemon.py"

# ── systemd service (create once, restart on updates) ────────────────────────
SERVICE="/etc/systemd/system/fpp-servo-calibrator.service"
if [ ! -f "$SERVICE" ]; then
    echo "Installing systemd service..."
    cat > /tmp/fpp-servo-calibrator.service << 'EOF'
[Unit]
Description=FPP Servo Calibrator Daemon
After=network.target fpp.service

[Service]
Type=simple
User=fpp
ExecStart=/usr/bin/python3 PLUGIN_DIR_PLACEHOLDER/servo_daemon.py
Restart=on-failure
RestartSec=3

[Install]
WantedBy=multi-user.target
EOF
    sed -i "s|PLUGIN_DIR_PLACEHOLDER|$PLUGIN_DIR|g" /tmp/fpp-servo-calibrator.service
    mv /tmp/fpp-servo-calibrator.service "$SERVICE"
    systemctl daemon-reload
    systemctl enable fpp-servo-calibrator.service
fi

systemctl restart fpp-servo-calibrator.service 2>/dev/null || true

echo "Done. Access via FPP menu: Plugins > Servo Calibrator"
