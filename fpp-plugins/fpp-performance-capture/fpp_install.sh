#!/bin/bash
# FPP Performance Capture plugin installer/updater
PLUGIN_DIR="$(cd "$(dirname "$0")" && pwd)"
LIB_DIR="$PLUGIN_DIR/lib"

echo "Installing/updating Animatronic Performance Capture plugin..."

# ── System packages (skip if already installed) ──────────────────────────────
if ! dpkg -s python3-opencv &>/dev/null 2>&1; then
    echo "Installing system packages..."
    # Recover from a prior interrupted apt/dpkg run (e.g. an OS upgrade or a
    # timed-out plugin install) — otherwise apt-get refuses to proceed at all.
    dpkg --configure -a || true
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    apt-get install -y python3-pip python3-opencv v4l-utils
fi

# ── uv (fast Python package installer) — install via pip, not a curl|sh script ─
export PATH="/usr/local/bin:$HOME/.local/bin:/root/.local/bin:$PATH"
if ! command -v uv &>/dev/null; then
    echo "Installing uv..."
    # --break-system-packages is safe here: pip targets /usr/local/lib/python3.x/
    # dist-packages, which dpkg doesn't track, so it can't conflict with apt.
    python3 -m pip install --quiet --break-system-packages uv
fi

# ── Python 3.11 venv — create as fpp so Python downloads land in /home/fpp ───
# Running as root causes uv to download Python to /root/.local, which fpp
# cannot read; the venv symlink then breaks when systemd starts the service.
if [ ! -d "$PLUGIN_DIR/venv" ]; then
    echo "Creating Python venv and installing packages..."
    chown -R fpp:fpp /home/fpp/.cache /home/fpp/.local 2>/dev/null || true
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
# Pinned deliberately — bump this sha only after reviewing what changed upstream.
CORE_SHA="b6f63a070bff09687ca47460b1927fd2edeb9004"
CORE_DIR="/home/fpp/media/animatronic"
if [ -d "$CORE_DIR/.git" ]; then
    echo "Updating shared core library..."
    chown -R fpp:fpp "$CORE_DIR" 2>/dev/null || true
    sudo -u fpp git -C "$CORE_DIR" fetch --quiet && sudo -u fpp git -C "$CORE_DIR" checkout --quiet "$CORE_SHA" || \
        echo "  WARNING: git update failed — using existing core"
else
    echo "Cloning shared core library..."
    sudo -u fpp git clone --quiet https://github.com/pgianotto/animatronic-motion-system.git "$CORE_DIR" && sudo -u fpp git -C "$CORE_DIR" checkout --quiet "$CORE_SHA" || \
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
mv /tmp/fpp-performance-capture.service "$SERVICE"
systemctl daemon-reload
systemctl enable fpp-performance-capture.service

systemctl restart fpp-performance-capture.service 2>/dev/null || true

# ── Apache proxy (always write so reinstalls and updates stay current) ────────
echo "Configuring Apache proxy..."
a2enmod proxy proxy_http 2>/dev/null || true
PROXY_CONF="/etc/apache2/conf-available/fpp-capture-proxy.conf"
printf '<IfModule mod_proxy.c>\n    ProxyPass        /fpp-capture-api/ http://localhost:5002/ flushpackets=on\n    ProxyPassReverse /fpp-capture-api/ http://localhost:5002/\n</IfModule>\n' \
    > "$PROXY_CONF"
ln -sf "$PROXY_CONF" /etc/apache2/conf-enabled/fpp-capture-proxy.conf
systemctl reload apache2 2>/dev/null || true

# Allow root (used by FPP's plugin manager) to run git in this directory.
# Without this, git 2.35+ rejects pull/fetch from root in fpp-owned dirs.
git config --system --add safe.directory "$(cd "$PLUGIN_DIR/../.." && pwd)" 2>/dev/null || true

echo "Done. Access via FPP menu: Plugins > Animatronic Capture"
