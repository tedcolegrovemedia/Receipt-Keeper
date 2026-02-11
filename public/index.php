<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$base = base_path();
if ($base !== '' && strpos($uriPath, $base) === 0) {
    $uriPath = substr($uriPath, strlen($base));
}
$path = '/' . ltrim($uriPath, '/');
$path = rtrim($path, '/');
if ($path === '') {
    $path = '/';
}

if (needs_install() && $path !== '/install') {
    redirect_to('install');
}

switch ($path) {
    case '/':
        (new HomeController())->index();
        break;
    case '/admin':
        (new AdminController())->index();
        break;
    case '/login':
        (new AuthController())->login();
        break;
    case '/forgot-password':
        (new AuthController())->forgotPassword();
        break;
    case '/logout':
        (new AuthController())->logout();
        break;
    case '/install':
        (new InstallController())->index();
        break;
    case '/change-password':
        (new AuthController())->changePassword();
        break;
    case '/api':
        (new ApiController())->handle();
        break;
    case '/image':
        (new ImageController())->show();
        break;
    default:
        http_response_code(404);
        echo 'Not Found';
}
