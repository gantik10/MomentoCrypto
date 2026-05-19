<?php
// Payou callback when card payment completes
// Payou POSTs: AMOUNT, status, intid, SIGN, MERCHANT_ORDER_ID
// Must respond: {order_id}|success or {order_id}|error

require_once __DIR__ . '/meta_capi.php';

$logFile = __DIR__ . '/payments-payou.log';
$salesFile = __DIR__ . '/sales.json';

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

// Read POST data
$amount = $_POST['AMOUNT'] ?? '';
$status = $_POST['status'] ?? '';
$intid = $_POST['intid'] ?? '';
$sign = $_POST['SIGN'] ?? '';
$orderId = $_POST['MERCHANT_ORDER_ID'] ?? '';

// Log everything
$logEntry = [
    'ts' => date('Y-m-d H:i:s'),
    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    'status' => $status,
    'intid' => $intid,
    'order_id' => $orderId,
    'amount' => $amount,
    'sign' => $sign,
    'post' => $_POST,
];
file_put_contents($logFile, json_encode($logEntry) . "\n", FILE_APPEND);

// Verify signature: md5(merchant_id:AMOUNT:secret:status:intid:MERCHANT_ORDER_ID)
$expectedSign = md5($MERCHANT_ID . ':' . $amount . ':' . $SECRET_KEY . ':' . $status . ':' . $intid . ':' . $orderId);

if ($sign !== $expectedSign) {
    file_put_contents($logFile, "SIGN MISMATCH — expected: {$expectedSign}, got: {$sign}\n", FILE_APPEND);
    echo "{$orderId}|error";
    exit;
}

if ($status === 'success') {
    $package = explode('_', $orderId)[0] ?? 'unknown';
    $packageNames = [
        'starter' => 'Starter (1 Month)',
        'trader' => 'Trader (3 Months)',
        'pro' => 'Pro (6 Months)',
    ];
    $packageNamesBR = [
        'starter' => 'Starter (1 Mês)',
        'trader' => 'Trader (3 Meses)',
        'pro' => 'Pro (6 Meses)',
    ];
    $amountFloat = floatval($amount);

    // Look up order metadata from pending file — BR orders use BRL/Pix
    $pendingFile = __DIR__ . '/payou_pending.json';
    $pending = file_exists($pendingFile) ? json_decode(file_get_contents($pendingFile), true) ?: [] : [];
    $currency = 'EUR';
    $method = 'payou_card';
    $attribution = [];
    foreach ($pending as $p) {
        if (($p['order_id'] ?? '') === $orderId) {
            $currency = $p['currency'] ?? 'EUR';
            $method = $p['method'] ?? 'payou_card';
            $attribution = $p['attribution'] ?? [];
            break;
        }
    }
    $isPix = ($method === 'payou_pix' || $currency === 'BRL');

    // Append to sales.json (prevent duplicates by intid)
    $sales = file_exists($salesFile) ? (json_decode(file_get_contents($salesFile), true) ?: []) : [];
    $alreadyLogged = false;
    foreach ($sales as $s) {
        if (($s['payment_id'] ?? '') === $intid && $intid !== '') { $alreadyLogged = true; break; }
    }
    if (!$alreadyLogged) {
        $sales[] = [
            'ts' => time(),
            'date' => date('Y-m-d H:i:s'),
            'order_id' => $orderId,
            'payment_id' => $intid,
            'package' => $package,
            'amount' => $amountFloat,
            'currency' => $currency,
            'method' => $method,
            'attribution' => $attribution,
        ];
        file_put_contents($salesFile, json_encode($sales, JSON_PRETTY_PRINT));

        // Fire Meta Conversions API Purchase event server-side
        mc_send_meta_purchase([
            'order_id' => $orderId,
            'package' => $package,
            'amount' => $amountFloat,
            'currency' => $currency,
            'attribution' => $attribution,
        ]);

        // Telegram notification — plain text (Markdown breaks on $, *, _)
        $token = $env['TELEGRAM_BOT_TOKEN'] ?? '';
        $chatId = $env['TELEGRAM_CHAT_ID'] ?? '';
        if ($token && $chatId) {
            if ($isPix) {
                $name = $packageNamesBR[$package] ?? $package;
                $msg = "💸 Pagamento Pix confirmado! (Payou BR)\n\n"
                    . "Plano: {$name}\n"
                    . "Valor: R$ {$amountFloat}\n"
                    . "Pedido: {$orderId}\n"
                    . "Horário: " . date('Y-m-d H:i') . " UTC";
            } else {
                $name = $packageNames[$package] ?? $package;
                $msg = "💳 Card payment confirmed! (Payou)\n\n"
                    . "Plan: {$name}\n"
                    . "Amount: {$currency} {$amountFloat}\n"
                    . "Order: {$orderId}\n"
                    . "Time: " . date('Y-m-d H:i') . " UTC";
            }
            $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query(['chat_id' => $chatId, 'text' => $msg]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
            ]);
            curl_exec($ch);
            curl_close($ch);
        }
    }

    echo "{$orderId}|success";
} else {
    echo "{$orderId}|error";
}
