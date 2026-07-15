#!/usr/bin/env python3
"""Persistent servo daemon for fpp-servo-calibrator.
Keeps the I2C bus open so per-command latency is ~2ms instead of ~150ms."""
from http.server import HTTPServer, BaseHTTPRequestHandler
import json, os, signal, sys, time
import smbus2

CONFIG   = '/home/fpp/media/config/co-other.json'
HOST     = '127.0.0.1'
PORT_NUM = 5003

outputs = []

def load_outputs():
    with open(CONFIG) as f:
        cfg = json.load(f)
    result = []
    for out in cfg.get('channelOutputs', []):
        if not out.get('ports') or not out.get('enabled'):
            continue
        dev  = out.get('device', 'i2c-1')
        addr = out.get('deviceID', 0x40)
        bus  = smbus2.SMBus(int(dev.split('-')[-1]))
        m = bus.read_byte_data(addr, 0x00)
        if m & 0x10:
            bus.write_byte_data(addr, 0x00, m & ~0x10)
            time.sleep(0.005)
        pre  = bus.read_byte_data(addr, 0xFE)
        freq = 25_000_000 / (4096 * (pre + 1))
        result.append({'bus': bus, 'addr': addr, 'freq': freq, 'ports': out['ports']})
    return result

def close_outputs(outs):
    for o in outs:
        try: o['bus'].close()
        except: pass

def us_to_counts(us, freq):
    return round(us * freq * 4096 / 1_000_000)

def set_ch(bus, addr, ch, counts):
    base = 0x06 + ch * 4
    bus.write_byte_data(addr, base,   0)
    bus.write_byte_data(addr, base+1, 0)
    bus.write_byte_data(addr, base+2, counts & 0xFF)
    bus.write_byte_data(addr, base+3, counts >> 8)

class Handler(BaseHTTPRequestHandler):
    def log_message(self, *a): pass

    def reply(self, code, obj):
        body = json.dumps(obj).encode()
        self.send_response(code)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_POST(self):
        global outputs
        try:
            length = int(self.headers.get('Content-Length', 0))
            req    = json.loads(self.rfile.read(length))
        except Exception:
            self.reply(400, {'status': 'error', 'message': 'Bad request'})
            return

        action  = req.get('action', '')
        out_idx = int(req.get('out', 0))

        if action == 'reload':
            try:
                close_outputs(outputs)
                outputs = load_outputs()
                self.reply(200, {'status': 'ok', 'action': 'reload'})
            except Exception as e:
                self.reply(500, {'status': 'error', 'message': str(e)})
            return

        if out_idx < 0 or out_idx >= len(outputs):
            self.reply(400, {'status': 'error', 'message': f'Output {out_idx} not found'})
            return

        o    = outputs[out_idx]
        bus, addr, freq, ports = o['bus'], o['addr'], o['freq'], o['ports']

        try:
            if action == 'set':
                port = int(req.get('port', -1))
                us   = max(0, min(4000, int(req.get('us', 0))))
                set_ch(bus, addr, port, us_to_counts(us, freq))
                self.reply(200, {'status': 'ok', 'port': port, 'us': us})

            elif action == 'set_all':
                for ch in req.get('channels', []):
                    port = int(ch.get('port', -1))
                    us   = max(0, min(4000, int(ch.get('us', 0))))
                    if 0 <= port < len(ports):
                        set_ch(bus, addr, port, us_to_counts(us, freq))
                self.reply(200, {'status': 'ok', 'action': 'set_all'})

            elif action == 'stop':
                for i, p in enumerate(ports):
                    mn  = p.get('min', 500)
                    mx  = p.get('max', 2500)
                    ctr = p.get('center', (mn + mx) // 2)
                    set_ch(bus, addr, i, us_to_counts(ctr, freq))
                self.reply(200, {'status': 'ok', 'action': 'stop'})

            else:
                self.reply(400, {'status': 'error', 'message': f'Unknown action: {action}'})

        except Exception as e:
            self.reply(500, {'status': 'error', 'message': str(e)})

def main():
    global outputs
    try:
        outputs = load_outputs()
    except Exception as e:
        print(f'ERROR loading servo outputs: {e}', file=sys.stderr)
        sys.exit(1)

    def shutdown(sig, frame):
        close_outputs(outputs)
        sys.exit(0)
    signal.signal(signal.SIGTERM, shutdown)
    signal.signal(signal.SIGINT,  shutdown)

    server = HTTPServer((HOST, PORT_NUM), Handler)
    print(f'Servo daemon listening on {HOST}:{PORT_NUM}', flush=True)
    server.serve_forever()

if __name__ == '__main__':
    main()
