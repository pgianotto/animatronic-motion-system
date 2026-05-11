<?php
// Direct I2C servo controller — bypasses fppd, writes to PCA9685 via Python.
header('Content-Type: application/json');

$body = file_get_contents('php://input');
if (!$body) { echo json_encode(['status' => 'error', 'message' => 'No body']); exit; }

$req = json_decode($body, true);
if (!$req || !isset($req['action'])) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
    exit;
}

$script = escapeshellarg(__DIR__ . '/servo_ctl.py');
$action = $req['action'];

if ($action === 'set') {
    $port = (int)($req['port'] ?? -1);
    $us   = (int)($req['us']   ?? 0);
    if ($port < 0 || $port > 31) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid port']); exit;
    }
    if ($us < 0 || $us > 4000) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid us']); exit;
    }
    $out = shell_exec("python3 $script set $port $us 2>&1");
    echo $out ?: json_encode(['status' => 'error', 'message' => 'Script failed']);

} elseif ($action === 'stop') {
    $out = shell_exec("python3 $script stop 2>&1");
    echo $out ?: json_encode(['status' => 'error', 'message' => 'Script failed']);

} else {
    echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
}
