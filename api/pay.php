<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

// ===== OXAPAY CREDENTIALS =====
$MERCHANT_API_KEY = 'YOUR_OXAPAY_MERCHANT_API_KEY';
// ===============================

$CALLBACK_URL = 'https://momentocrypto.com/api/webhook.php';
$RETURN_URL = 'https://momentocrypto.com/success.html';

$input = json_decode(file_get_contents('php://input'), true);
$package = $input['package'] ?? '';

$packages = [
    'starter' => ['amount' => 50, 'name' => 'Starter — 1 Month'],
    'trader'  => ['amount' => 120, 'name' => 'Trader — 3 Months'],
    'pro'     => ['amount' => 200, 'name' => 'Pro — 6 Months'],
];

if (!isset($packages[$package])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid package']);
    exit;
}

$pkg = $packages[$package];
$orderId = $package . '_' . time() . '_' . bin2hex(random_bytes(4));

$data = [
    'merchant_api_key' => $MERCHANT_API_KEY,
    'amount' => $pkg['amount'],
    'currency' => 'USD',
    'order_id' => $orderId,
    'callback_url' => $CALLBACK_URL,
    'return_url' => $RETURN_URL,
    'description' => $pkg['name'],
    'thanks_message' => 'Payment successful! Copy your activation code on the next page.',
    'lifetime' => 60,
];

$ch = curl_init('https://api.oxapay.com/v1/payment/invoice');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($data),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
    ],
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$result = json_decode($response, true);

if ($result && isset($result['data']['payment_url'])) {
    echo json_encode([
        'ok' => true,
        'payment_url' => $result['data']['payment_url'],
        'order_id' => $orderId,
        'track_id' => $result['data']['track_id'] ?? null,
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to create invoice',
        'details' => $result,
    ]);
}
