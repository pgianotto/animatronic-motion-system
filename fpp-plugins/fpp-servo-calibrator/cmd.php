<?php
// Direct I2C servo controller — bypasses fppd, writes to PCA9685 via Python.
// Note: no header() call here — FPP's config.php has already sent output.
$body = file_get_contents('php://input');
if (!$body) { echo json_encode(['status' => 'error', 'message' => 'No body']); exit; }

$req = json_decode($body, true);
if (!$req || !isset($req['action'])) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
    exit;
}

$script = escapeshellarg(__DIR__ . '/servo_ctl.py');
$action = $req['action'];
$out    = (int)($req['out'] ?? 0);

if ($action === 'set') {
    $port = (int)($req['port'] ?? -1);
    $us   = (int)($req['us']   ?? 0);
    if ($port < 0 || $port > 31) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid port']); exit;
    }
    if ($us < 0 || $us > 4000) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid us']); exit;
    }
    $result = shell_exec("python3 $script set $out $port $us 2>&1");
    echo $result ?: json_encode(['status' => 'error', 'message' => 'Script failed']);

} elseif ($action === 'stop') {
    $result = shell_exec("python3 $script stop $out 2>&1");
    echo $result ?: json_encode(['status' => 'error', 'message' => 'Script failed']);

} elseif ($action === 'set_all') {
    $channels = $req['channels'] ?? [];
    $args = '';
    foreach ($channels as $ch) {
        $port = (int)($ch['port'] ?? -1);
        $us   = (int)($ch['us']   ?? 0);
        if ($port < 0 || $port > 31 || $us < 0 || $us > 4000) continue;
        $args .= " $port $us";
    }
    if (!$args) { echo json_encode(['status' => 'error', 'message' => 'No valid channels']); exit; }
    $result = shell_exec("python3 $script set_all $out$args 2>&1");
    echo $result ?: json_encode(['status' => 'error', 'message' => 'Script failed']);

} else {
    echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
}
