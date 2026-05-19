<?php
// Telegram-screenshot reviews API
//   GET  /api/reviews.php          → public, all reviews (newest first by default)
//   POST /api/reviews.php          → admin auth, create review
//   POST /api/reviews.php?delete=ID → admin auth, delete

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

$file = __DIR__ . '/reviews.json';
$reviews = file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];

function require_admin($adminPass) {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($auth !== 'Bearer ' . $adminPass) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Public — newest first by ts (sort_order DESC takes precedence if set)
    usort($reviews, function ($a, $b) {
        $oa = $a['sort_order'] ?? 0;
        $ob = $b['sort_order'] ?? 0;
        if ($oa !== $ob) return $ob - $oa;
        return ($b['ts'] ?? 0) - ($a['ts'] ?? 0);
    });
    echo json_encode([
        'ok' => true,
        'reviews' => $reviews,
        'total' => count($reviews),
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin($ADMIN_PASS);

    // Delete
    if (isset($_GET['delete'])) {
        $id = $_GET['delete'];
        $reviews = array_values(array_filter($reviews, fn($r) => ($r['id'] ?? '') !== $id));
        file_put_contents($file, json_encode($reviews, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo json_encode(['ok' => true, 'deleted' => $id]);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON body']);
        exit;
    }

    $image = trim($input['image'] ?? '');
    if (!$image) {
        http_response_code(400);
        echo json_encode(['error' => 'image (URL) is required']);
        exit;
    }

    $id = $input['id'] ?? ('rev_' . time() . '_' . bin2hex(random_bytes(3)));
    $review = [
        'id' => $id,
        'image' => $image,
        'caption' => trim($input['caption'] ?? ''),
        'sort_order' => isset($input['sort_order']) && $input['sort_order'] !== '' ? (int)$input['sort_order'] : 0,
        'ts' => time(),
    ];

    // Upsert
    $reviews = array_values(array_filter($reviews, fn($r) => ($r['id'] ?? '') !== $id));
    $reviews[] = $review;
    file_put_contents($file, json_encode($reviews, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    echo json_encode(['ok' => true, 'review' => $review]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
