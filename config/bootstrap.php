<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../app/helpers.php';

start_secure_session();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, max-age=0');
header(
    "Content-Security-Policy: default-src 'self'; "
    . "script-src 'self' https://unpkg.com blob:; "
    . "worker-src 'self' blob:; "
    . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
    . "font-src 'self' https://fonts.gstatic.com; "
    . "img-src 'self' data: blob:; "
    . "connect-src 'self' https://unpkg.com blob:; "
    . "frame-src 'self' blob:; "
    . "object-src 'none'; "
    . "base-uri 'self'; "
    . "form-action 'self'"
);

require_once __DIR__ . '/../app/Controllers/HomeController.php';
require_once __DIR__ . '/../app/Controllers/AdminController.php';
require_once __DIR__ . '/../app/Controllers/AuthController.php';
require_once __DIR__ . '/../app/Controllers/InstallController.php';
require_once __DIR__ . '/../app/Controllers/ApiController.php';
require_once __DIR__ . '/../app/Controllers/ImageController.php';
