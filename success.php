<?php
// ===== VALIDATE ONE-TIME TOKEN =====
$tokensFile = __DIR__ . '/api/tokens.json';
$token = $_GET['t'] ?? '';
$code = null;
$error = false;

// Load .env for activation codes + pixel id
$envFile = __DIR__ . '/.env';
$env = [];
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        [$key, $val] = explode('=', $line, 2);
        $env[trim($key)] = trim($val);
    }
}

$activationCodes = [
    'starter' => $env['ACTIVATION_CODE_1M'] ?? '',
    'trader'  => $env['ACTIVATION_CODE_3M'] ?? '',
    'pro'     => $env['ACTIVATION_CODE_6M'] ?? '',
];

$metaPixelId = $env['META_PIXEL_ID'] ?? '';

// Order metadata pulled from the token (locale, amount, currency, attribution)
$package = '';
$orderId = '';
$amount = 0;
$currency = 'USD';
$locale = 'en';
$utmSource = '';
$membershipId = '';   // Whop subscription id → Telegram deep link instead of an activation code
$firstView = false;   // purchase tracking fires once per token
$isTrial = false;     // Whop free trial: nothing charged yet
$planUsd = 0;

if (!$token || strlen($token) !== 64) {
    $error = true;
} else {
    $tokens = file_exists($tokensFile) ? json_decode(file_get_contents($tokensFile), true) : [];

    // Whop tokens carry the membership id: the bot binds the Telegram user on /start, so the
    // page can be reopened (within the token's 24h lifetime) if Telegram didn't open the first time.
    // Code-based tokens (OxaPay etc.) stay strictly one-time because the code is shared.
    // Kill switch: WHOP_DEEPLINK=0 in .env sends Whop buyers back to the activation-code flow
    $isWhopLink = ($env['WHOP_DEEPLINK'] ?? '1') !== '0'
        && isset($tokens[$token])
        && ($tokens[$token]['method'] ?? '') === 'whop'
        && preg_match('/^mem_[A-Za-z0-9]+$/', $tokens[$token]['membership_id'] ?? '');

    if (!isset($tokens[$token])) {
        $error = true;
    } elseif ($tokens[$token]['used'] && !$isWhopLink) {
        $error = true;
    } else {
        $tokData = $tokens[$token];
        $firstView = empty($tokData['used']);
        if ($isWhopLink) $membershipId = $tokData['membership_id'];
        $isTrial = !empty($tokData['trial']);
        $planUsd = floatval($tokData['plan_usd'] ?? 0);
        $package = $tokData['package'] ?? '';
        $orderId = $tokData['order_id'] ?? '';
        $amount = floatval($tokData['amount'] ?? 0);
        $currency = $tokData['currency'] ?? 'USD';
        $locale = $tokData['locale'] ?? 'en';
        $utmSource = $tokData['attribution']['utm_source'] ?? '';
        $code = $activationCodes[$package] ?? '';

        // Mark token as used (first view only, keep the original timestamp on reopen)
        if ($firstView) {
            $tokens[$token]['used'] = true;
            $tokens[$token]['used_at'] = time();
            file_put_contents($tokensFile, json_encode($tokens));
        }
    }
}

$isIN = ($locale === 'en-IN');

// Localized strings — English only (BR site retired)
$t = [
    'title_ok' => 'PAYMENT SUCCESSFUL',
    'title_err' => 'LINK EXPIRED',
    'subtitle_ok' => 'Your payment has been confirmed. Use the activation code below to start your subscription.',
    'subtitle_err' => 'This activation link has already been used or is invalid. Each link can only be opened once. If you believe this is a mistake, contact support.',
    'code_label' => 'Your Activation Code',
    'copy' => 'COPY CODE',
    'copied' => 'COPIED!',
    'steps_title' => 'HOW TO ACTIVATE',
    'step1' => 'Open our Telegram bot by clicking the button below',
    'step2_pre' => 'Press', 'step2_strong' => 'Start', 'step2_mid' => 'or send', 'step2_cmd' => '/start', 'step2_post' => 'to the bot',
    'step3' => 'Send your activation code (shown above) to the bot',
    'step4_pre' => "Done! You'll get instant access to the", 'step4_strong' => 'private signals channel',
    'open_bot' => 'OPEN TELEGRAM BOT',
    'support' => 'CONTACT SUPPORT',
    'back' => '← Back to MomentoCrypto',
    'footer_note' => 'This link is one-time only. Save your code now. If you have any issues, contact support via the bot.',
    // Whop: deep link with the membership id, no code
    'title_trial' => 'FREE TRIAL STARTED',
    'subtitle_trial' => 'Your free trial is active &mdash; nothing is charged until it ends. Open our Telegram bot with the button below &mdash; your subscription is linked to your Telegram account automatically.',
    'trial_meta' => 'Free trial',
    'subtitle_link' => 'Your payment is confirmed. Open our Telegram bot with the button below &mdash; your subscription is linked to your Telegram account automatically.',
    'link_step1' => 'Tap <strong>Open Telegram bot</strong> below',
    'link_step2_pre' => 'Press', 'link_step2_post' => 'in the bot',
    'link_step3_pre' => "Done! You're added to the", 'link_step3_post' => 'and your access renews with your plan',
    'copy_link' => 'Copy bot link',
    'copied_link' => 'Link copied',
    'link_note' => "Telegram didn't open? Copy the link and open it on the device where you use Telegram. This page stays available for 24 hours. Need help? Contact support via the bot.",
];

// Build Telegram start param:
//   FB attributed buyers → ?start=tradefb (single channel for FB cohort)
//   Other buyers → paid_<orderid>_<utm_source> (per-order attribution)
$attribution = isset($tokData) ? ($tokData['attribution'] ?? []) : [];
$isFbUser = !empty($attribution['fbclid']) || !empty($attribution['fbc']) ||
    preg_match('/(facebook|^fb$|meta|instagram|^ig$)/i', $utmSource);
if ($membershipId) {
    // Whop: the start payload IS the membership id; the bot binds the Telegram user to it and
    // follows renewals/cancellations via Whop webhooks (membership.* / payment.*)
    $tgStart = $membershipId;
} elseif ($isFbUser) {
    $tgStart = 'tradefb';
} else {
    $tgStart = 'paid';
    if ($orderId) $tgStart .= '_' . substr(preg_replace('/[^a-zA-Z0-9_]/', '', $orderId), 0, 40);
    if ($utmSource) $tgStart .= '_' . substr(preg_replace('/[^a-zA-Z0-9]/', '', $utmSource), 0, 20);
}
$tgBotLink = 'https://t.me/momentocrypto_bot?start=' . urlencode($tgStart);

$currencySymbol = $currency === 'EUR' ? '€' : ($currency === 'INR' ? '₹' : '$');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $error ? $t['title_err'] : ($isTrial ? $t['title_trial'] : $t['title_ok']) ?> — MomentoCrypto</title>
<meta name="robots" content="noindex, nofollow">
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Bebas+Neue&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">

<?php if (!$error && $firstView && empty($tokData['test']) && $metaPixelId && $orderId): ?>
<!-- Meta Pixel — Purchase event, or StartTrial for a Whop free trial (dedup with CAPI via event_id = order_id) -->
<script>
!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
fbq('init', <?= json_encode($metaPixelId) ?>);
fbq('track', 'PageView');
fbq('track', <?= json_encode($isTrial ? 'StartTrial' : 'Purchase') ?>, {
  value: <?= json_encode($amount) ?>,<?php if ($isTrial): ?>
  predicted_ltv: <?= json_encode($planUsd) ?>,<?php endif; ?>
  currency: <?= json_encode($currency) ?>,
  content_ids: [<?= json_encode($package) ?>],
  content_type: 'product',
  content_name: 'MomentoCrypto ' + <?= json_encode(ucfirst($package)) ?>,
  num_items: 1,
}, { eventID: <?= json_encode($orderId) ?> });
</script>
<noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=<?= htmlspecialchars($metaPixelId) ?>&ev=<?= $isTrial ? 'StartTrial' : 'Purchase' ?>&noscript=1&cd[value]=<?= $amount ?>&cd[currency]=<?= $currency ?>"/></noscript>
<?php endif; ?>

<!-- MC Analytics -->
<script>
(function(){
  const sid = sessionStorage.getItem('mc_sid') || new URLSearchParams(location.search).get('sid') || 'unknown';
  window.__mc_sid = sid;
  function send(type, data) {
    try { navigator.sendBeacon('/api/analytics/event', JSON.stringify({ sid, type, ts: Date.now(), data: data || {} })); } catch{}
  }
  send('page_view', { path: '/success', referrer: document.referrer, locale: <?= json_encode($locale) ?> });
  window.__mc_send = send;
})();
</script>
<style>
  :root { --green:#39FF14; --black:#080808; --gray-mid:#1a1a1a; --white:#f5f5f5; --white-dim:rgba(245,245,245,0.65); }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { background: var(--black); color: var(--white); font-family: 'DM Sans', sans-serif; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
  .success-card { background: #111; border: 1px solid rgba(57,255,20,0.2); max-width: 520px; width: 100%; padding: 48px 40px; text-align: center; }
  .check-icon { width: 72px; height: 72px; border-radius: 50%; background: rgba(57,255,20,0.1); border: 2px solid var(--green); display: flex; align-items: center; justify-content: center; margin: 0 auto 24px; font-size: 36px; }
  .error-icon { width: 72px; height: 72px; border-radius: 50%; background: rgba(255,60,60,0.1); border: 2px solid #ff4444; display: flex; align-items: center; justify-content: center; margin: 0 auto 24px; font-size: 36px; }
  h1 { font-family: 'Bebas Neue', sans-serif; font-size: 42px; letter-spacing: 3px; color: var(--green); margin-bottom: 8px; }
  h1.error { color: #ff4444; }
  .subtitle { color: var(--white-dim); font-size: 15px; margin-bottom: 40px; line-height: 1.6; }
  .order-meta { font-family: 'Space Mono', monospace; font-size: 10px; color: rgba(245,245,245,0.35); margin-bottom: 24px; letter-spacing: 0.5px; }
  .code-section { background: var(--gray-mid); border: 1px solid rgba(57,255,20,0.15); padding: 28px; margin-bottom: 36px; }
  .code-label { font-family: 'Space Mono', monospace; font-size: 11px; letter-spacing: 3px; text-transform: uppercase; color: var(--green); margin-bottom: 12px; }
  .code-value { font-family: 'Space Mono', monospace; font-size: 42px; font-weight: 700; letter-spacing: 8px; color: var(--white); margin-bottom: 12px; user-select: all; }
  .copy-btn { font-family: 'Space Mono', monospace; font-size: 11px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; color: var(--black); background: var(--green); border: none; padding: 10px 28px; cursor: pointer; transition: all 0.2s; }
  .copy-btn:hover { background: #4fff2a; }
  .copy-btn.copied { background: #fff; }
  .steps { text-align: left; margin-bottom: 36px; }
  .steps-title { font-family: 'Bebas Neue', sans-serif; font-size: 24px; letter-spacing: 2px; margin-bottom: 20px; text-align: center; }
  .step { display: flex; gap: 16px; margin-bottom: 20px; align-items: flex-start; }
  .step-num { width: 32px; height: 32px; min-width: 32px; border-radius: 50%; background: rgba(57,255,20,0.1); border: 1px solid rgba(57,255,20,0.3); display: flex; align-items: center; justify-content: center; font-family: 'Bebas Neue', sans-serif; font-size: 16px; color: var(--green); }
  .step-text { font-size: 14px; color: var(--white-dim); line-height: 1.6; padding-top: 4px; }
  .step-text strong { color: var(--white); }
  .step-text .green { color: var(--green); }
  .bot-btn { display: inline-flex; align-items: center; gap: 12px; font-family: 'Space Mono', monospace; font-size: 13px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; text-decoration: none; color: var(--black); background: var(--green); padding: 18px 40px; transition: all 0.25s; }
  .bot-btn:hover { background: #4fff2a; transform: translateY(-2px); box-shadow: 0 0 40px rgba(57,255,20,0.4); }
  .bot-btn svg { flex-shrink: 0; }
  .back-btn { display: inline-block; margin-top: 24px; font-family: 'Space Mono', monospace; font-size: 12px; letter-spacing: 1px; color: var(--white-dim); text-decoration: none; transition: color 0.2s; }
  .back-btn:hover { color: var(--green); }
  .bot-btn-main { margin-bottom: 14px; }
  .copy-link-btn { font-family: 'Space Mono', monospace; font-size: 11px; letter-spacing: 1.5px; text-transform: uppercase; color: var(--white-dim); background: transparent; border: 1px solid rgba(245,245,245,0.18); padding: 9px 20px; cursor: pointer; margin-bottom: 36px; transition: color 0.2s, border-color 0.2s; }
  .copy-link-btn:hover { color: var(--white); border-color: rgba(57,255,20,0.5); }
  .footer-note { font-family: 'Space Mono', monospace; font-size: 10px; color: rgba(245,245,245,0.25); margin-top: 32px; line-height: 1.6; }
</style>
</head>
<body>

<?php if ($error): ?>
<div class="success-card">
  <div class="error-icon">✕</div>
  <h1 class="error"><?= $t['title_err'] ?></h1>
  <p class="subtitle"><?= $t['subtitle_err'] ?></p>
  <a href="<?= htmlspecialchars($tgBotLink) ?>" class="bot-btn">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.894 8.221-1.97 9.28c-.145.658-.537.818-1.084.508l-3-2.21-1.447 1.394c-.16.16-.295.295-.605.295l.213-3.053 5.56-5.023c.242-.213-.054-.333-.373-.12l-6.871 4.326-2.962-.924c-.643-.204-.657-.643.136-.953l11.57-4.461c.537-.194 1.006.131.833.941z" fill="currentColor"/></svg>
    <?= $t['support'] ?>
  </a>
  <br><a href="<?= $isIN ? '/in' : '/' ?>" class="back-btn"><?= $t['back'] ?></a>
</div>

<?php else: ?>
<div class="success-card">
  <div class="check-icon">✓</div>
  <h1><?= $isTrial ? $t['title_trial'] : $t['title_ok'] ?></h1>
  <p class="subtitle"><?= $isTrial && $membershipId ? $t['subtitle_trial'] : ($membershipId ? $t['subtitle_link'] : $t['subtitle_ok']) ?></p>
  <?php if ($orderId): ?><div class="order-meta"><?= htmlspecialchars(($isTrial ? $t['trial_meta'] : $currencySymbol . ' ' . number_format($amount, 2, '.', '')) . ' · ' . $orderId) ?></div><?php endif; ?>

  <?php if ($membershipId): ?>
  <a href="<?= htmlspecialchars($tgBotLink) ?>" class="bot-btn bot-btn-main" onclick="if(window.__mc_send)window.__mc_send('bot_link_open',{package:<?= htmlspecialchars(json_encode($package)) ?>})">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.894 8.221-1.97 9.28c-.145.658-.537.818-1.084.508l-3-2.21-1.447 1.394c-.16.16-.295.295-.605.295l.213-3.053 5.56-5.023c.242-.213-.054-.333-.373-.12l-6.871 4.326-2.962-.924c-.643-.204-.657-.643.136-.953l11.57-4.461c.537-.194 1.006.131.833.941z" fill="currentColor"/></svg>
    <?= $t['open_bot'] ?>
  </a>
  <div><button class="copy-link-btn" id="copyLinkBtn" onclick="copyBotLink()"><?= $t['copy_link'] ?></button></div>

  <div class="steps steps-link">
    <div class="steps-title"><?= $t['steps_title'] ?></div>
    <div class="step"><div class="step-num">1</div><div class="step-text"><?= $t['link_step1'] ?></div></div>
    <div class="step"><div class="step-num">2</div><div class="step-text"><?= $t['link_step2_pre'] ?> <strong><?= $t['step2_strong'] ?></strong> <?= $t['link_step2_post'] ?></div></div>
    <div class="step"><div class="step-num">3</div><div class="step-text"><?= $t['link_step3_pre'] ?> <strong><?= $t['step4_strong'] ?></strong> <?= $t['link_step3_post'] ?></div></div>
  </div>

  <div class="footer-note"><?= $t['link_note'] ?></div>
  <?php else: ?>
  <div class="code-section">
    <div class="code-label"><?= $t['code_label'] ?></div>
    <div class="code-value" id="codeValue"><?= htmlspecialchars($code) ?></div>
    <button class="copy-btn" id="copyBtn" onclick="copyCode()"><?= $t['copy'] ?></button>
  </div>

  <div class="steps">
    <div class="steps-title"><?= $t['steps_title'] ?></div>
    <div class="step"><div class="step-num">1</div><div class="step-text"><?= $t['step1'] ?></div></div>
    <div class="step"><div class="step-num">2</div><div class="step-text"><?= $t['step2_pre'] ?> <strong><?= $t['step2_strong'] ?></strong> <?= $t['step2_mid'] ?> <span class="green"><?= $t['step2_cmd'] ?></span> <?= $t['step2_post'] ?></div></div>
    <div class="step"><div class="step-num">3</div><div class="step-text"><?= $t['step3'] ?></div></div>
    <div class="step"><div class="step-num">4</div><div class="step-text"><?= $t['step4_pre'] ?> <strong><?= $t['step4_strong'] ?></strong></div></div>
  </div>

  <a href="<?= htmlspecialchars($tgBotLink) ?>" class="bot-btn">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.894 8.221-1.97 9.28c-.145.658-.537.818-1.084.508l-3-2.21-1.447 1.394c-.16.16-.295.295-.605.295l.213-3.053 5.56-5.023c.242-.213-.054-.333-.373-.12l-6.871 4.326-2.962-.924c-.643-.204-.657-.643.136-.953l11.57-4.461c.537-.194 1.006.131.833.941z" fill="currentColor"/></svg>
    <?= $t['open_bot'] ?>
  </a>

  <div class="footer-note"><?= $t['footer_note'] ?></div>
  <?php endif; ?>
</div>

<script>
  if (window.__mc_send && <?= json_encode($firstView) ?>) {
    window.__mc_send('payment_complete', {
      sale: { package: <?= json_encode($package) ?>, order_id: <?= json_encode($orderId) ?>, amount: <?= json_encode($amount) ?>, currency: <?= json_encode($currency) ?>, locale: <?= json_encode($locale) ?>, trial: <?= json_encode($isTrial) ?> }
    });
  }
  // Clear lost-redirect flag — user arrived successfully
  try { localStorage.removeItem('mc_last_pay_attempt'); } catch {}

  function copyBotLink() {
    navigator.clipboard.writeText(<?= json_encode($tgBotLink) ?>).then(() => {
      if (window.__mc_send) window.__mc_send('bot_link_copied', { package: <?= json_encode($package) ?> });
      const btn = document.getElementById('copyLinkBtn');
      btn.textContent = <?= json_encode($t['copied_link']) ?>;
      setTimeout(() => { btn.textContent = <?= json_encode($t['copy_link']) ?>; }, 2000);
    });
  }

  function copyCode() {
    const code = document.getElementById('codeValue').textContent;
    navigator.clipboard.writeText(code).then(() => {
      if (window.__mc_send) window.__mc_send('activation_code_copied', { package: <?= json_encode($package) ?> });
      const btn = document.getElementById('copyBtn');
      btn.textContent = <?= json_encode($t['copied']) ?>;
      btn.classList.add('copied');
      setTimeout(() => {
        btn.textContent = <?= json_encode($t['copy']) ?>;
        btn.classList.remove('copied');
      }, 2000);
    });
  }
</script>
<?php endif; ?>

</body>
</html>
