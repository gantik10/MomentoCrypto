<?php
// Whop post-payment claim: verifies a payment server-side via the Whop API (v1),
// then issues a success token and redirects the buyer to /success.php?t=...
// Whop buyers get a Telegram deep link carrying their Whop membership id
// (t.me/momentocrypto_bot?start=mem_XXX) so the bot can bind the Telegram user to
// the subscription and follow renewals/cancellations via Whop webhooks.
// OxaPay / other methods keep the activation-code flow in success.php.
//
// Entry points:
//   - index.html onComplete  → /api/whop-claim.php?payment=pay_XXX  (or ?setup=sint_XXX)
//   - Whop checkout returnUrl (off-site steps) → ?payment=pay_XXX&status=succeeded|failed|canceled
//
// Free trials: the signup charges nothing, so Whop hands back either a $0 payment or a
// setup intent (card saved, no payment). Both are accepted once Whop shows the membership
// as trialing/active; the first real charge happens on Whop's side when the trial ends.
//
// Idempotent per payment / setup intent: repeat visits reuse the same token (success.php
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
$WHOP_COMPANY = 'biz_VKsE4rMpSLxud8';

// Whop plan → site package
$PLAN_MAP = [
    'plan_wK02ujxaHuVbX' => ['package' => 'starter', 'name' => 'Starter (1 Month)', 'usd' => 20],
    'plan_ExxqOfZ011XoL' => ['package' => 'trader',  'name' => 'Trader (3 Months)', 'usd' => 50],
    'plan_EJRrxW0ltWGn9' => ['package' => 'pro',     'name' => 'Pro (6 Months)', 'usd' => 90],
    // Recurring versions (whop.html): first payment issues the same activation code
    'plan_o4pZx6sSsYnpR' => ['package' => 'starter', 'name' => 'Starter (1 Month, recurring)', 'usd' => 20],
    'plan_qjAl1ZXSspoA8' => ['package' => 'trader',  'name' => 'Trader (3 Months, recurring)', 'usd' => 50],
    'plan_jqaoTVAwb4TNK' => ['package' => 'pro',     'name' => 'Pro (6 Months, recurring)', 'usd' => 90],
    // $1 / 7 days test plan (button on /whop only): full flow, but kept out of Meta + sales totals
    'plan_lGmofeRqtTwRn' => ['package' => 'starter', 'name' => 'TEST $1 / week (flow check)', 'usd' => 1, 'test' => true],
];

function bail_to_landing(string $reason): void {
    file_put_contents(__DIR__ . '/whop_claim.log',
        date('Y-m-d H:i:s') . " | BAIL | {$reason} | qs=" . ($_SERVER['QUERY_STRING'] ?? '') . "\n", FILE_APPEND);
    header('Location: /');
    exit;
}

function whop_get(string $path, string $key): array {
    $ch = curl_init("https://api.whop.com/api/v1" . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key],
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$http, json_decode($resp ?: '', true)];
}

$paymentId = $_GET['payment'] ?? '';
$setupId = $_GET['setup'] ?? ($_GET['setup_intent_id'] ?? '');
$status = $_GET['status'] ?? '';

// returnUrl round-trips for failed/canceled off-site steps come back here too
if ($status !== '' && $status !== 'succeeded') bail_to_landing("status={$status}");
$hasPay = preg_match('/^pay_[A-Za-z0-9]+$/', $paymentId);
$hasSetup = preg_match('/^sint_[A-Za-z0-9]+$/', $setupId);
if (!$hasPay && !$hasSetup) bail_to_landing('bad payment id');
if (!$WHOP_KEY) bail_to_landing('WHOP_API_KEY not configured');
$claimId = $hasPay ? $paymentId : $setupId;

// Idempotency: one token per payment / setup intent
$claimsFile = __DIR__ . '/whop_claims.json';
$claims = file_exists($claimsFile) ? (json_decode(file_get_contents($claimsFile), true) ?: []) : [];
if (isset($claims[$claimId])) {
    header('Location: /success.php?t=' . urlencode($claims[$claimId]));
    exit;
}

// Verify with Whop (server-side source of truth, API v1)
if ($hasPay) {
    [$http, $pay] = whop_get('/payments/' . rawurlencode($paymentId), $WHOP_KEY);
    if ($http !== 200 || !is_array($pay)) bail_to_landing("whop api http={$http}");
    if (!empty($pay['refunded_at']) || floatval($pay['refunded_amount']['amount'] ?? 0) > 0) bail_to_landing('payment refunded');

    $planId = $pay['plan_id'] ?? '';
    $amount = floatval($pay['total']['amount'] ?? 0);
    $currency = strtoupper($pay['currency'] ?? 'USD');
    // Membership = the subscription Whop renews/cancels; the bot keys on it
    $membershipId = preg_match('/^mem_[A-Za-z0-9]+$/', $pay['membership_id'] ?? '') ? $pay['membership_id'] : '';
    $whopUserId = $pay['user']['id'] ?? '';

    if (($pay['status'] ?? '') !== 'paid') {
        // A free-trial signup may come back as a $0 payment that isn't "paid":
        // accept it only when Whop shows the membership itself as trialing/active
        if ($amount > 0 || !$membershipId) bail_to_landing('payment status=' . ($pay['status'] ?? 'unknown'));
        [$mh, $mem] = whop_get('/memberships/' . rawurlencode($membershipId), $WHOP_KEY);
        if ($mh !== 200 || !in_array($mem['status'] ?? '', ['trialing', 'active'], true)) {
            bail_to_landing("zero payment, membership status=" . ($mem['status'] ?? "http {$mh}"));
        }
    }
} else {
    // Setup-mode checkout (free trial: card saved, nothing charged yet).
    // The setup intent has no plan/membership, so find the membership Whop created for this buyer.
    [$http, $si] = whop_get('/setup_intents/' . rawurlencode($setupId), $WHOP_KEY);
    if ($http !== 200 || !is_array($si)) bail_to_landing("whop setup api http={$http}");
    if (($si['status'] ?? '') !== 'succeeded') bail_to_landing('setup status=' . ($si['status'] ?? 'unknown'));
    $whopUserId = $si['member']['user']['id'] ?? '';
    if (!$whopUserId) bail_to_landing('setup intent without user');
    $since = strtotime($si['created_at'] ?? 'now') - 600;

    // Whop ignores the user filter on this list, so match the user on our side.
    // The membership can land a moment after the setup intent: retry briefly.
    $mem = null;
    for ($i = 0; $i < 4 && !$mem; $i++) {
        if ($i) usleep(1500000);
        [$lh, $list] = whop_get("/memberships?company_id={$WHOP_COMPANY}&first=50&order=created_at&direction=desc", $WHOP_KEY);
        foreach (($lh === 200 ? ($list['data'] ?? []) : []) as $m) {
            $mPlan = $m['plan_id'] ?? ($m['plan']['id'] ?? '');
            if (($m['user_id'] ?? ($m['user']['id'] ?? '')) === $whopUserId
                && isset($PLAN_MAP[$mPlan])
                && in_array($m['status'] ?? '', ['trialing', 'active'], true)
                && strtotime($m['created_at'] ?? '') >= $since) { $mem = $m; break; }
        }
    }
    if (!$mem) bail_to_landing('setup intent: no trialing membership found');

    $planId = $mem['plan_id'] ?? ($mem['plan']['id'] ?? '');
    $membershipId = $mem['id'];
    $amount = 0.0;
    $currency = strtoupper($mem['currency'] ?? 'USD');
}

if (!isset($PLAN_MAP[$planId])) bail_to_landing("unknown plan {$planId}");
$package = $PLAN_MAP[$planId]['package'];
$pkgName = $PLAN_MAP[$planId]['name'];
$planUsd = $PLAN_MAP[$planId]['usd'];
$isTest = !empty($PLAN_MAP[$planId]['test']);
$isTrial = $amount <= 0;   // free trial: Whop charges the plan price when it ends

// Buyer attribution: the buyer's own browser hits this endpoint, so IP/UA are genuine
$attribution = [
    'ip' => trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '')[0]),
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    'landing_url' => 'https://momentocrypto.com/',
];

// Issue the one-time success token (same shape as pay.php / pay-payou-*.php)
$token = bin2hex(random_bytes(32));
$tokensFile = __DIR__ . '/tokens.json';
$tokens = file_exists($tokensFile) ? (json_decode(file_get_contents($tokensFile), true) ?: []) : [];
$tokens[$token] = [
    'package' => $package,
    'order_id' => $claimId,
    'created' => time(),
    'used' => false,
    'locale' => 'en',
    'amount' => $amount,
    'currency' => $currency,
    'method' => 'whop',
    'membership_id' => $membershipId,
    'whop_user_id' => $whopUserId,
    'test' => $isTest,
    'trial' => $isTrial,
    'plan_usd' => $planUsd,
    'attribution' => $attribution,
];
$tokens = array_filter($tokens, fn($t) => $t['created'] > time() - 86400);
file_put_contents($tokensFile, json_encode($tokens));

// Record the claim for idempotency
$claims[$claimId] = $token;
file_put_contents($claimsFile, json_encode($claims, JSON_PRETTY_PRINT));

// Bookkeeping: sales.json (dedup by payment id), Meta CAPI Purchase, admin Telegram ping
$salesFile = __DIR__ . '/sales.json';
$sales = file_exists($salesFile) ? (json_decode(file_get_contents($salesFile), true) ?: []) : [];
$already = false;
foreach ($sales as $s) {
    if (($s['payment_id'] ?? '') === $claimId) { $already = true; break; }
}
if (!$already) {
    $sales[] = [
        'ts' => time(),
        'date' => date('Y-m-d H:i:s'),
        'order_id' => $claimId,
        'payment_id' => $claimId,
        'package' => $package,
        'amount' => $amount,
        'currency' => $currency,
        'method' => 'whop',
        'membership_id' => $membershipId,
        'whop_user_id' => $whopUserId,
        'test' => $isTest,
        'trial' => $isTrial,
        'attribution' => $attribution,
    ];
    file_put_contents($salesFile, json_encode($sales, JSON_PRETTY_PRINT));

    // Trials go to Meta as StartTrial (value 0, predicted LTV = plan price), not Purchase
    if (!$isTest) mc_send_meta_purchase([
        'order_id' => $claimId,
        'package' => $package,
        'amount' => $amount,
        'currency' => $currency,
        'attribution' => $attribution,
    ] + ($isTrial ? ['event_name' => 'StartTrial', 'predicted_ltv' => $planUsd] : []));

    $tgToken = $env['TELEGRAM_BOT_TOKEN'] ?? '';
    $tgChat = $env['TELEGRAM_CHAT_ID'] ?? '';
    if ($tgToken && $tgChat) {
        $msg = ($isTest ? "🧪 TEST " : "") . ($isTrial ? "🎁 Whop free trial started!\n\n" : "💳 Whop payment confirmed!\n\n")
            . "Plan: {$pkgName}\n"
            . "Amount: " . ($isTrial ? "free trial (then {$currency} {$planUsd})" : "{$currency} {$amount}") . "\n"
            . ($hasPay ? "Payment: {$paymentId}\n" : "Setup: {$setupId}\n")
            . "Membership: " . ($membershipId ?: '—') . "\n"
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
    date('Y-m-d H:i:s') . " | OK | {$claimId} | {$package} | {$currency} {$amount}" . ($isTrial ? ' TRIAL' : '') . " | " . ($membershipId ?: 'no-membership') . "\n", FILE_APPEND);

header('Location: /success.php?t=' . $token);
exit;
