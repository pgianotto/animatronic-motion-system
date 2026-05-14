import time
from abc import ABC, abstractmethod
from typing import Dict

import numpy as np


# ---------------------------------------------------------------------------
# Backends
# ---------------------------------------------------------------------------

class ServoBackend(ABC):
    @abstractmethod
    def set_angle(self, channel: int, angle: float): ...

    @abstractmethod
    def close(self): ...


class MockServoBackend(ServoBackend):
    """No-op backend for Windows development / testing."""
    def __init__(self):
        self._positions: Dict[int, float] = {}

    def set_angle(self, channel: int, angle: float):
        self._positions[channel] = float(angle)

    def get_angle(self, channel: int) -> float:
        return self._positions.get(channel, 90.0)

    def close(self):
        pass


class SerialServoBackend(ServoBackend):
    """Controls servos via Arduino/serial bridge.

    Arduino sketch should parse lines like:  #<channel>:<angle>
    and call servo.write(angle) on the appropriate servo object.
    """
    def __init__(self, port: str, baud: int = 115200):
        import serial
        self._ser = serial.Serial(port, baud, timeout=1)
        time.sleep(2)   # wait for Arduino reset

    def set_angle(self, channel: int, angle: float):
        self._ser.write(f"#{channel}:{angle:.1f}\n".encode())

    def close(self):
        self._ser.close()


class Smbus2ServoBackend(ServoBackend):
    """PCA9685 via smbus2 — no Adafruit libs required.

    Reads the actual PWM frequency from the prescaler register so pulse
    widths are accurate regardless of what fppd has configured.
    """
    def __init__(self, address: int = 0x40, i2c_bus: int = 1):
        import smbus2
        self._bus  = smbus2.SMBus(i2c_bus)
        self._addr = address
        m = self._bus.read_byte_data(address, 0x00)
        if m & 0x10:
            self._bus.write_byte_data(address, 0x00, m & ~0x10)
            time.sleep(0.005)
        pre = self._bus.read_byte_data(address, 0xFE)
        self._freq = 25_000_000 / (4096 * (pre + 1))

    def set_angle(self, channel: int, angle: float):
        pulse_us = 1000.0 + (float(angle) / 180.0) * 1000.0
        counts   = round(pulse_us * self._freq * 4096 / 1_000_000)
        base = 0x06 + channel * 4
        self._bus.write_byte_data(self._addr, base,   0)
        self._bus.write_byte_data(self._addr, base+1, 0)
        self._bus.write_byte_data(self._addr, base+2, counts & 0xFF)
        self._bus.write_byte_data(self._addr, base+3, counts >> 8)

    def close(self):
        self._bus.close()


class PCA9685ServoBackend(ServoBackend):
    """PCA9685 via Adafruit CircuitPython libs (requires adafruit-circuitpython-pca9685)."""
    def __init__(self, address: int = 0x40, frequency: int = 50):
        from adafruit_pca9685 import PCA9685
        import board, busio
        i2c = busio.I2C(board.SCL, board.SDA)
        self._pca = PCA9685(i2c, address=address)
        self._pca.frequency = frequency

    def set_angle(self, channel: int, angle: float):
        # 1000-2000 µs pulse over 20ms period (50Hz)
        pulse_us = 1000.0 + (angle / 180.0) * 1000.0
        duty = int(pulse_us / 20000.0 * 65535)
        self._pca.channels[channel].duty_cycle = duty

    def close(self):
        self._pca.deinit()


class GPIOServoBackend(ServoBackend):
    """Direct RPi.GPIO software PWM servo control."""
    def __init__(self, pin_map: Dict[int, int]):
        import RPi.GPIO as GPIO
        self._GPIO = GPIO
        self._pwm: Dict[int, object] = {}
        GPIO.setmode(GPIO.BCM)
        for ch, pin in pin_map.items():
            GPIO.setup(pin, GPIO.OUT)
            pwm = GPIO.PWM(pin, 50)
            pwm.start(0)
            self._pwm[ch] = pwm

    def set_angle(self, channel: int, angle: float):
        # Duty cycle: 2.5% = 0°, 12.5% = 180°
        duty = 2.5 + (angle / 180.0) * 10.0
        if channel in self._pwm:
            self._pwm[channel].ChangeDutyCycle(duty)

    def close(self):
        for pwm in self._pwm.values():
            pwm.stop()
        self._GPIO.cleanup()


def create_backend(config: dict) -> ServoBackend:
    hw_type = config.get('type', 'mock')

    if hw_type == 'mock':
        return MockServoBackend()

    if hw_type == 'serial':
        return SerialServoBackend(
            config['serial_port'],
            config.get('serial_baud', 115200),
        )

    if hw_type == 'smbus2':
        addr = config.get('pca9685_address', 0x40)
        if isinstance(addr, str):
            addr = int(addr, 16)
        bus_num = int(config.get('pca9685_i2c_bus', 1))
        return Smbus2ServoBackend(addr, bus_num)

    if hw_type == 'pca9685':
        addr = config.get('pca9685_address', 0x40)
        if isinstance(addr, str):
            addr = int(addr, 16)
        return PCA9685ServoBackend(addr, config.get('pca9685_frequency', 50))

    if hw_type == 'gpio':
        assignments = config.get('channel_assignments', {'pan': 0, 'tilt': 1})
        pin_map = {
            assignments.get('pan', 0):  config.get('gpio_pan_pin', 17),
            assignments.get('tilt', 1): config.get('gpio_tilt_pin', 27),
        }
        return GPIOServoBackend(pin_map)

    raise ValueError(f"Unknown hardware type: '{hw_type}'")


# ---------------------------------------------------------------------------
# High-level controller
# ---------------------------------------------------------------------------

class ServoController:
    def __init__(self, backend: ServoBackend, servo_configs: dict,
                 channel_assignments: dict):
        self._backend = backend
        self._configs = servo_configs
        self._channels = channel_assignments
        self._angles: Dict[str, float] = {
            name: float(cfg.get('center_angle', 90))
            for name, cfg in servo_configs.items()
        }

    def set_servo(self, name: str, angle: float):
        cfg = self._configs.get(name, {})
        angle = float(np.clip(
            angle,
            cfg.get('min_angle', 0),
            cfg.get('max_angle', 180),
        ))
        self._angles[name] = angle
        channel = self._channels.get(name, 0)
        self._backend.set_angle(channel, angle)

    def get_angle(self, name: str) -> float:
        return self._angles.get(name, 90.0)

    def center_all(self):
        for name, cfg in self._configs.items():
            self.set_servo(name, cfg.get('center_angle', 90))

    def close(self):
        self.center_all()
        self._backend.close()
