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

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Public — return all posts, newest first
    usort($posts, fn($a, $b) => ($b['ts'] ?? 0) - ($a['ts'] ?? 0));
    $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 30;
    echo json_encode([
        'ok' => true,
        'posts' => array_slice($posts, 0, $limit),
        'total' => count($posts),
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
        'title' => trim($input['title'] ?? ''),
        'body' => trim($input['body'] ?? ''),
        'image' => trim($input['image'] ?? ''),
        'win_count' => isset($input['win_count']) ? (int)$input['win_count'] : null,
        'loss_count' => isset($input['loss_count']) ? (int)$input['loss_count'] : null,
        'total_pnl_pct' => isset($input['total_pnl_pct']) ? (float)$input['total_pnl_pct'] : null,
        'ts' => strtotime($date) ?: time(),
        'updated_at' => time(),
    ];

    if (!$post['title'] || !$post['body']) {
        http_response_code(400);
        echo json_encode(['error' => 'title and body are required']);
        exit;
    }

    // Upsert: remove existing with same id, then add
    $posts = array_values(array_filter($posts, fn($p) => ($p['id'] ?? '') !== $id));
    $posts[] = $post;
    file_put_contents($file, json_encode($posts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    echo json_encode(['ok' => true, 'post' => $post]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
