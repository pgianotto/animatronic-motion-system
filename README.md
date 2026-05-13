# Animatronic Motion System

Camera-based motion tracking and servo control plugins for [Falcon Player (FPP)](https://falconchristmas.com). Built for animatronic heads and figures — tracks a performer's face in real time, captures motion for playback, and exports servo data directly to FPP sequences.

---

## Plugins

### [Animatronic Live Follow](fpp-plugins/fpp-live-follow/README.md)
Real-time face tracking. Moves pan/tilt servos to keep a detected face centered in frame. Configurable trigger modes: always-on, show-active, FPP playlist command, or GPIO motion sensor.

### [Animatronic Performance Capture](fpp-plugins/fpp-performance-capture/README.md)
Records a performer's head pose, facial expressions, and body movement via camera. Play back through servos in real time or export directly to FPP as an FSEQ v2 sequence file. Requires the Servo Calibrator plugin.

### [FPP Servo Calibrator](fpp-plugins/fpp-servo-calibrator/README.md)
Mixer-style per-channel servo calibration for PCA9685 outputs. Set min/max/center values with live faders and save back to FPP's `co-other.json`. **Install this first** — Performance Capture reads its calibration data.

---

## Prerequisites

- Raspberry Pi running FPP v6+
- PCA9685 PWM board connected via I2C, configured in FPP as a channel output
- USB or CSI camera (for Live Follow and Performance Capture)

---

## Install Order

1. **Servo Calibrator** — sets up servo calibration that the other plugins depend on
2. **Live Follow** and/or **Performance Capture** — in either order

Each plugin has its own install instructions. SSH into your Pi, then follow the README for each plugin you want.

---

## Quick Install (all three plugins)

```bash
# Clone the repo once
git clone https://github.com/pgianotto/animatronic-motion-system.git \
    /home/fpp/media/animatronic

# Install each plugin
bash /home/fpp/media/animatronic/fpp-plugins/fpp-servo-calibrator/fpp_install.sh
bash /home/fpp/media/animatronic/fpp-plugins/fpp-live-follow/fpp_install.sh
bash /home/fpp/media/animatronic/fpp-plugins/fpp-performance-capture/fpp_install.sh
```

Refresh the FPP browser UI — all three plugins appear under **Plugins**.

---

## Update

```bash
cd /home/fpp/media/animatronic && git pull
sudo systemctl restart fpp-live-follow fpp-performance-capture
```

Re-run a plugin's `fpp_install.sh` only if the release notes mention new dependencies.
