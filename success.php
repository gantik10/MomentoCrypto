<?php
// ===== VALIDATE ONE-TIME TOKEN =====
$tokensFile = __DIR__ . '/api/tokens.json';
$token = $_GET['t'] ?? '';
$code = null;
$error = false;

// Load .env for activation codes
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

if (!$token || strlen($token) !== 64) {
    $error = true;
} else {
    $tokens = file_exists($tokensFile) ? json_decode(file_get_contents($tokensFile), true) : [];

    if (!isset($tokens[$token])) {
        $error = true;
    } elseif ($tokens[$token]['used']) {
        $error = true;
    } else {
        $package = $tokens[$token]['package'];
        $code = $activationCodes[$package] ?? '';

        // Mark token as used
        $tokens[$token]['used'] = true;
        $tokens[$token]['used_at'] = time();
        file_put_contents($tokensFile, json_encode($tokens));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $error ? 'Link Expired' : 'Payment Successful' ?> — MomentoCrypto</title>
<meta name="robots" content="noindex, nofollow">
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Bebas+Neue&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<!-- MC Analytics (minimal tracker for success page) -->
<script>
(function(){
  const sid = sessionStorage.getItem('mc_sid') || new URLSearchParams(location.search).get('sid') || 'unknown';
  window.__mc_sid = sid;
  function send(type, data) {
    try { navigator.sendBeacon('/api/analytics/event', JSON.stringify({ sid, type, ts: Date.now(), data: data || {} })); } catch{}
  }
  send('page_view', { path: '/success', referrer: document.referrer });
  window.__mc_send = send;
})();
</script>
<style>
  :root {
    --green: #39FF14;
    --black: #080808;
    --gray-mid: #1a1a1a;
    --white: #f5f5f5;
    --white-dim: rgba(245,245,245,0.65);
  }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    background: var(--black);
    color: var(--white);
    font-family: 'DM Sans', sans-serif;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
  }
  .success-card {
    background: #111;
    border: 1px solid rgba(57,255,20,0.2);
    max-width: 520px;
    width: 100%;
    padding: 48px 40px;
    text-align: center;
  }
  .check-icon {
    width: 72px; height: 72px;
    border-radius: 50%;
    background: rgba(57,255,20,0.1);
    border: 2px solid var(--green);
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 24px;
    font-size: 36px;
  }
  .error-icon {
    width: 72px; height: 72px;
    border-radius: 50%;
    background: rgba(255,60,60,0.1);
    border: 2px solid #ff4444;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 24px;
    font-size: 36px;
  }
  h1 {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 42px;
    letter-spacing: 3px;
    color: var(--green);
    margin-bottom: 8px;
  }
  h1.error { color: #ff4444; }
  .subtitle {
    color: var(--white-dim);
    font-size: 15px;
    margin-bottom: 40px;
    line-height: 1.6;
  }
  .code-section {
    background: var(--gray-mid);
    border: 1px solid rgba(57,255,20,0.15);
    padding: 28px;
    margin-bottom: 36px;
  }
  .code-label {
    font-family: 'Space Mono', monospace;
    font-size: 11px;
    letter-spacing: 3px;
    text-transform: uppercase;
    color: var(--green);
    margin-bottom: 12px;
  }
  .code-value {
    font-family: 'Space Mono', monospace;
    font-size: 42px;
    font-weight: 700;
    letter-spacing: 8px;
    color: var(--white);
    margin-bottom: 12px;
    user-select: all;
  }
  .copy-btn {
    font-family: 'Space Mono', monospace;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 2px;
    text-transform: uppercase;
    color: var(--black);
    background: var(--green);
    border: none;
    padding: 10px 28px;
    cursor: pointer;
    transition: all 0.2s;
  }
  .copy-btn:hover { background: #4fff2a; }
  .copy-btn.copied { background: #fff; }
  .steps { text-align: left; margin-bottom: 36px; }
  .steps-title {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 24px;
    letter-spacing: 2px;
    margin-bottom: 20px;
    text-align: center;
  }
  .step { display: flex; gap: 16px; margin-bottom: 20px; align-items: flex-start; }
  .step-num {
    width: 32px; height: 32px; min-width: 32px;
    border-radius: 50%;
    background: rgba(57,255,20,0.1);
    border: 1px solid rgba(57,255,20,0.3);
    display: flex; align-items: center; justify-content: center;
    font-family: 'Bebas Neue', sans-serif;
    font-size: 16px; color: var(--green);
  }
  .step-text { font-size: 14px; color: var(--white-dim); line-height: 1.6; padding-top: 4px; }
  .step-text strong { color: var(--white); }
  .step-text .green { color: var(--green); }
  .bot-btn {
    display: inline-flex; align-items: center; gap: 12px;
    font-family: 'Space Mono', monospace;
    font-size: 13px; font-weight: 700; letter-spacing: 2px;
    text-transform: uppercase; text-decoration: none;
    color: var(--black); background: var(--green);
    padding: 18px 40px; transition: all 0.25s;
  }
  .bot-btn:hover { background: #4fff2a; transform: translateY(-2px); box-shadow: 0 0 40px rgba(57,255,20,0.4); }
  .bot-btn svg { flex-shrink: 0; }
  .back-btn {
    display: inline-block; margin-top: 24px;
    font-family: 'Space Mono', monospace;
    font-size: 12px; letter-spacing: 1px;
    color: var(--white-dim); text-decoration: none;
    transition: color 0.2s;
  }
  .back-btn:hover { color: var(--green); }
  .footer-note {
    font-family: 'Space Mono', monospace;
    font-size: 10px; color: rgba(245,245,245,0.25);
    margin-top: 32px; line-height: 1.6;
  }
</style>
</head>
<body>

<?php if ($error): ?>
<div class="success-card">
  <div class="error-icon">✕</div>
  <h1 class="error">LINK EXPIRED</h1>
  <p class="subtitle">This activation link has already been used or is invalid. Each link can only be opened once. If you believe this is a mistake, contact support.</p>
  <a href="https://t.me/momentocrypto_bot" class="bot-btn">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.894 8.221-1.97 9.28c-.145.658-.537.818-1.084.508l-3-2.21-1.447 1.394c-.16.16-.295.295-.605.295l.213-3.053 5.56-5.023c.242-.213-.054-.333-.373-.12l-6.871 4.326-2.962-.924c-.643-.204-.657-.643.136-.953l11.57-4.461c.537-.194 1.006.131.833.941z" fill="currentColor"/></svg>
    CONTACT SUPPORT
  </a>
  <br><a href="/" class="back-btn">← Back to MomentoCrypto</a>
</div>

<?php else: ?>
<div class="success-card">
  <div class="check-icon">✓</div>
  <h1>PAYMENT SUCCESSFUL</h1>
  <p class="subtitle">Your payment has been confirmed. Use the activation code below to start your subscription.</p>

  <div class="code-section">
    <div class="code-label">Your Activation Code</div>
    <div class="code-value" id="codeValue"><?= htmlspecialchars($code) ?></div>
    <button class="copy-btn" id="copyBtn" onclick="copyCode()">COPY CODE</button>
  </div>

  <div class="steps">
    <div class="steps-title">HOW TO ACTIVATE</div>
    <div class="step">
      <div class="step-num">1</div>
      <div class="step-text">Open our Telegram bot by clicking the button below</div>
    </div>
    <div class="step">
      <div class="step-num">2</div>
      <div class="step-text">Press <strong>Start</strong> or send <span class="green">/start</span> to the bot</div>
    </div>
    <div class="step">
      <div class="step-num">3</div>
      <div class="step-text">Send your activation code (shown above) to the bot</div>
    </div>
    <div class="step">
      <div class="step-num">4</div>
      <div class="step-text">Done! You'll get instant access to the <strong>private signals channel</strong></div>
    </div>
  </div>

  <a href="https://t.me/momentocrypto_bot" class="bot-btn">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.894 8.221-1.97 9.28c-.145.658-.537.818-1.084.508l-3-2.21-1.447 1.394c-.16.16-.295.295-.605.295l.213-3.053 5.56-5.023c.242-.213-.054-.333-.373-.12l-6.871 4.326-2.962-.924c-.643-.204-.657-.643.136-.953l11.57-4.461c.537-.194 1.006.131.833.941z" fill="currentColor"/></svg>
    OPEN TELEGRAM BOT
  </a>

  <div class="footer-note">This link is one-time only. Save your code now. If you have any issues, contact support via the bot.</div>
</div>

<script>
  // Fire payment_complete event
  if (window.__mc_send) {
    window.__mc_send('payment_complete', {
      sale: { package: <?= json_encode($package ?? '') ?>, order_id: <?= json_encode($tokens[$token]['order_id'] ?? '') ?>, amount: <?= json_encode(['starter'=>25,'trader'=>60,'pro'=>100][$package] ?? 0) ?> }
    });
  }

  function copyCode() {
    const code = document.getElementById('codeValue').textContent;
    navigator.clipboard.writeText(code).then(() => {
      if (window.__mc_send) window.__mc_send('activation_code_copied', { package: <?= json_encode($package ?? '') ?> });
      const btn = document.getElementById('copyBtn');
      btn.textContent = 'COPIED!';
      btn.classList.add('copied');
      setTimeout(() => {
        btn.textContent = 'COPY CODE';
        btn.classList.remove('copied');
      }, 2000);
    });
  }
</script>
<?php endif; ?>

</body>
</html>
