<?php
// PremiumPay callback when card payment status changes

$logFile = __DIR__ . '/payments-card.log';
$salesFile = __DIR__ . '/sales.json';
$input = file_get_contents('php://input');
$data = json_decode($input, true) ?: [];

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

$logEntry = [
    'ts' => date('Y-m-d H:i:s'),
    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    'status' => $data['status'] ?? '',
    'paymentId' => $data['paymentId'] ?? '',
    'clientOrderId' => $data['clientOrderId'] ?? '',
    'amount' => $data['amount'] ?? '',
    'currency' => $data['currency'] ?? '',
    'data' => $data,
];
file_put_contents($logFile, json_encode($logEntry) . "\n", FILE_APPEND);

$status = strtolower($data['status'] ?? '');
$isPaid = in_array($status, ['ok', 'paid', 'confirmed', 'completed', 'success']);

if ($isPaid) {
    $orderId = $data['clientOrderId'] ?? '';
    $package = explode('_', $orderId)[0] ?? 'unknown';
    $packageNames = [
        'trial' => 'Trial (1 Week)',
        'starter' => 'Starter (1 Month)',
        'trader' => 'Trader (3 Months)',
        'pro' => 'Pro (6 Months)',
    ];
    $amount = floatval($data['amount'] ?? 0);
    $currency = $data['currency'] ?? 'EUR';

    // Append to sales.json
    $sales = file_exists($salesFile) ? (json_decode(file_get_contents($salesFile), true) ?: []) : [];
    $paymentId = $data['paymentId'] ?? '';
    $alreadyLogged = false;
    foreach ($sales as $s) {
        if (($s['payment_id'] ?? '') === $paymentId && $paymentId !== '') { $alreadyLogged = true; break; }
    }
    if (!$alreadyLogged) {
        $sales[] = [
            'ts' => time(),
            'date' => date('Y-m-d H:i:s'),
            'order_id' => $orderId,
            'payment_id' => $paymentId,
            'package' => $package,
            'amount' => $amount,
            'currency' => $currency,
            'method' => 'card',
        ];
        file_put_contents($salesFile, json_encode($sales, JSON_PRETTY_PRINT));

        // Telegram notification
        $token = $env['TELEGRAM_BOT_TOKEN'] ?? '';
        $chatId = $env['TELEGRAM_CHAT_ID'] ?? '';
        if ($token && $chatId) {
            $name = $packageNames[$package] ?? $package;
            $msg = "💳 *Card payment confirmed!*\n\n"
                . "📦 Plan: *{$name}*\n"
                . "💵 Amount: *${$amount} {$currency}*\n"
                . "🆔 Order: `{$orderId}`\n"
                . "🕐 " . date('Y-m-d H:i') . " UTC";
            $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query(['chat_id' => $chatId, 'text' => $msg, 'parse_mode' => 'Markdown']),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
            ]);
            curl_exec($ch);
            curl_close($ch);
        }
    }
}

http_response_code(200);
echo json_encode(['ok' => true]);
