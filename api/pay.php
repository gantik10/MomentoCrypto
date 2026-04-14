<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

// ===== LOAD .env =====
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        [$key, $val] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($val);
    }
}
$MERCHANT_API_KEY = $_ENV['OXAPAY_MERCHANT_API_KEY'] ?? '';
$SANDBOX = ($_ENV['PAYMENT_SANDBOX'] ?? 'false') === 'true';
// ===============================

$CALLBACK_URL = 'https://momentocrypto.com/api/webhook.php';

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

// Generate unique one-time token for success page
$token = bin2hex(random_bytes(32));
$tokensFile = __DIR__ . '/tokens.json';
$tokens = file_exists($tokensFile) ? json_decode(file_get_contents($tokensFile), true) : [];
$tokens[$token] = [
    'package' => $package,
    'order_id' => $orderId,
    'created' => time(),
    'used' => false,
];
// Clean tokens older than 24h
$tokens = array_filter($tokens, fn($t) => $t['created'] > time() - 86400);
file_put_contents($tokensFile, json_encode($tokens));

$data = [
    'amount' => $pkg['amount'],
    'currency' => 'USD',
    'order_id' => $orderId,
    'callback_url' => $CALLBACK_URL,
    'return_url' => 'https://momentocrypto.com/success.php?t=' . $token,
    'description' => $pkg['name'],
    'thanks_message' => 'Payment successful! Copy your activation code on the next page.',
    'lifetime' => 60,
    'fee_paid_by_payer' => 0,
    'sandbox' => $SANDBOX,
];

$ch = curl_init('https://api.oxapay.com/v1/payment/invoice');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($data),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'User-Agent: MomentoCrypto/1.0',
        'merchant_api_key: ' . $MERCHANT_API_KEY,
    ],
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => true,
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Debug log
file_put_contents(__DIR__ . '/pay_debug.log', date('Y-m-d H:i:s') . "\nHTTP: {$httpCode}\nCurl Error: {$curlError}\nRequest: " . json_encode($data) . "\nResponse: {$response}\n\n", FILE_APPEND);

$result = json_decode($response, true);

if ($result && isset($result['data']['payment_url'])) {
    // Notify Telegram: payment initiated
    $tgToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
    $tgChat = $_ENV['TELEGRAM_CHAT_ID'] ?? '';
    if ($tgToken && $tgChat) {
        $country = $_SERVER['HTTP_CF_IPCOUNTRY'] ?? '';
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        $ip = explode(',', $ip)[0];
        $msg = "🟡 *Payment initiated*\n\n"
            . "📦 Plan: *{$pkg['name']}*\n"
            . "💵 Amount: *\${$pkg['amount']} USD*\n"
            . "🆔 Order: `{$orderId}`\n"
            . ($country ? "🌍 Country: {$country}\n" : "")
            . ($ip ? "🖥 IP: `{$ip}`\n" : "")
            . "🕐 " . date('Y-m-d H:i') . " UTC";
        $tgCh = curl_init("https://api.telegram.org/bot{$tgToken}/sendMessage");
        curl_setopt_array($tgCh, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'chat_id' => $tgChat,
                'text' => $msg,
                'parse_mode' => 'Markdown',
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);
        curl_exec($tgCh);
        curl_close($tgCh);
    }

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
