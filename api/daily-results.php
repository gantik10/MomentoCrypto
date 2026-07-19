<?php
// Daily results API
//   GET  /api/daily-results.php       → public, returns posts (newest first)
//   POST /api/daily-results.php       → admin auth, creates/updates post
//   POST /api/daily-results.php?delete=ID → admin auth, deletes post

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

$envFile = __DIR__ . '/../.env';
$env = [];
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        [$key, $val] = explode('=', $line, 2);
        $env[trim($key)] = trim($val);
    }
}
$ADMIN_PASS = $env['ADMIN_PASSWORD'] ?? 'MomentoCrypto2026!';

$file = __DIR__ . '/daily_results.json';
$posts = file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];

function require_admin($adminPass) {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($auth !== 'Bearer ' . $adminPass) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

// Parse trade metrics from body text on the fly — no denormalised fields required.
// Body contains lines like "✅ BTC/USDT +42.18%" or "❌ SOL/USDT -8%"; we count wins/losses
// and sum the percent values so admins can just paste their signal recap.
function parse_body_stats(string $body): array {
    $wins = 0;
    $losses = 0;
    $sumPct = 0.0;
    $best = 0.0;
    $worst = 0.0;
    if ($body === '') {
        return ['wins' => 0, 'losses' => 0, 'sum_pct' => 0.0, 'best' => 0.0, 'worst' => 0.0];
    }
    // Split into lines, look at each independently
    $lines = preg_split('/\r?\n/', $body);
    foreach ($lines as $line) {
        $hasWin = strpos($line, '✅') !== false;
        $hasLoss = strpos($line, '❌') !== false;
        // Find any signed percent on the line: +42.18%, -8%, 63.53%
        if (preg_match('/([+\-]?\d+(?:\.\d+)?)\s*%/', $line, $m)) {
            $pct = (float)$m[1];
            if ($hasWin) {
                $wins++;
                $sumPct += $pct;
                if ($pct > $best) $best = $pct;
            } elseif ($hasLoss) {
                $losses++;
                $sumPct += $pct;  // usually negative
                if ($pct < $worst) $worst = $pct;
            }
        } else {
            // No percent on the line — just count the emoji as a trade marker
            if ($hasWin) $wins++;
            elseif ($hasLoss) $losses++;
        }
    }
    return [
        'wins' => $wins,
        'losses' => $losses,
        'sum_pct' => round($sumPct, 2),
        'best' => round($best, 2),
        'worst' => round($worst, 2),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Public — newest first
    usort($posts, fn($a, $b) => ($b['ts'] ?? 0) - ($a['ts'] ?? 0));
    $limit = isset($_GET['limit']) ? min(500, max(1, (int)$_GET['limit'])) : 200;
    $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

    // Track-record stats over ALL posts — computed by parsing body text (no denormalised win_count fields)
    $totalPosts = count($posts);
    $totalTrades = 0;
    $totalWins = 0;
    $totalLosses = 0;
    $sumPct = 0.0;
    $bestTrade = 0.0;
    foreach ($posts as $p) {
        $body = $p['body_en'] ?? $p['body'] ?? $p['body_pt'] ?? '';
        $s = parse_body_stats($body);
        $totalWins += $s['wins'];
        $totalLosses += $s['losses'];
        $totalTrades += $s['wins'] + $s['losses'];
        $sumPct += $s['sum_pct'];
        if ($s['best'] > $bestTrade) $bestTrade = $s['best'];
    }
    $winRate = ($totalWins + $totalLosses) > 0
        ? round($totalWins / ($totalWins + $totalLosses) * 100, 1)
        : null;

    // Display floor — never show fewer than MIN_DISPLAYED_DAYS in the counter
    $minDisplayed = isset($env['MIN_DISPLAYED_DAYS']) ? (int)$env['MIN_DISPLAYED_DAYS'] : 50;

    $stats = [
        'days_published' => $totalPosts,
        'displayed_days_published' => max($totalPosts, $minDisplayed),
        'min_displayed_days' => $minDisplayed,
        'total_trades' => $totalTrades,
        'total_wins' => $totalWins,
        'total_losses' => $totalLosses,
        'win_rate_pct' => $winRate,
        'cumulative_pct' => round($sumPct, 1),
        'best_trade_pct' => round($bestTrade, 1),
    ];

    echo json_encode([
        'ok' => true,
        'posts' => array_values(array_slice($posts, $offset, $limit)),
        'total' => $totalPosts,
        'offset' => $offset,
        'limit' => $limit,
        'stats' => $stats,
        'public_since' => $env['DAILY_RESULTS_PUBLIC_SINCE'] ?? '2026-05-12',
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin($ADMIN_PASS);

    // Delete
    if (isset($_GET['delete'])) {
        $id = $_GET['delete'];
        $posts = array_values(array_filter($posts, fn($p) => ($p['id'] ?? '') !== $id));
        file_put_contents($file, json_encode($posts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo json_encode(['ok' => true, 'deleted' => $id]);
        exit;
    }

    // Create or update
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON body']);
        exit;
    }

    $date = $input['date'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid date (YYYY-MM-DD required)']);
        exit;
    }

    $id = $input['id'] ?? $date;
    $post = [
        'id' => $id,
        'date' => $date,
        // English only — BR site retired. Legacy _pt fields on existing posts are still returned as fallback.
        'title_en' => trim($input['title_en'] ?? $input['title'] ?? ''),
        'body_en'  => trim($input['body_en']  ?? $input['body']  ?? ''),
        'image' => trim($input['image'] ?? ''),
        'ts' => strtotime($date) ?: time(),
        'updated_at' => time(),
    ];

    if (!$post['title_en'] || !$post['body_en']) {
        http_response_code(400);
        echo json_encode(['error' => 'title_en and body_en are required']);
        exit;
    }

    // Upsert: remove existing with same id, then add (preserving legacy _pt fields if present)
    $existing = null;
    foreach ($posts as $p) {
        if (($p['id'] ?? '') === $id) { $existing = $p; break; }
    }
    if ($existing) {
        // Preserve legacy fields so old BR-era posts still render on IN pre-landing fallback
        if (!empty($existing['title_pt'])) $post['title_pt'] = $existing['title_pt'];
        if (!empty($existing['body_pt']))  $post['body_pt']  = $existing['body_pt'];
    }
    $posts = array_values(array_filter($posts, fn($p) => ($p['id'] ?? '') !== $id));
    $posts[] = $post;
    file_put_contents($file, json_encode($posts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    echo json_encode(['ok' => true, 'post' => $post]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
