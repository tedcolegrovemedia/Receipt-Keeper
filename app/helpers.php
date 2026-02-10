<?php
declare(strict_types=1);

function base_path(): string
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if ($base === '/' || $base === '.') {
        return '';
    }
    return $base;
}

function url_path(string $path = ''): string
{
    $base = base_path();
    $path = ltrim($path, '/');
    if ($path === '') {
        return $base === '' ? '/' : $base . '/';
    }
    return ($base === '' ? '' : $base) . '/' . $path;
}

function asset_path(string $path): string
{
    return url_path($path);
}

function redirect_to(string $path): void
{
    header('Location: ' . url_path($path));
    exit;
}

function render(string $view, array $data = []): void
{
    extract($data, EXTR_SKIP);
    require __DIR__ . '/Views/' . $view . '.php';
    exit;
}

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

function ensure_authenticated(bool $isApi = false): void
{
    if (!empty($_SESSION['authenticated'])) {
        return;
    }

    if ($isApi) {
        json_response(['ok' => false, 'error' => 'Unauthorized. Please sign in again.'], 401);
    }

    redirect_to('login');
}
