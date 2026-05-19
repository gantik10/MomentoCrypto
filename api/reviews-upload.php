<?php
// Image upload for Telegram reviews — admin auth
//   POST multipart/form-data with field "image"
//   Returns { ok: true, url: "/uploads/reviews/FILENAME" }

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST only']); exit; }

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

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($auth !== 'Bearer ' . $ADMIN_PASS) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'No file uploaded', 'detail' => $_FILES['image']['error'] ?? 'missing']);
    exit;
}

$file = $_FILES['image'];
$maxBytes = 8 * 1024 * 1024;
if ($file['size'] > $maxBytes) {
    http_response_code(400);
    echo json_encode(['error' => 'File too large (max 8MB)']);
    exit;
}

$mime = mime_content_type($file['tmp_name']);
$allowed = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
];
if (!isset($allowed[$mime])) {
    http_response_code(400);
    echo json_encode(['error' => 'Unsupported image type', 'mime' => $mime]);
    exit;
}
$ext = $allowed[$mime];

$uploadDir = __DIR__ . '/../uploads/reviews';
if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }

$filename = 'review_' . date('Ymd') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$dest = $uploadDir . '/' . $filename;
if (!move_uploaded_file($file['tmp_name'], $dest)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save file']);
    exit;
}

echo json_encode([
    'ok' => true,
    'url' => '/uploads/reviews/' . $filename,
    'size' => $file['size'],
    'mime' => $mime,
]);
