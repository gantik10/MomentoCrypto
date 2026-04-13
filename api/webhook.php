<?php
// OxaPay sends POST webhook when payment status changes
// Logs all webhooks for reference

$logFile = __DIR__ . '/payments.log';
$input = file_get_contents('php://input');
$data = json_decode($input, true);

$logEntry = [
    'ts' => date('Y-m-d H:i:s'),
    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    'status' => $data['status'] ?? '',
    'track_id' => $data['track_id'] ?? '',
    'order_id' => $data['order_id'] ?? '',
    'amount' => $data['amount'] ?? '',
    'currency' => $data['currency'] ?? '',
    'data' => $data,
];

file_put_contents($logFile, json_encode($logEntry) . "\n", FILE_APPEND);

// Respond 200 OK to OxaPay
http_response_code(200);
echo json_encode(['ok' => true]);
