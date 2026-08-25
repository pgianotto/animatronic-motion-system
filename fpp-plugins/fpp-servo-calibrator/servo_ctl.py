#!/usr/bin/env python3
import sys, json, time
import http.client
import smbus2

def err(msg):
    print(json.dumps({'status': 'error', 'message': msg}))
    sys.exit(1)

def load_output(idx):
    # Fetch co-other.json's contents through FPP's documented channel-output
    # API rather than reading the config file directly — the file's format is
    # not a stable contract across FPP releases.
    try:
        conn = http.client.HTTPConnection('localhost', 80, timeout=3)
        conn.request('GET', '/api/channel/output/co-other')
        cfg = json.loads(conn.getresponse().read())
        conn.close()
    except Exception as e:
        err(f'Config read: {e}')
    outputs = [o for o in cfg.get('channelOutputs', [])
               if o.get('ports') and o.get('enabled')]
    if idx < 0 or idx >= len(outputs):
        err(f'Output index {idx} out of range (have {len(outputs)})')
    return outputs[idx]

def open_bus(out):
    dev = out.get('device', 'i2c-1')
    try:
        return smbus2.SMBus(int(dev.split('-')[-1]))
    except Exception as e:
        err(f'I2C open: {e}')

def wake(bus, addr):
    m = bus.read_byte_data(addr, 0x00)
    if m & 0x10:
        bus.write_byte_data(addr, 0x00, m & ~0x10)
        time.sleep(0.005)

def actual_freq(bus, addr):
    pre = bus.read_byte_data(addr, 0xFE)
    return 25_000_000 / (4096 * (pre + 1))

def us_to_counts(us, freq):
    return round(us * freq * 4096 / 1_000_000)

def set_ch(bus, addr, ch, counts):
    base = 0x06 + ch * 4
    bus.write_byte_data(addr, base,   0x00)
    bus.write_byte_data(addr, base+1, 0x00)
    bus.write_byte_data(addr, base+2, counts & 0xFF)
    bus.write_byte_data(addr, base+3, counts >> 8)

def main():
    if len(sys.argv) < 3:
        err('Usage: servo_ctl.py <action> <out_idx> [args...]')

    action  = sys.argv[1]
    out_idx = int(sys.argv[2])
    out     = load_output(out_idx)
    addr    = out.get('deviceID', 0x40)
    ports   = out.get('ports', [])
    bus     = open_bus(out)

    wake(bus, addr)
    freq = actual_freq(bus, addr)

    try:
        if action == 'set':
            if len(sys.argv) < 5:
                err('set requires <out_idx> <port> <us>')
            port = int(sys.argv[3])
            us   = int(sys.argv[4])
            if port < 0 or port >= len(ports):
                err(f'Port {port} out of range (0-{len(ports)-1})')
            p  = ports[port]
            us = max(p.get('min', 500), min(p.get('max', 2500), us))
            set_ch(bus, addr, port, us_to_counts(us, freq))
            print(json.dumps({'status': 'ok', 'port': port, 'us': us}))

        elif action == 'stop':
            for i, p in enumerate(ports):
                mn  = p.get('min', 500)
                mx  = p.get('max', 2500)
                ctr = p.get('center', (mn + mx) // 2)
                set_ch(bus, addr, i, us_to_counts(ctr, freq))
            print(json.dumps({'status': 'ok', 'action': 'stop'}))

        elif action == 'set_all':
            # Args after out_idx: port us port us ...
            args = sys.argv[3:]
            if len(args) % 2 != 0:
                err('set_all requires port us pairs')
            for i in range(0, len(args), 2):
                port = int(args[i])
                us   = int(args[i + 1])
                if port < 0 or port >= len(ports):
                    continue
                p  = ports[port]
                us = max(p.get('min', 500), min(p.get('max', 2500), us))
                set_ch(bus, addr, port, us_to_counts(us, freq))
            print(json.dumps({'status': 'ok', 'action': 'set_all'}))

        else:
            err(f'Unknown action: {action}')
    finally:
        bus.close()

main()
