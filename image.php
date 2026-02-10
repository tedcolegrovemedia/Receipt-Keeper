<?php
declare(strict_types=1);

define('IS_API', true);
require_once __DIR__ . '/guard.php';

$id = trim($_GET['id'] ?? '');
if ($id === '') {
    http_response_code(404);
    exit;
}

$receipt = fetch_receipt_by_id($id);
if (!$receipt) {
    http_response_code(404);
    exit;
}

$imageFile = $receipt['imageFile'] ?? '';
if ($imageFile === '') {
    http_response_code(404);
    exit;
}

$path = UPLOADS_DIR . '/' . $imageFile;
if (!is_file($path)) {
    http_response_code(404);
    exit;
}

$mime = function_exists('mime_content_type') ? mime_content_type($path) : 'application/octet-stream';
if (!$mime) {
    $mime = 'application/octet-stream';
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=3600');
readfile($path);
exit;
