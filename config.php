<?php
declare(strict_types=1);

const APP_USERNAME = 'admin';
const APP_PASSWORD_HASH = '$2y$12$8wb2q8YMgXSbXub8WD/8kec9Jz2aRNokurK1hF4QGHQCosasKAS.W';
const SESSION_NAME = 'receipts_session';
const DATA_DIR = __DIR__ . '/data';
const PASSWORD_FILE = DATA_DIR . '/password.json';
const ATTEMPTS_FILE = DATA_DIR . '/attempts.json';
const RECEIPTS_FILE = DATA_DIR . '/receipts.json';
const UPLOADS_DIR = DATA_DIR . '/uploads';
const MAX_LOGIN_ATTEMPTS = 5;
const LOGIN_WINDOW_SECONDS = 900;
const MIN_PASSWORD_LENGTH = 12;

// Optional local secrets file (do not commit).
$localConfig = __DIR__ . '/config.local.php';
if (is_file($localConfig)) {
    require $localConfig;
}

if (!defined('VERYFI_CLIENT_ID')) {
    define('VERYFI_CLIENT_ID', '');
}
if (!defined('VERYFI_USERNAME')) {
    define('VERYFI_USERNAME', '');
}
if (!defined('VERYFI_API_KEY')) {
    define('VERYFI_API_KEY', '');
}
if (!defined('VERYFI_CLIENT_SECRET')) {
    define('VERYFI_CLIENT_SECRET', '');
}
const VERYFI_ENDPOINT = 'https://api.veryfi.com/api/v8/partner/documents';
const VERYFI_WEBHOOKS_ENDPOINT = 'https://api.veryfi.com/api/v8/partner/settings/webhooks';
const VERYFI_MONTHLY_LIMIT = 100;
const VERYFI_USAGE_FILE = DATA_DIR . '/veryfi-usage.json';

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function data_store_available(): bool
{
    return is_dir(DATA_DIR) && is_writable(DATA_DIR);
}

function get_password_hash(): string
{
    if (is_file(PASSWORD_FILE)) {
        $raw = file_get_contents(PASSWORD_FILE);
        if ($raw !== false) {
            $data = json_decode($raw, true);
            if (is_array($data) && !empty($data['hash'])) {
                return (string) $data['hash'];
            }
        }
    }
    return APP_PASSWORD_HASH;
}

function set_password_hash(string $hash): bool
{
    if (!data_store_available()) {
        return false;
    }
    $payload = json_encode(['hash' => $hash], JSON_PRETTY_PRINT);
    return file_put_contents(PASSWORD_FILE, $payload, LOCK_EX) !== false;
}

function get_client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function load_attempts(): array
{
    if (!is_file(ATTEMPTS_FILE)) {
        return [];
    }
    $raw = file_get_contents(ATTEMPTS_FILE);
    $data = $raw ? json_decode($raw, true) : [];
    return is_array($data) ? $data : [];
}

function save_attempts(array $data): bool
{
    if (!data_store_available()) {
        return false;
    }
    return file_put_contents(ATTEMPTS_FILE, json_encode($data), LOCK_EX) !== false;
}

function prune_attempts(array $timestamps, int $now): array
{
    $threshold = $now - LOGIN_WINDOW_SECONDS;
    return array_values(array_filter($timestamps, fn($ts) => $ts >= $threshold));
}

function rate_limit_status(string $ip): array
{
    $now = time();
    if (data_store_available()) {
        $data = load_attempts();
        $timestamps = $data[$ip] ?? [];
        $timestamps = prune_attempts($timestamps, $now);
        $data[$ip] = $timestamps;
        save_attempts($data);
    } else {
        $timestamps = $_SESSION['login_attempts'][$ip] ?? [];
        $timestamps = prune_attempts($timestamps, $now);
        $_SESSION['login_attempts'][$ip] = $timestamps;
    }

    $count = count($timestamps);
    if ($count >= MAX_LOGIN_ATTEMPTS) {
        $oldest = min($timestamps);
        $retryAfter = max(1, ($oldest + LOGIN_WINDOW_SECONDS) - $now);
        return ['blocked' => true, 'retry_after' => $retryAfter, 'count' => $count];
    }

    return ['blocked' => false, 'retry_after' => 0, 'count' => $count];
}

function register_failed_attempt(string $ip): int
{
    $now = time();
    if (data_store_available()) {
        $data = load_attempts();
        $timestamps = $data[$ip] ?? [];
        $timestamps = prune_attempts($timestamps, $now);
        $timestamps[] = $now;
        $data[$ip] = $timestamps;
        save_attempts($data);
    } else {
        $timestamps = $_SESSION['login_attempts'][$ip] ?? [];
        $timestamps = prune_attempts($timestamps, $now);
        $timestamps[] = $now;
        $_SESSION['login_attempts'][$ip] = $timestamps;
    }

    return count($timestamps);
}

function clear_failed_attempts(string $ip): void
{
    if (data_store_available()) {
        $data = load_attempts();
        unset($data[$ip]);
        save_attempts($data);
    } else {
        unset($_SESSION['login_attempts'][$ip]);
    }
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token(?string $token): bool
{
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function ensure_storage_ready(): bool
{
    if (!is_dir(DATA_DIR)) {
        return false;
    }
    if (!is_dir(UPLOADS_DIR)) {
        if (!mkdir(UPLOADS_DIR, 0755, true) && !is_dir(UPLOADS_DIR)) {
            return false;
        }
    }
    if (!is_file(RECEIPTS_FILE)) {
        if (file_put_contents(RECEIPTS_FILE, json_encode([], JSON_PRETTY_PRINT), LOCK_EX) === false) {
            return false;
        }
    }
    return is_writable(DATA_DIR) && is_writable(UPLOADS_DIR) && is_writable(RECEIPTS_FILE);
}

function load_veryfi_usage(): array
{
    if (!is_file(VERYFI_USAGE_FILE)) {
        return [];
    }
    $raw = file_get_contents(VERYFI_USAGE_FILE);
    $data = $raw ? json_decode($raw, true) : [];
    return is_array($data) ? $data : [];
}

function save_veryfi_usage(array $data): bool
{
    if (!data_store_available()) {
        return false;
    }
    return file_put_contents(VERYFI_USAGE_FILE, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX) !== false;
}

function veryfi_usage_status(?int $now = null): array
{
    $limit = max(0, (int) VERYFI_MONTHLY_LIMIT);
    $date = date('Y-m', $now ?? time());
    if (!data_store_available()) {
        $remaining = $limit > 0 ? $limit : null;
        return ['date' => $date, 'count' => 0, 'limit' => $limit, 'remaining' => $remaining];
    }

    $usage = load_veryfi_usage();
    $count = isset($usage[$date]) ? (int) $usage[$date] : 0;
    $remaining = $limit > 0 ? max(0, $limit - $count) : null;
    return ['date' => $date, 'count' => $count, 'limit' => $limit, 'remaining' => $remaining];
}

function veryfi_usage_allowed(?int $now = null): array
{
    $status = veryfi_usage_status($now);
    $allowed = $status['limit'] <= 0 || $status['count'] < $status['limit'];
    $status['allowed'] = $allowed;
    return $status;
}

function increment_veryfi_usage(?int $now = null): array
{
    $status = veryfi_usage_status($now);
    if ($status['limit'] <= 0) {
        return $status;
    }

    $date = $status['date'];
    $usage = load_veryfi_usage();
    $count = isset($usage[$date]) ? (int) $usage[$date] : 0;
    $count += 1;
    $usage[$date] = $count;
    save_veryfi_usage($usage);

    $status['count'] = $count;
    $status['remaining'] = max(0, $status['limit'] - $count);
    return $status;
}

function load_receipts(): array
{
    if (!is_file(RECEIPTS_FILE)) {
        return [];
    }
    $raw = file_get_contents(RECEIPTS_FILE);
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function save_receipts(array $receipts): bool
{
    if (!ensure_storage_ready()) {
        return false;
    }
    $payload = json_encode(array_values($receipts), JSON_PRETTY_PRINT);
    return file_put_contents(RECEIPTS_FILE, $payload, LOCK_EX) !== false;
}

function find_receipt_index(array $receipts, string $id): int
{
    foreach ($receipts as $index => $receipt) {
        if (isset($receipt['id']) && $receipt['id'] === $id) {
            return (int) $index;
        }
    }
    return -1;
}

function build_image_url(string $id): string
{
    return 'image.php?id=' . rawurlencode($id);
}
