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

$MERCHANT_ID = $env['PAYOU_MERCHANT_ID'] ?? '';
$SECRET_KEY = $env['PAYOU_SECRET_KEY'] ?? '';
$PAYMENT_SYSTEM = $env['PAYOU_SYSTEM'] ?? 'card_EUR';

$input = json_decode(file_get_contents('php://input'), true);
$package = $input['package'] ?? '';

// EUR amounts for Payou card payments
$packages = [
    'starter' => ['amount' => 23, 'name' => 'Starter — 1 Month'],
    'trader'  => ['amount' => 55, 'name' => 'Trader — 3 Months'],
    'pro'     => ['amount' => 92, 'name' => 'Pro — 6 Months'],
];

if (!isset($packages[$package])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid package']);
    exit;
}

if (!$MERCHANT_ID || !$SECRET_KEY) {
    http_response_code(500);
    echo json_encode(['error' => 'Payou not configured — set PAYOU_MERCHANT_ID and PAYOU_SECRET_KEY in .env']);
    exit;
}

$pkg = $packages[$package];
$orderId = $package . '_' . time() . '_' . bin2hex(random_bytes(4));
$amount = number_format($pkg['amount'], 2, '.', '');

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

// Calculate Payou hash: md5(id:summ:password:sistems:order_id)
$hash = md5($MERCHANT_ID . ':' . $amount . ':' . $SECRET_KEY . ':' . $PAYMENT_SYSTEM . ':' . $orderId);

// Build redirect URL
$params = http_build_query([
    'id' => $MERCHANT_ID,
    'sistems' => $PAYMENT_SYSTEM,
    'summ' => $amount,
    'order_id' => $orderId,
    'Coment' => $pkg['name'],
    'user_code' => 'mc_' . bin2hex(random_bytes(4)),
    'user_email' => 'customer@momentocrypto.com',
    'hash' => $hash,
]);
$redirectUrl = 'https://payou.pro/sci/v1/?' . $params;

// Debug log
file_put_contents(__DIR__ . '/pay_payou_debug.log', date('Y-m-d H:i:s') . "\nOrder: {$orderId}\nAmount: {$amount}\nSystem: {$PAYMENT_SYSTEM}\nHash: {$hash}\nRedirect: {$redirectUrl}\n\n", FILE_APPEND);

// Telegram notification
$tgToken = $env['TELEGRAM_BOT_TOKEN'] ?? '';
$tgChat = $env['TELEGRAM_CHAT_ID'] ?? '';
if ($tgToken && $tgChat) {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $ip = trim(explode(',', $ip)[0]);
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
    $msg = "🟡 *Card payment initiated (Payou)*\n\n"
        . "📦 Plan: *{$pkg['name']}*\n"
        . "💵 Amount: *\${$pkg['amount']}*\n"
        . "🆔 Order: `{$orderId}`\n"
        . "💳 Method: Payou ({$PAYMENT_SYSTEM})\n"
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
    'redirect' => $redirectUrl,
    'order_id' => $orderId,
    'token' => $token,
]);
