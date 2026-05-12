# FPP Servo Calibrator Plugin

Mixer-style servo calibration tool for PCA9685 outputs on Falcon Player (FPP).  
Writes directly to PCA9685 via I2C — lets you drag per-channel faders, set min/max/center values, and save back to `co-other.json`.

---

## Install (SSH into your Pi first)

```bash
git clone https://github.com/pgianotto/fpp-servo-calibrator.git /home/fpp/media/plugins/fpp-servo-calibrator
bash /home/fpp/media/plugins/fpp-servo-calibrator/fpp_install.sh
```

Refresh the FPP browser UI — the plugin appears under **Content Setup → Plugins → Servo Calibrator**.

---

## Update

```bash
cd /home/fpp/media/plugins/fpp-servo-calibrator && git pull
```

Re-run `fpp_install.sh` only if the release notes mention new dependencies.

---

## Why not the FPP Plugin Manager?

FPP's built-in plugin manager uses the GitHub API, which requires a configured Personal Access Token.  
`git clone` / `git pull` over HTTPS bypasses the API entirely — no token needed.

---

## Hardware

- Raspberry Pi running FPP (tested on FPP v6+, Falcon Player OS Image v2026-05)
- PCA9685 PWM board connected via I2C
- Servo outputs configured in FPP as a **PCA9685** channel output (`co-other.json`)
