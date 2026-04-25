<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

// Load .env
$envFile = __DIR__ . '/../.env';
$env = [];
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        [$key, $val] = explode('=', $line, 2);
        $env[trim($key)] = trim($val);
    }
}

$PP_KEY = $env['PREMIUMPAY_API_KEY'] ?? '';
$PP_BASE = $env['PREMIUMPAY_BASE_URL'] ?? 'https://dev.premiumpay.pro/api/v1';
$CALLBACK_URL = 'https://momentocrypto.com/api/webhook-card.php';

$input = json_decode(file_get_contents('php://input'), true);
$package = $input['package'] ?? '';
$email = $input['email'] ?? '';

$packages = [
    'trial'   => ['amount' => 7, 'name' => 'Trial — 1 Week'],
    'starter' => ['amount' => 25, 'name' => 'Starter — 1 Month'],
    'trader'  => ['amount' => 60, 'name' => 'Trader — 3 Months'],
    'pro'     => ['amount' => 100, 'name' => 'Pro — 6 Months'],
];

if (!isset($packages[$package])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid package']);
    exit;
}

$pkg = $packages[$package];
$orderId = $package . '_' . time() . '_' . bin2hex(random_bytes(4));

// Generate one-time token for success page
$token = bin2hex(random_bytes(32));
$tokensFile = __DIR__ . '/tokens.json';
$tokens = file_exists($tokensFile) ? json_decode(file_get_contents($tokensFile), true) : [];
$tokens[$token] = [
    'package' => $package,
    'order_id' => $orderId,
    'created' => time(),
    'used' => false,
];
$tokens = array_filter($tokens, fn($t) => $t['created'] > time() - 86400);
file_put_contents($tokensFile, json_encode($tokens));

// Get client IP
$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '8.8.8.8';
$ip = trim(explode(',', $ip)[0]);

// Create PremiumPay payment
$data = [
    'amount' => $pkg['amount'],
    'currency' => 'EUR',
    'productDescription' => $pkg['name'],
    'productName' => 'MomentoCrypto ' . $pkg['name'],
    'clientOrderId' => $orderId,
    'clientIP' => $ip,
    'clientEmail' => $email ?: 'customer@momentocrypto.com',
    'okurl' => 'https://momentocrypto.com/success.php?t=' . $token,
    'kourl' => 'https://momentocrypto.com/payment-failed.html',
    'cancelurl' => 'https://momentocrypto.com/#packages',
    'callbackurl' => $CALLBACK_URL,
    'paymentMethods' => ['stripe2'],
    'expiry' => 30,
    'headers' => true,
    'required_fields' => [
        'email' => $email ?: '',
    ],
];

$ch = curl_init($PP_BASE . '/makepayment');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($data),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $PP_KEY,
    ],
    CURLOPT_TIMEOUT => 30,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Debug log
file_put_contents(__DIR__ . '/pay_card_debug.log', date('Y-m-d H:i:s') . "\nHTTP: {$httpCode}\nRequest: " . json_encode($data) . "\nResponse: {$response}\n\n", FILE_APPEND);

$result = json_decode($response, true);

if ($result && $result['status'] === 'ok' && isset($result['redirect'])) {
    // Telegram notification: payment initiated
    $tgToken = $env['TELEGRAM_BOT_TOKEN'] ?? '';
    $tgChat = $env['TELEGRAM_CHAT_ID'] ?? '';
    if ($tgToken && $tgChat) {
        // Geo lookup
        $country = '';
        if ($ip && !in_array($ip, ['127.0.0.1', '::1'])) {
            $geoCh = curl_init("http://ip-api.com/json/{$ip}?fields=status,country,countryCode,city");
            curl_setopt_array($geoCh, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
            $geo = json_decode(curl_exec($geoCh), true);
            curl_close($geoCh);
            if (($geo['status'] ?? '') === 'success') {
                $flag = $geo['countryCode'] ?? '';
                if ($flag && strlen($flag) === 2 && function_exists('mb_chr')) {
                    $flag = mb_chr(0x1F1E6 + ord($flag[0]) - 65) . mb_chr(0x1F1E6 + ord($flag[1]) - 65);
                }
                $country = trim(($flag ? $flag . ' ' : '') . ($geo['country'] ?? ''));
            }
        }
        $msg = "🟡 *Card payment initiated*\n\n"
            . "📦 Plan: *{$pkg['name']}*\n"
            . "💵 Amount: *€{$pkg['amount']}*\n"
            . "🆔 Order: `{$orderId}`\n"
            . "💳 Method: Card (PremiumPay)\n"
            . ($country ? "🌍 {$country}\n" : "")
            . "🕐 " . date('Y-m-d H:i') . " UTC";
        $tgCh = curl_init("https://api.telegram.org/bot{$tgToken}/sendMessage");
        curl_setopt_array($tgCh, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['chat_id' => $tgChat, 'text' => $msg, 'parse_mode' => 'Markdown']),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);
        curl_exec($tgCh);
        curl_close($tgCh);
    }

    echo json_encode([
        'ok' => true,
        'redirect' => $result['redirect'],
        'payment_id' => $result['paymentId'] ?? null,
        'order_id' => $orderId,
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to create payment',
        'details' => $result,
    ]);
}
