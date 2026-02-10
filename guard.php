<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

start_secure_session();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, max-age=0');

if (empty($_SESSION['authenticated'])) {
    $isApi = defined('IS_API') && IS_API;
    if ($isApi) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized. Please sign in again.']);
        exit;
    }
    header('Location: login.php');
    exit;
}
