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

# ── uv (fast Python installer) — install to /usr/local/bin so all users see it ─
export PATH="/usr/local/bin:$HOME/.local/bin:$PATH"
if ! command -v uv &>/dev/null; then
    curl -LsSf https://astral.sh/uv/install.sh | sh
    if [ -f "$HOME/.local/bin/uv" ]; then
        sudo mv "$HOME/.local/bin/uv" /usr/local/bin/uv 2>/dev/null || true
    fi
    export PATH="/usr/local/bin:$PATH"
fi

# ── Python 3.11 venv — create as fpp so Python downloads land in /home/fpp ───
# Running as root causes uv to download Python to /root/.local, which fpp
# cannot read; the venv symlink then breaks when systemd starts the service.
if [ ! -d "$PLUGIN_DIR/venv" ]; then
    echo "Creating Python venv and installing packages..."
    sudo chown -R fpp:fpp /home/fpp/.cache /home/fpp/.local 2>/dev/null || true
    if python3 -c "import sys; assert sys.version_info[:2] == (3,11)" 2>/dev/null; then
        sudo -u fpp python3 -m venv "$PLUGIN_DIR/venv"
        sudo -u fpp "$PLUGIN_DIR/venv/bin/pip" install --quiet \
            flask pyyaml smbus2 "mediapipe==0.10.9" RPi.GPIO 2>/dev/null || \
        sudo -u fpp "$PLUGIN_DIR/venv/bin/pip" install --quiet \
            flask pyyaml smbus2 "mediapipe==0.10.9"
    else
        sudo -u fpp env HOME=/home/fpp PATH=/usr/local/bin:/usr/bin:/bin UV_NO_CACHE=1 \
            uv venv --python 3.11 "$PLUGIN_DIR/venv"
        sudo -u fpp env HOME=/home/fpp PATH=/usr/local/bin:/usr/bin:/bin UV_NO_CACHE=1 \
            uv pip install --python "$PLUGIN_DIR/venv/bin/python" \
            flask pyyaml smbus2 "mediapipe==0.10.9" RPi.GPIO 2>/dev/null || \
        sudo -u fpp env HOME=/home/fpp PATH=/usr/local/bin:/usr/bin:/bin UV_NO_CACHE=1 \
            uv pip install --python "$PLUGIN_DIR/venv/bin/python" \
            flask pyyaml smbus2 "mediapipe==0.10.9"
    fi
fi

# ── Clone or update shared Python core from animatronic-motion-system ────────
CORE_DIR="/home/fpp/media/animatronic"
if [ -d "$CORE_DIR/.git" ]; then
    echo "Updating shared core library..."
    sudo chown -R fpp:fpp "$CORE_DIR/.git" 2>/dev/null || true
    git -C "$CORE_DIR" pull --quiet 2>/dev/null || echo "  WARNING: git pull failed — using existing core"
else
    echo "Cloning shared core library..."
    git clone --quiet https://github.com/pgianotto/animatronic-motion-system.git "$CORE_DIR" || \
        echo "  WARNING: git clone failed — tracking code may not work"
fi

mkdir -p "$LIB_DIR"
for d in core modes xlights; do
    if [ -d "$CORE_DIR/$d" ]; then
        rm -rf "$LIB_DIR/$d"
        cp -r "$CORE_DIR/$d" "$LIB_DIR/$d"
        echo "  Copied $d/"
    else
        echo "  WARNING: $CORE_DIR/$d not found — tracking code may not work."
    fi
done

[ -f "$CORE_DIR/config.yaml" ] && cp "$CORE_DIR/config.yaml" "$LIB_DIR/config.yaml"

# ── systemd service (always write so updates stay current) ────────────────────
SERVICE="/etc/systemd/system/fpp-performance-capture.service"
echo "Installing systemd service..."
cat > /tmp/fpp-performance-capture.service << 'EOF'
[Unit]
Description=FPP Animatronic Performance Capture Daemon
After=network.target fppd.service

[Service]
Type=simple
User=fpp
WorkingDirectory=PLUGIN_DIR_PLACEHOLDER
ExecStartPre=/bin/sleep 8
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
