<?php
// Sends daily/weekly/monthly sales summary to Telegram
// Run via cron:
//   0 23 * * *  php /root/MomentoCrypto/api/report.php daily
//   0 23 * * 0  php /root/MomentoCrypto/api/report.php weekly
//   0 23 1 * *  php /root/MomentoCrypto/api/report.php monthly

$period = $argv[1] ?? 'daily';
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
$token = $env['TELEGRAM_BOT_TOKEN'] ?? '';
$chatId = $env['TELEGRAM_CHAT_ID'] ?? '';
if (!$token || !$chatId) { exit("Missing TG credentials\n"); }

$sales = file_exists($salesFile) ? (json_decode(file_get_contents($salesFile), true) ?: []) : [];

// Period boundaries
switch ($period) {
    case 'weekly':
        $since = strtotime('-7 days');
        $title = '📊 *Weekly Report*';
        $label = 'Last 7 days';
        break;
    case 'monthly':
        $since = strtotime('first day of this month 00:00');
        $title = '📈 *Monthly Report*';
        $label = date('F Y');
        break;
    default: // daily
        $since = strtotime('today 00:00');
        $title = '📅 *Daily Report*';
        $label = date('Y-m-d');
}

$filtered = array_filter($sales, fn($s) => ($s['ts'] ?? 0) >= $since);

$totalRevenue = 0;
$byPackage = ['starter' => ['count' => 0, 'revenue' => 0], 'trader' => ['count' => 0, 'revenue' => 0], 'pro' => ['count' => 0, 'revenue' => 0]];
foreach ($filtered as $s) {
    $amt = floatval($s['amount'] ?? 0);
    $pkg = $s['package'] ?? 'unknown';
    $totalRevenue += $amt;
    if (isset($byPackage[$pkg])) {
        $byPackage[$pkg]['count']++;
        $byPackage[$pkg]['revenue'] += $amt;
    }
}

$totalSales = count($filtered);
$names = ['starter' => 'Starter', 'trader' => 'Trader', 'pro' => 'Pro'];

$msg = "{$title}\n_{$label}_\n\n";
$msg .= "💰 Total revenue: *\${$totalRevenue}*\n";
$msg .= "🛒 Total sales: *{$totalSales}*\n\n";

if ($totalSales > 0) {
    $msg .= "*Breakdown by plan:*\n";
    foreach ($byPackage as $pkg => $stats) {
        if ($stats['count'] > 0) {
            $msg .= "• {$names[$pkg]}: {$stats['count']} × = \${$stats['revenue']}\n";
        }
    }
} else {
    $msg .= "_No sales in this period._";
}

@file_get_contents(
    "https://api.telegram.org/bot{$token}/sendMessage?"
    . http_build_query([
        'chat_id' => $chatId,
        'text' => $msg,
        'parse_mode' => 'Markdown',
    ])
);

echo "Report sent: {$period} ({$totalSales} sales, \${$totalRevenue})\n";
