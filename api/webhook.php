<?php
// Heleket sends POST webhook when payment status changes
// This logs all webhooks for reference

$logFile = __DIR__ . '/payments.log';
$input = file_get_contents('php://input');
$data = json_decode($input, true);

$logEntry = [
    'ts' => date('Y-m-d H:i:s'),
    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    'data' => $data,
];

file_put_contents($logFile, json_encode($logEntry) . "\n", FILE_APPEND);

// Respond 200 OK to Heleket
http_response_code(200);
echo json_encode(['ok' => true]);
