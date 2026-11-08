<?php

// Router for `php -S`: a stand-in hub. Status comes from $LW_HUB_DIR/mode;
// every request is logged to $LW_HUB_DIR/requests.jsonl.

$dir = getenv('LW_HUB_DIR');
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$body = file_get_contents('php://input');
$json = json_decode($body, true);
file_put_contents("$dir/requests.jsonl", json_encode([
    'auth' => $auth,
    'date' => $json['date'] ?? null,
    'bytes' => strlen($body),
]) . "\n", FILE_APPEND);

$status = $auth === 'Bearer lw_test_token' ? (int) trim((string) @file_get_contents("$dir/mode")) : 401;
http_response_code($status ?: 202);
header('Content-Type: application/json');
echo json_encode($status === 202 ? ['data' => ['received_at' => 'now']] : ['message' => "status $status"]);
