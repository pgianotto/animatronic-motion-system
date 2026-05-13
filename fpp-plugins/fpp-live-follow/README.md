# FPP Animatronic Live Follow Plugin

Real-time face-tracking servo control for FPP animatronics. Detects a face in the camera frame and moves pan/tilt servos to keep it centered. Supports four trigger modes: always-on, show-active, FPP playlist command, and motion-sensor (GPIO).

---

## Install (SSH into your Pi first)

```bash
git clone https://github.com/pgianotto/animatronic-motion-system.git \
    /home/fpp/media/animatronic
bash /home/fpp/media/animatronic/fpp-plugins/fpp-live-follow/fpp_install.sh
```

The install script:
1. Installs Python 3.11 venv with Flask, MediaPipe, RPi.GPIO
2. Copies `core/`, `modes/`, and `xlights/` from the repo into the plugin's `lib/` folder
3. Creates and starts a systemd service on port 5001
4. Configures an Apache proxy so FPP's UI can reach the daemon

Refresh the FPP browser — the plugin appears under **Plugins → Animatronic Live Follow**.

---

## Update

```bash
cd /home/fpp/media/animatronic && git pull
sudo systemctl restart fpp-live-follow
```

Re-run `fpp_install.sh` only if the release notes mention new dependencies or changes
to `core/` or `modes/` that need to be re-copied into `lib/`.

---

## Trigger Modes

| Mode | Behavior |
|------|----------|
| **Always On** | Tracking runs whenever the daemon is running |
| **Show Active** | Activates when an FPP playlist starts, stops when it ends |
| **FPP Command** | Controlled via playlist command scripts (`/api/start`, `/api/stop`) |
| **Motion Sensor** | GPIO pin triggers tracking; auto-off after configurable timeout |

Configure the mode and all servo settings from the plugin page in the FPP UI.

---

## Hardware

- Raspberry Pi running FPP (tested on FPP v6+)
- PCA9685 PWM board connected via I2C
- USB or CSI camera
- Pan/tilt servo assembly connected to PCA9685 channels 0 (pan) and 1 (tilt) by default
