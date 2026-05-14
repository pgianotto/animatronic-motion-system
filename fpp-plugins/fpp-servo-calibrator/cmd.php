<?php
// Servo command proxy — forwards to persistent servo_daemon on localhost:5003.
// Note: no header() call here — FPP's config.php has already sent output.
$body = file_get_contents('php://input');
if (!$body) { echo json_encode(['status' => 'error', 'message' => 'No body']); exit; }
if (!json_decode($body)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
    exit;
}

$ctx = stream_context_create(['http' => [
    'method'        => 'POST',
    'header'        => "Content-Type: application/json\r\n",
    'content'       => $body,
    'timeout'       => 3,
    'ignore_errors' => true,
]]);
$result = @file_get_contents('http://127.0.0.1:5003/', false, $ctx);
echo $result !== false ? $result : json_encode(['status' => 'error', 'message' => 'Servo daemon not running — check: sudo systemctl status fpp-servo-calibrator']);
