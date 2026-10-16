<?php
// Whop post-payment claim: verifies a payment server-side via the Whop API,
// then issues the same one-time success token the OxaPay flow uses and
// redirects the buyer to /success.php?t=... (activation code → Telegram bot).
//
// Entry points:
//   - whop.html onComplete  → /api/whop-claim.php?payment=pay_XXX
//   - Whop checkout returnUrl (off-site steps) → ?payment=pay_XXX&status=succeeded|failed|canceled
//
// Idempotent per payment: repeat visits reuse the same token (success.php
// itself enforces one-time viewing of the activation code).

require_once __DIR__ . '/meta_capi.php';

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

$WHOP_KEY = $env['WHOP_API_KEY'] ?? '';

// Whop plan → site package
$PLAN_MAP = [
    'plan_wK02ujxaHuVbX' => ['package' => 'starter', 'name' => 'Starter (1 Month)'],
    'plan_ExxqOfZ011XoL' => ['package' => 'trader',  'name' => 'Trader (3 Months)'],
    'plan_EJRrxW0ltWGn9' => ['package' => 'pro',     'name' => 'Pro (6 Months)'],
];

function bail_to_landing(string $reason): void {
    file_put_contents(__DIR__ . '/whop_claim.log',
        date('Y-m-d H:i:s') . " | BAIL | {$reason} | qs=" . ($_SERVER['QUERY_STRING'] ?? '') . "\n", FILE_APPEND);
    header('Location: /whop');
    exit;
}

$paymentId = $_GET['payment'] ?? '';
$status = $_GET['status'] ?? '';

// returnUrl round-trips for failed/canceled off-site steps come back here too
if ($status !== '' && $status !== 'succeeded') bail_to_landing("status={$status}");
if (!preg_match('/^pay_[A-Za-z0-9]+$/', $paymentId)) bail_to_landing('bad payment id');
if (!$WHOP_KEY) bail_to_landing('WHOP_API_KEY not configured');

// Idempotency: one token per payment
$claimsFile = __DIR__ . '/whop_claims.json';
$claims = file_exists($claimsFile) ? (json_decode(file_get_contents($claimsFile), true) ?: []) : [];
if (isset($claims[$paymentId])) {
    header('Location: /success.php?t=' . urlencode($claims[$paymentId]));
    exit;
}

// Verify the payment with Whop (server-side source of truth)
$ch = curl_init("https://api.whop.com/api/v2/payments/" . rawurlencode($paymentId));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $WHOP_KEY],
]);
$resp = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$pay = json_decode($resp ?: '', true);

if ($http !== 200 || !is_array($pay)) bail_to_landing("whop api http={$http}");
if (($pay['status'] ?? '') !== 'paid') bail_to_landing('payment status=' . ($pay['status'] ?? 'unknown'));
if (!empty($pay['refunded_at'])) bail_to_landing('payment refunded');

$planId = $pay['plan'] ?? '';
if (!isset($PLAN_MAP[$planId])) bail_to_landing("unknown plan {$planId}");
$package = $PLAN_MAP[$planId]['package'];
$pkgName = $PLAN_MAP[$planId]['name'];
$amount = floatval($pay['final_amount'] ?? 0);
$currency = strtoupper($pay['currency'] ?? 'USD');

// Buyer attribution: the buyer's own browser hits this endpoint, so IP/UA are genuine
$attribution = [
    'ip' => trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '')[0]),
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    'landing_url' => 'https://momentocrypto.com/whop',
];

// Issue the one-time success token (same shape as pay.php / pay-payou-*.php)
$token = bin2hex(random_bytes(32));
$tokensFile = __DIR__ . '/tokens.json';
$tokens = file_exists($tokensFile) ? (json_decode(file_get_contents($tokensFile), true) ?: []) : [];
$tokens[$token] = [
    'package' => $package,
    'order_id' => $paymentId,
    'created' => time(),
    'used' => false,
    'locale' => 'en',
    'amount' => $amount,
    'currency' => $currency,
    'method' => 'whop',
    'attribution' => $attribution,
];
$tokens = array_filter($tokens, fn($t) => $t['created'] > time() - 86400);
file_put_contents($tokensFile, json_encode($tokens));

// Record the claim for idempotency
$claims[$paymentId] = $token;
file_put_contents($claimsFile, json_encode($claims, JSON_PRETTY_PRINT));

// Bookkeeping: sales.json (dedup by payment id), Meta CAPI Purchase, admin Telegram ping
$salesFile = __DIR__ . '/sales.json';
$sales = file_exists($salesFile) ? (json_decode(file_get_contents($salesFile), true) ?: []) : [];
$already = false;
foreach ($sales as $s) {
    if (($s['payment_id'] ?? '') === $paymentId) { $already = true; break; }
}
if (!$already) {
    $sales[] = [
        'ts' => time(),
        'date' => date('Y-m-d H:i:s'),
        'order_id' => $paymentId,
        'payment_id' => $paymentId,
        'package' => $package,
        'amount' => $amount,
        'currency' => $currency,
        'method' => 'whop',
        'attribution' => $attribution,
    ];
    file_put_contents($salesFile, json_encode($sales, JSON_PRETTY_PRINT));

    mc_send_meta_purchase([
        'order_id' => $paymentId,
        'package' => $package,
        'amount' => $amount,
        'currency' => $currency,
        'attribution' => $attribution,
    ]);

    $tgToken = $env['TELEGRAM_BOT_TOKEN'] ?? '';
    $tgChat = $env['TELEGRAM_CHAT_ID'] ?? '';
    if ($tgToken && $tgChat) {
        $msg = "💳 Whop payment confirmed!\n\n"
            . "Plan: {$pkgName}\n"
            . "Amount: {$currency} {$amount}\n"
            . "Payment: {$paymentId}\n"
            . "Time: " . date('Y-m-d H:i') . " UTC";
        $tg = curl_init("https://api.telegram.org/bot{$tgToken}/sendMessage");
        curl_setopt_array($tg, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['chat_id' => $tgChat, 'text' => $msg]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);
        curl_exec($tg);
        curl_close($tg);
    }
}

file_put_contents(__DIR__ . '/whop_claim.log',
    date('Y-m-d H:i:s') . " | OK | {$paymentId} | {$package} | {$currency} {$amount}\n", FILE_APPEND);

header('Location: /success.php?t=' . $token);
exit;
