#!/bin/bash
# FPP Performance Capture plugin installer/updater
set -e

PLUGIN_DIR="$(cd "$(dirname "$0")" && pwd)"
LIB_DIR="$PLUGIN_DIR/lib"

echo "Installing/updating Animatronic Performance Capture plugin..."

# ── System packages (skip if already installed) ──────────────────────────────
if ! dpkg -s python3-opencv &>/dev/null 2>&1; then
    echo "Installing system packages..."
    sudo apt-get update -qq
    sudo apt-get install -y python3-pip python3-opencv v4l-utils curl
fi

# ── uv (fast Python installer) ───────────────────────────────────────────────
export PATH="$HOME/.local/bin:$PATH"
if ! command -v uv &>/dev/null; then
    curl -LsSf https://astral.sh/uv/install.sh | sh
    export PATH="$HOME/.local/bin:$PATH"
fi

# ── Python 3.11 venv (mediapipe has no 3.13 wheels yet) ─────────────────────
if [ ! -d "$PLUGIN_DIR/venv" ]; then
    echo "Creating Python venv and installing packages..."
    uv venv --python 3.11 "$PLUGIN_DIR/venv"
    uv pip install --python "$PLUGIN_DIR/venv/bin/python" \
        flask pyyaml smbus2 "mediapipe==0.10.9"
fi

# ── Copy shared Python core (always refresh on update) ───────────────────────
PARENT="$(cd "$PLUGIN_DIR/../.." && pwd)"
mkdir -p "$LIB_DIR"

for d in core modes xlights; do
    if [ -d "$PARENT/$d" ]; then
        rm -rf "$LIB_DIR/$d"
        cp -r "$PARENT/$d" "$LIB_DIR/$d"
        echo "  Copied $d/"
    else
        echo "  WARNING: $PARENT/$d not found — tracking code may not work."
    fi
done

[ -f "$PARENT/config.yaml" ] && cp "$PARENT/config.yaml" "$LIB_DIR/config.yaml"

# ── systemd service (create once, then just restart on updates) ──────────────
SERVICE="/etc/systemd/system/fpp-performance-capture.service"
if [ ! -f "$SERVICE" ]; then
    echo "Installing systemd service..."
    cat > /tmp/fpp-performance-capture.service << 'EOF'
[Unit]
Description=FPP Animatronic Performance Capture Daemon
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
    sed -i "s|PLUGIN_DIR_PLACEHOLDER|$PLUGIN_DIR|g" /tmp/fpp-performance-capture.service
    sudo mv /tmp/fpp-performance-capture.service "$SERVICE"
    sudo systemctl daemon-reload
    sudo systemctl enable fpp-performance-capture.service
fi

sudo systemctl restart fpp-performance-capture.service 2>/dev/null || true

# ── Apache proxy (create once) ───────────────────────────────────────────────
if [ ! -f "/etc/apache2/conf-available/fpp-capture-proxy.conf" ]; then
    echo "Configuring Apache proxy..."
    sudo a2enmod proxy proxy_http 2>/dev/null || true
    cat > /tmp/fpp-capture-proxy.conf << 'EOF'
<IfModule mod_proxy.c>
    ProxyPass        /fpp-capture-api/ http://localhost:5002/ flushpackets=on
    ProxyPassReverse /fpp-capture-api/ http://localhost:5002/
</IfModule>
EOF
    sudo cp /tmp/fpp-capture-proxy.conf /etc/apache2/conf-available/fpp-capture-proxy.conf
    sudo a2enconf fpp-capture-proxy 2>/dev/null || true
    sudo systemctl reload apache2 2>/dev/null || true
fi

echo "Done. Access via FPP menu: Plugins > Animatronic Capture"
