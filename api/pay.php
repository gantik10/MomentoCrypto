<?php
header('Content-Type: application/json');

// ===== HELEKET CREDENTIALS =====
// Replace these with your real credentials from Heleket dashboard
$MERCHANT_UUID = 'YOUR_MERCHANT_UUID_HERE';
$API_KEY = 'YOUR_API_KEY_HERE';
// ================================

$CALLBACK_URL = 'https://momentocrypto.com/api/webhook.php';
$SUCCESS_URL = 'https://momentocrypto.com/success.html';
$RETURN_URL = 'https://momentocrypto.com/#packages';

$input = json_decode(file_get_contents('php://input'), true);
$package = $input['package'] ?? '';

$packages = [
    'starter' => ['amount' => '50', 'name' => 'Starter (1 Month)'],
    'trader'  => ['amount' => '120', 'name' => 'Trader (3 Months)'],
    'pro'     => ['amount' => '200', 'name' => 'Pro (6 Months)'],
];

if (!isset($packages[$package])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid package']);
    exit;
}

$pkg = $packages[$package];
$orderId = $package . '_' . time() . '_' . bin2hex(random_bytes(4));

$data = [
    'amount' => $pkg['amount'],
    'currency' => 'USD',
    'order_id' => $orderId,
    'url_callback' => $CALLBACK_URL,
    'url_return' => $RETURN_URL,
    'url_success' => $SUCCESS_URL,
    'lifetime' => 3600,
];

$jsonData = json_encode($data);
$sign = md5(base64_encode($jsonData) . $API_KEY);

$ch = curl_init('https://api.heleket.com/v1/payment');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $jsonData,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'merchant: ' . $MERCHANT_UUID,
        'sign: ' . $sign,
    ],
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$result = json_decode($response, true);

if ($result && isset($result['result']['url'])) {
    echo json_encode([
        'ok' => true,
        'payment_url' => $result['result']['url'],
        'order_id' => $orderId,
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to create invoice',
        'details' => $result,
    ]);
}
