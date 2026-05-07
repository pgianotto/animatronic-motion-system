"""Animatronic Motion System — entry point.

Development (Windows):  set hardware.type: mock  in config.yaml
Deployed (Pi):          set hardware.type: pca9685 (or gpio / serial)

Usage:
    python main.py              # uses config.yaml
    python main.py my_cfg.yaml  # custom config file
"""

import sys


def load_config(path: str) -> dict:
    try:
        import yaml
        with open(path) as f:
            return yaml.safe_load(f) or {}
    except FileNotFoundError:
        print(f"[warning] {path} not found — using defaults.")
        return {}


def main():
    from pathlib import Path
    default_cfg = str(Path(__file__).parent / 'config.yaml')
    cfg_path = sys.argv[1] if len(sys.argv) > 1 else default_cfg
    config = load_config(cfg_path)

    from gui.app import AnimatronicApp
    AnimatronicApp(config, config_path=cfg_path).run()


if __name__ == '__main__':
    main()
