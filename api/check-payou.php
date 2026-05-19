<?php
// Polls Payou status API for recent orders, sends TG notification when paid
// Run via cron every 2 minutes: */2 * * * * php /root/MomentoCrypto/api/check-payou.php

require_once __DIR__ . '/meta_capi.php';

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
$TG_TOKEN = $env['TELEGRAM_BOT_TOKEN'] ?? '';
$TG_CHAT = $env['TELEGRAM_CHAT_ID'] ?? '';

if (!$MERCHANT_ID || !$SECRET_KEY) { echo "Missing Payou credentials\n"; exit; }

// Read pending orders (created in last 2 hours, not yet confirmed)
$pendingFile = __DIR__ . '/payou_pending.json';
$pending = file_exists($pendingFile) ? json_decode(file_get_contents($pendingFile), true) ?: [] : [];

// Clean orders older than 2 hours
$pending = array_filter($pending, fn($o) => $o['created'] > time() - 7200);

if (empty($pending)) { exit; }

$salesFile = __DIR__ . '/sales.json';
$tokensFile = __DIR__ . '/tokens.json';
$packageNames = [
    'starter' => 'Starter (1 Month)',
    'trader' => 'Trader (3 Months)',
    'pro' => 'Pro (6 Months)',
];

$changed = false;
foreach ($pending as $key => &$order) {
    if ($order['status'] === 'paid') continue;

    $orderId = $order['order_id'];
    $hash = md5($MERCHANT_ID . ':' . $SECRET_KEY . ':' . $orderId);
    $url = "https://payou.pro/api/status2?id={$MERCHANT_ID}&order_id={$orderId}&hash={$hash}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $response = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($response, true);
    $status = $result['status'] ?? '';

    echo "[Check] Order {$orderId}: status={$status}\n";

    if ($status === 'success') {
        $order['status'] = 'paid';
        $changed = true;
        $package = explode('_', $orderId)[0] ?? 'unknown';
        $amount = floatval($result['summ'] ?? $order['amount'] ?? 0);
        $currency = $order['currency'] ?? 'EUR';
        $method = $order['method'] ?? 'payou_card';
        $isPix = ($method === 'payou_pix' || $currency === 'BRL');

        // Log to sales.json
        $sales = file_exists($salesFile) ? json_decode(file_get_contents($salesFile), true) ?: [] : [];
        $alreadyLogged = false;
        foreach ($sales as $s) {
            if (($s['order_id'] ?? '') === $orderId) { $alreadyLogged = true; break; }
        }
        if (!$alreadyLogged) {
            $attribution = $order['attribution'] ?? [];
            $sales[] = [
                'ts' => time(),
                'date' => date('Y-m-d H:i:s'),
                'order_id' => $orderId,
                'payment_id' => $result['id'] ?? '',
                'package' => $package,
                'amount' => $amount,
                'currency' => $currency,
                'method' => $method,
                'attribution' => $attribution,
            ];
            file_put_contents($salesFile, json_encode($sales, JSON_PRETTY_PRINT));

            // Fire Meta Conversions API Purchase event server-side
            mc_send_meta_purchase([
                'order_id' => $orderId,
                'package' => $package,
                'amount' => $amount,
                'currency' => $currency,
                'attribution' => $attribution,
            ]);
        }

        // Send TG notification (Pix or Card)
        if ($TG_TOKEN && $TG_CHAT) {
            $name = $packageNames[$package] ?? $package;
            if ($isPix) {
                $msg = "💸 Pagamento Pix confirmado! (Payou BR)\n\n"
                    . "Plano: {$name}\n"
                    . "Valor: R$ {$amount}\n"
                    . "Pedido: {$orderId}\n"
                    . "Horário: " . date('Y-m-d H:i') . " UTC";
            } else {
                $msg = "💳 Card payment confirmed! (Payou)\n\n"
                    . "Plan: {$name}\n"
                    . "Amount: {$currency} {$amount}\n"
                    . "Order: {$orderId}\n"
                    . "Time: " . date('Y-m-d H:i') . " UTC";
            }
            $tgCh = curl_init("https://api.telegram.org/bot{$TG_TOKEN}/sendMessage");
            curl_setopt_array($tgCh, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query(['chat_id' => $TG_CHAT, 'text' => $msg]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
            ]);
            curl_exec($tgCh);
            curl_close($tgCh);
            echo "[Check] PAID! TG notification sent for {$orderId}\n";
        }
    }
}

if ($changed) {
    file_put_contents($pendingFile, json_encode(array_values($pending), JSON_PRETTY_PRINT));
}
