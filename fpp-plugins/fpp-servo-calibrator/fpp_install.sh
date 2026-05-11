#!/bin/bash
# fpp-servo-calibrator installer
set -e

echo "Installing Animatronic Servo Calibrator plugin..."

# servo_ctl.py requires smbus2 for direct I2C writes to PCA9685
pip3 install --quiet smbus2

chmod +x "$(dirname "$0")/servo_ctl.py"

echo "Done. Access via FPP menu: Plugins > Servo Calibrator"
