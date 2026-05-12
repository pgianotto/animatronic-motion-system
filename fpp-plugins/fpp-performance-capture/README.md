# FPP Performance Capture Plugin

Record a performer's head pose, facial expressions, and body movement using a camera.
Play back through servos in real time or export directly to FPP as an FSEQ sequence file.

Requires the **FPP Servo Calibrator** plugin to be installed first — it provides the
min/max/center calibration stored in `co-other.json` that this plugin uses to drive servos.

---

## Install (SSH into your Pi first)

```bash
git clone https://github.com/pgianotto/animatronic-motion-system.git \
    /home/fpp/media/animatronic
bash /home/fpp/media/animatronic/fpp-plugins/fpp-performance-capture/fpp_install.sh
```

The install script:
1. Installs Python 3.11 venv with Flask, MediaPipe, smbus2
2. Copies `core/`, `modes/`, and `xlights/` from the repo into the plugin's `lib/` folder
3. Creates and starts a systemd service on port 5002
4. Configures an Apache proxy so FPP's UI can reach the daemon

Refresh the FPP browser — the plugin appears under **Plugins → Animatronic Capture**.

---

## Update

```bash
cd /home/fpp/media/animatronic && git pull
sudo systemctl restart fpp-performance-capture
```

Re-run `fpp_install.sh` only if the release notes mention new dependencies or changes
to `core/`, `modes/`, or `xlights/` that need to be re-copied into `lib/`.

---

## Why not the FPP Plugin Manager?

FPP's built-in plugin manager uses the GitHub API, which requires a configured Personal
Access Token. `git clone` / `git pull` over HTTPS bypasses the API entirely — no token needed.

---

## Joint Mapping

After install, open the plugin page and use the **Joint Mapping** card to:

1. Select which servo **port** each tracked joint drives
2. Toggle **Invert** to reverse the servo direction
3. Adjust **Scale %** to reduce travel range (100% = full calibrated range)
4. Click **Save Mapping** — the daemon applies it immediately

Tracked joints available for mapping:

| Group | Joint Keys |
|-------|-----------|
| Face  | `head_yaw`, `head_pitch`, `head_roll`, `mouth_open`, `left_eye_open`, `right_eye_open`, `left_eyebrow_raise`, `right_eyebrow_raise`, `face_center_x`, `face_center_y` |
| Body  | `torso_lean_lr`, `torso_lean_fb`, `torso_tilt`, `left_arm_raise`, `right_arm_raise`, `left_elbow_bend`, `right_elbow_bend`, `left_wrist_raise`, `right_wrist_raise` |

---

## Hardware

- Raspberry Pi running FPP (tested on FPP v6+, Falcon Player OS Image v2026-05)
- PCA9685 PWM board connected via I2C, configured via the Servo Calibrator plugin
- USB or CSI camera
