#!/bin/bash
set -e

PLUGIN_DIR="$(cd "$(dirname "$0")" && pwd)"
LIB_DIR="$PLUGIN_DIR/lib"

echo "Installing Animatronic Performance Capture plugin..."

sudo apt-get update -qq
sudo apt-get install -y python3-pip python3-opencv v4l-utils

pip3 install --quiet flask mediapipe

PARENT="$(cd "$PLUGIN_DIR/../.." && pwd)"
mkdir -p "$LIB_DIR"

for d in core modes xlights; do
    if [ -d "$PARENT/$d" ]; then
        cp -r "$PARENT/$d" "$LIB_DIR/$d"
        echo "  Copied $d/"
    else
        echo "  WARNING: $PARENT/$d not found."
    fi
done

[ -f "$PARENT/config.yaml" ] && cp "$PARENT/config.yaml" "$LIB_DIR/config.yaml"

cat > /tmp/fpp-performance-capture.service << 'EOF'
[Unit]
Description=FPP Animatronic Performance Capture Daemon
After=network.target fpp.service

[Service]
Type=simple
User=fpp
WorkingDirectory=PLUGIN_DIR_PLACEHOLDER
ExecStart=/usr/bin/python3 PLUGIN_DIR_PLACEHOLDER/daemon.py
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF

sed -i "s|PLUGIN_DIR_PLACEHOLDER|$PLUGIN_DIR|g" /tmp/fpp-performance-capture.service
sudo mv /tmp/fpp-performance-capture.service /etc/systemd/system/fpp-performance-capture.service
sudo systemctl daemon-reload
sudo systemctl enable fpp-performance-capture.service
sudo systemctl start  fpp-performance-capture.service

echo "Performance Capture plugin installed. Daemon running on port 5002."
