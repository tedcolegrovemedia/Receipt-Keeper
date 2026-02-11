<?php
declare(strict_types=1);

const APP_USERNAME = 'admin';
const SESSION_NAME = 'receipts_session';
const DATA_DIR = __DIR__ . '/../data';
const PUBLIC_DIR = __DIR__ . '/../public';
const PASSWORD_FILE = DATA_DIR . '/password.json';
const LOCAL_CONFIG_FILE = __DIR__ . '/config.local.php';
const ATTEMPTS_FILE = DATA_DIR . '/attempts.json';
const RECEIPTS_FILE = DATA_DIR . '/receipts.json';
const SQLITE_DB_FILE = DATA_DIR . '/receipts.sqlite';
const UPLOADS_DIR = DATA_DIR . '/uploads';
const VENDOR_MEMORY_FILE = DATA_DIR . '/vendor-memory.json';
const MAX_LOGIN_ATTEMPTS = 5;
const LOGIN_WINDOW_SECONDS = 900;
const MIN_PASSWORD_LENGTH = 12;
const FORGOT_CODE_TTL_SECONDS = 600;
const FORGOT_CODE_RESEND_SECONDS = 45;
const FORGOT_CODE_MAX_ATTEMPTS = 5;

// Optional local secrets file (do not commit).
$localConfig = LOCAL_CONFIG_FILE;
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
if (!defined('OCR_DEFAULT_ENABLED')) {
    define('OCR_DEFAULT_ENABLED', true);
}
if (!defined('APP_BASE_PATH')) {
    define('APP_BASE_PATH', '');
}
if (!defined('STORAGE_MODE')) {
    define('STORAGE_MODE', 'auto');
}
if (!defined('MYSQL_HOST')) {
    define('MYSQL_HOST', '');
}
if (!defined('MYSQL_PORT')) {
    define('MYSQL_PORT', 3306);
}
if (!defined('MYSQL_DATABASE')) {
    define('MYSQL_DATABASE', '');
}
if (!defined('MYSQL_USERNAME')) {
    define('MYSQL_USERNAME', '');
}
if (!defined('MYSQL_PASSWORD')) {
    define('MYSQL_PASSWORD', '');
}
if (!defined('TWILIO_ACCOUNT_SID')) {
    define('TWILIO_ACCOUNT_SID', '');
}
if (!defined('TWILIO_AUTH_TOKEN')) {
    define('TWILIO_AUTH_TOKEN', '');
}
if (!defined('TWILIO_FROM_NUMBER')) {
    define('TWILIO_FROM_NUMBER', '');
}
if (!defined('MAIL_TRANSPORT')) {
    define('MAIL_TRANSPORT', 'mail');
}
if (!defined('MAIL_FROM_EMAIL')) {
    define('MAIL_FROM_EMAIL', '');
}
if (!defined('MAIL_FROM_NAME')) {
    define('MAIL_FROM_NAME', 'Receipt Keeper');
}
if (!defined('SMTP_HOST')) {
    define('SMTP_HOST', '');
}
if (!defined('SMTP_PORT')) {
    define('SMTP_PORT', 587);
}
if (!defined('SMTP_ENCRYPTION')) {
    define('SMTP_ENCRYPTION', 'tls');
}
if (!defined('SMTP_USERNAME')) {
    define('SMTP_USERNAME', '');
}
if (!defined('SMTP_PASSWORD')) {
    define('SMTP_PASSWORD', '');
}
if (!defined('SMTP_TIMEOUT')) {
    define('SMTP_TIMEOUT', 20);
}
const VERYFI_ENDPOINT = 'https://api.veryfi.com/api/v8/partner/documents';
const VERYFI_MONTHLY_LIMIT = 100;
const VERYFI_USAGE_FILE = DATA_DIR . '/veryfi-usage.json';

function request_is_https(): bool
{
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    if ($https !== '' && $https !== 'off' && $https !== '0') {
        return true;
    }

    $forwardedProto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ($forwardedProto !== '' && strpos($forwardedProto, 'https') !== false) {
        return true;
    }

    $forwardedSsl = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? ''));
    if ($forwardedSsl === 'on' || $forwardedSsl === '1') {
        return true;
    }

    $port = (int) ($_SERVER['SERVER_PORT'] ?? 0);
    return $port === 443;
}

function ensure_session_storage_path(): ?string
{
    if (!is_dir(DATA_DIR) || !is_writable(DATA_DIR)) {
        return null;
    }

    $path = DATA_DIR . '/sessions';
    if (!is_dir($path)) {
        @mkdir($path, 0700, true);
    }
    if (!is_dir($path) || !is_writable($path)) {
        return null;
    }

    return $path;
}

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $sessionPath = ensure_session_storage_path();
    if ($sessionPath !== null) {
        session_save_path($sessionPath);
    }

    $secure = request_is_https();
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (!@session_start()) {
        error_log('Session start failed. save_path=' . (string) ini_get('session.save_path'));
    }
}

function data_store_available(): bool
{
    return is_dir(DATA_DIR) && is_writable(DATA_DIR);
}

function load_password_record(): array
{
    if (!is_file(PASSWORD_FILE)) {
        return [];
    }
    $raw = file_get_contents(PASSWORD_FILE);
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function save_password_record(array $data): bool
{
    if (!data_store_available()) {
        return false;
    }
    $payload = [
        'hash' => isset($data['hash']) ? (string) $data['hash'] : '',
        'forgot_email' => isset($data['forgot_email']) ? strtolower(trim((string) $data['forgot_email'])) : '',
        'forgot_phone' => isset($data['forgot_phone']) ? trim((string) $data['forgot_phone']) : '',
    ];
    return file_put_contents(PASSWORD_FILE, json_encode($payload, JSON_PRETTY_PRINT), LOCK_EX) !== false;
}

function get_password_hash(): string
{
    $data = load_password_record();
    if (!empty($data['hash'])) {
        return (string) $data['hash'];
    }
    return '';
}

function set_password_hash(string $hash): bool
{
    $data = load_password_record();
    $data['hash'] = $hash;
    return save_password_record($data);
}

function get_forgot_password_email(): string
{
    $data = load_password_record();
    if (!empty($data['forgot_email'])) {
        return strtolower(trim((string) $data['forgot_email']));
    }
    return '';
}

function set_forgot_password_email(string $email): bool
{
    $data = load_password_record();
    $data['forgot_email'] = strtolower(trim($email));
    return save_password_record($data);
}

function get_forgot_password_phone(): string
{
    $data = load_password_record();
    if (!empty($data['forgot_phone'])) {
        return trim((string) $data['forgot_phone']);
    }
    return '';
}

function set_forgot_password_phone(string $phone): bool
{
    $data = load_password_record();
    $data['forgot_phone'] = trim($phone);
    return save_password_record($data);
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

function normalize_host_for_csrf(string $host): string
{
    $host = strtolower(trim($host));
    $host = preg_replace('/:\d+$/', '', $host);
    if (!is_string($host) || $host === '') {
        return '';
    }
    if (strpos($host, 'www.') === 0) {
        $host = substr($host, 4);
    }
    return $host;
}

function request_looks_same_origin(): bool
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
        return false;
    }

    $host = normalize_host_for_csrf((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return false;
    }

    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin !== '') {
        $originHost = normalize_host_for_csrf((string) parse_url($origin, PHP_URL_HOST));
        if ($originHost !== '' && $originHost === $host) {
            return true;
        }
    }

    $referer = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
    if ($referer !== '') {
        $refererHost = normalize_host_for_csrf((string) parse_url($referer, PHP_URL_HOST));
        if ($refererHost !== '' && $refererHost === $host) {
            return true;
        }
    }

    $fetchSite = strtolower(trim((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
    if (in_array($fetchSite, ['same-origin', 'same-site'], true)) {
        return true;
    }

    return false;
}

function verify_csrf_or_same_origin(?string $token): bool
{
    if (verify_csrf_token($token)) {
        return true;
    }
    return request_looks_same_origin();
}

function app_mail_transport(): string
{
    $transport = strtolower(trim((string) MAIL_TRANSPORT));
    if ($transport !== 'smtp') {
        return 'mail';
    }
    return 'smtp';
}

function app_mail_from_name(): string
{
    $name = trim((string) MAIL_FROM_NAME);
    if ($name === '') {
        return 'Receipt Keeper';
    }
    return $name;
}

function app_mail_from_email(): string
{
    $configured = strtolower(trim((string) MAIL_FROM_EMAIL));
    if ($configured !== '' && filter_var($configured, FILTER_VALIDATE_EMAIL)) {
        return $configured;
    }

    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $host = preg_replace('/:\d+$/', '', $host);
    $host = preg_replace('/^www\./i', '', $host);
    if (!is_string($host) || trim($host) === '') {
        $host = 'localhost.localdomain';
    }
    return 'noreply@' . $host;
}

function app_mail_smtp_encryption(): string
{
    $encryption = strtolower(trim((string) SMTP_ENCRYPTION));
    if (!in_array($encryption, ['none', 'tls', 'ssl'], true)) {
        return 'tls';
    }
    return $encryption;
}

function app_mail_smtp_settings(): array
{
    $port = (int) SMTP_PORT;
    if ($port <= 0 || $port > 65535) {
        $port = 587;
    }

    $timeout = (int) SMTP_TIMEOUT;
    if ($timeout < 5 || $timeout > 120) {
        $timeout = 20;
    }

    return [
        'host' => trim((string) SMTP_HOST),
        'port' => $port,
        'encryption' => app_mail_smtp_encryption(),
        'username' => trim((string) SMTP_USERNAME),
        'password' => (string) SMTP_PASSWORD,
        'timeout' => $timeout,
    ];
}

function app_mail_log(string $message, array $context = []): void
{
    if (!is_dir(DATA_DIR) || !is_writable(DATA_DIR)) {
        return;
    }

    $entry = [
        'time' => (new DateTime('now', new DateTimeZone('America/New_York')))->format('c'),
        'message' => $message,
        'context' => $context,
    ];
    $line = json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    @file_put_contents(DATA_DIR . '/mail-debug.log', $line, FILE_APPEND | LOCK_EX);
}

function app_mail_encode_subject(string $subject): string
{
    if (function_exists('mb_encode_mimeheader')) {
        $encoded = mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");
        if (is_string($encoded) && $encoded !== '') {
            return $encoded;
        }
    }
    return '=?UTF-8?B?' . base64_encode($subject) . '?=';
}

function app_mail_send(string $to, string $subject, string $body, ?string &$error = null): bool
{
    $to = trim($to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid destination email.';
        return false;
    }

    $transport = app_mail_transport();
    if ($transport === 'smtp') {
        $ok = app_mail_send_smtp($to, $subject, $body, $error);
    } else {
        $ok = app_mail_send_mail($to, $subject, $body, $error);
    }

    app_mail_log($ok ? 'Email sent' : 'Email failed', [
        'transport' => $transport,
        'to' => $to,
        'subject' => $subject,
        'error' => $ok ? '' : (string) $error,
    ]);

    return $ok;
}

function app_mail_send_mail(string $to, string $subject, string $body, ?string &$error = null): bool
{
    if (!function_exists('mail')) {
        $error = 'mail() is not available.';
        return false;
    }

    $fromEmail = app_mail_from_email();
    $fromName = app_mail_from_name();
    $subjectHeader = app_mail_encode_subject($subject);
    $headers = [
        'From: ' . $fromName . ' <' . $fromEmail . '>',
        'Reply-To: ' . $fromEmail,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
    ];
    $headersRaw = implode("\r\n", $headers);

    $params = '';
    if (filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        $params = '-f' . $fromEmail;
    }

    if ($params !== '') {
        $ok = @mail($to, $subjectHeader, $body, $headersRaw, $params);
    } else {
        $ok = @mail($to, $subjectHeader, $body, $headersRaw);
    }

    if (!$ok) {
        $last = error_get_last();
        $error = is_array($last) && isset($last['message']) ? (string) $last['message'] : 'mail() returned false.';
        return false;
    }

    return true;
}

function app_mail_send_smtp(string $to, string $subject, string $body, ?string &$error = null): bool
{
    if (!function_exists('stream_socket_client')) {
        $error = 'stream_socket_client() is not available.';
        return false;
    }

    $settings = app_mail_smtp_settings();
    $host = (string) $settings['host'];
    $port = (int) $settings['port'];
    $timeout = (int) $settings['timeout'];
    $encryption = (string) $settings['encryption'];
    $username = (string) $settings['username'];
    $password = (string) $settings['password'];
    $fromEmail = app_mail_from_email();
    $fromName = app_mail_from_name();

    if ($host === '') {
        $error = 'SMTP host is empty.';
        return false;
    }
    if ($port <= 0 || $port > 65535) {
        $error = 'SMTP port is invalid.';
        return false;
    }

    $remoteHost = $host;
    if ($encryption === 'ssl') {
        $remoteHost = 'ssl://' . $host;
    }

    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client(
        $remoteHost . ':' . $port,
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT
    );
    if (!$socket) {
        $error = 'SMTP connect failed: ' . $errstr . ' (' . $errno . ')';
        return false;
    }

    stream_set_timeout($socket, $timeout);
    $response = '';
    if (!app_smtp_expect($socket, [220], $response)) {
        fclose($socket);
        $error = 'SMTP greeting failed: ' . trim($response);
        return false;
    }

    $heloHost = normalize_host_for_csrf((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    if ($heloHost === '') {
        $heloHost = 'localhost';
    }

    if (!app_smtp_command($socket, 'EHLO ' . $heloHost, [250], $response)) {
        fclose($socket);
        $error = 'SMTP EHLO failed: ' . trim($response);
        return false;
    }

    if ($encryption === 'tls') {
        if (!app_smtp_command($socket, 'STARTTLS', [220], $response)) {
            fclose($socket);
            $error = 'SMTP STARTTLS failed: ' . trim($response);
            return false;
        }

        $crypto = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if ($crypto !== true) {
            fclose($socket);
            $error = 'SMTP TLS negotiation failed.';
            return false;
        }

        if (!app_smtp_command($socket, 'EHLO ' . $heloHost, [250], $response)) {
            fclose($socket);
            $error = 'SMTP EHLO after TLS failed: ' . trim($response);
            return false;
        }
    }

    if ($username !== '' || $password !== '') {
        if (!app_smtp_command($socket, 'AUTH LOGIN', [334], $response)) {
            fclose($socket);
            $error = 'SMTP AUTH LOGIN failed: ' . trim($response);
            return false;
        }
        if (!app_smtp_command($socket, base64_encode($username), [334], $response)) {
            fclose($socket);
            $error = 'SMTP username auth failed: ' . trim($response);
            return false;
        }
        if (!app_smtp_command($socket, base64_encode($password), [235], $response)) {
            fclose($socket);
            $error = 'SMTP password auth failed: ' . trim($response);
            return false;
        }
    }

    if (!app_smtp_command($socket, 'MAIL FROM:<' . $fromEmail . '>', [250], $response)) {
        fclose($socket);
        $error = 'SMTP MAIL FROM failed: ' . trim($response);
        return false;
    }
    if (!app_smtp_command($socket, 'RCPT TO:<' . $to . '>', [250, 251], $response)) {
        fclose($socket);
        $error = 'SMTP RCPT TO failed: ' . trim($response);
        return false;
    }
    if (!app_smtp_command($socket, 'DATA', [354], $response)) {
        fclose($socket);
        $error = 'SMTP DATA failed: ' . trim($response);
        return false;
    }

    $headers = [
        'Date: ' . gmdate('D, d M Y H:i:s O'),
        'From: ' . $fromName . ' <' . $fromEmail . '>',
        'To: <' . $to . '>',
        'Subject: ' . app_mail_encode_subject($subject),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];

    $messageBody = str_replace(["\r\n", "\r"], "\n", $body);
    $bodyLines = explode("\n", $messageBody);
    $safeLines = [];
    foreach ($bodyLines as $line) {
        if (strpos($line, '.') === 0) {
            $line = '.' . $line;
        }
        $safeLines[] = $line;
    }

    $payload = implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $safeLines) . "\r\n.\r\n";
    if (@fwrite($socket, $payload) === false) {
        fclose($socket);
        $error = 'SMTP write failed while sending message data.';
        return false;
    }
    if (!app_smtp_expect($socket, [250], $response)) {
        fclose($socket);
        $error = 'SMTP message rejected: ' . trim($response);
        return false;
    }

    app_smtp_command($socket, 'QUIT', [221], $response);
    fclose($socket);
    return true;
}

function app_smtp_command($socket, string $command, array $expectedCodes, ?string &$response = null): bool
{
    $line = $command . "\r\n";
    if (@fwrite($socket, $line) === false) {
        $response = 'SMTP command write failed.';
        return false;
    }
    return app_smtp_expect($socket, $expectedCodes, $response);
}

function app_smtp_expect($socket, array $expectedCodes, ?string &$response = null): bool
{
    $code = 0;
    $response = '';
    if (!app_smtp_read_response($socket, $code, $response)) {
        return false;
    }
    return in_array($code, $expectedCodes, true);
}

function app_smtp_read_response($socket, ?int &$code, ?string &$response): bool
{
    $code = null;
    $response = '';

    while (true) {
        $line = fgets($socket, 2048);
        if ($line === false) {
            $meta = stream_get_meta_data($socket);
            if (!empty($meta['timed_out'])) {
                $response = trim($response . "\nSMTP read timed out.");
            }
            return false;
        }

        $response .= $line;
        if (!preg_match('/^(\d{3})([ -])/', $line, $matches)) {
            continue;
        }

        $code = (int) $matches[1];
        if ($matches[2] === ' ') {
            return true;
        }
    }
}

function sqlite_available(): bool
{
    if (!class_exists('PDO')) {
        return false;
    }
    $drivers = PDO::getAvailableDrivers();
    return in_array('sqlite', $drivers, true);
}

function mysql_available(): bool
{
    if (!class_exists('PDO')) {
        return false;
    }
    $drivers = PDO::getAvailableDrivers();
    return in_array('mysql', $drivers, true);
}

function mysql_configured(): bool
{
    return MYSQL_HOST !== '' && MYSQL_DATABASE !== '' && MYSQL_USERNAME !== '';
}

function storage_mode(): string
{
    $mode = strtolower(trim((string) STORAGE_MODE));
    if (!in_array($mode, ['auto', 'json', 'sqlite', 'mysql'], true)) {
        return 'auto';
    }
    return $mode;
}

function get_db(): PDO
{
    static $db = null;
    if ($db instanceof PDO) {
        return $db;
    }
    if (!sqlite_available()) {
        throw new RuntimeException('SQLite is not available.');
    }
    $db = new PDO('sqlite:' . SQLITE_DB_FILE);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA journal_mode = WAL');
    return $db;
}

function get_mysql_db(): PDO
{
    static $db = null;
    if ($db instanceof PDO) {
        return $db;
    }
    if (!mysql_available()) {
        throw new RuntimeException('MySQL is not available.');
    }
    if (!mysql_configured()) {
        throw new RuntimeException('MySQL is not configured.');
    }
    $port = (int) MYSQL_PORT;
    if ($port <= 0) {
        $port = 3306;
    }
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        MYSQL_HOST,
        $port,
        MYSQL_DATABASE
    );
    $db = new PDO($dsn, MYSQL_USERNAME, MYSQL_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $db;
}

function init_receipts_db(PDO $db): void
{
    $db->exec(
        'CREATE TABLE IF NOT EXISTS receipts (
            id TEXT PRIMARY KEY,
            date TEXT,
            vendor TEXT,
            location TEXT,
            category TEXT,
            business_purpose TEXT,
            total REAL,
            created_at TEXT,
            image_file TEXT
        )'
    );
    $columns = $db->query('PRAGMA table_info(receipts)')->fetchAll(PDO::FETCH_ASSOC);
    $hasPurpose = false;
    $hasCategory = false;
    foreach ($columns as $column) {
        if (($column['name'] ?? '') === 'business_purpose') {
            $hasPurpose = true;
            break;
        }
    }
    foreach ($columns as $column) {
        if (($column['name'] ?? '') === 'category') {
            $hasCategory = true;
            break;
        }
    }
    if (!$hasCategory) {
        $db->exec('ALTER TABLE receipts ADD COLUMN category TEXT');
    }
    if (!$hasPurpose) {
        $db->exec('ALTER TABLE receipts ADD COLUMN business_purpose TEXT');
    }
    $db->exec('CREATE INDEX IF NOT EXISTS receipts_date_idx ON receipts(date)');
}

function migrate_receipts_from_json(PDO $db): void
{
    if (!is_file(RECEIPTS_FILE)) {
        return;
    }
    $raw = file_get_contents(RECEIPTS_FILE);
    if ($raw === false || trim($raw) === '') {
        return;
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || $data === []) {
        return;
    }

    $stmt = $db->prepare(
        'INSERT OR IGNORE INTO receipts (id, date, vendor, location, category, business_purpose, total, created_at, image_file)
         VALUES (:id, :date, :vendor, :location, :category, :business_purpose, :total, :created_at, :image_file)'
    );

    foreach ($data as $receipt) {
        if (!is_array($receipt)) {
            continue;
        }
        $id = isset($receipt['id']) ? trim((string) $receipt['id']) : '';
        if ($id === '') {
            continue;
        }
        $stmt->execute([
            ':id' => $id,
            ':date' => isset($receipt['date']) ? (string) $receipt['date'] : '',
            ':vendor' => isset($receipt['vendor']) ? (string) $receipt['vendor'] : '',
            ':location' => isset($receipt['location']) ? (string) $receipt['location'] : '',
            ':category' => isset($receipt['category']) ? (string) $receipt['category'] : '',
            ':business_purpose' => isset($receipt['businessPurpose']) ? (string) $receipt['businessPurpose'] : (isset($receipt['business_purpose']) ? (string) $receipt['business_purpose'] : ''),
            ':total' => isset($receipt['total']) ? (float) $receipt['total'] : 0.0,
            ':created_at' => isset($receipt['createdAt']) ? (string) $receipt['createdAt'] : gmdate('c'),
            ':image_file' => isset($receipt['imageFile']) ? (string) $receipt['imageFile'] : '',
        ]);
    }
}

function init_receipts_mysql_db(PDO $db): void
{
    $db->exec(
        'CREATE TABLE IF NOT EXISTS receipts (
            id VARCHAR(64) PRIMARY KEY,
            date VARCHAR(32) NOT NULL DEFAULT "",
            vendor VARCHAR(255) NOT NULL DEFAULT "",
            location VARCHAR(255) NOT NULL DEFAULT "",
            category VARCHAR(255) NOT NULL DEFAULT "",
            business_purpose VARCHAR(255) NOT NULL DEFAULT "",
            total DECIMAL(10,2) NOT NULL DEFAULT 0,
            created_at VARCHAR(32) NOT NULL DEFAULT "",
            image_file VARCHAR(255) NOT NULL DEFAULT ""
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function migrate_receipts_from_json_to_mysql(PDO $db): void
{
    if (!is_file(RECEIPTS_FILE)) {
        return;
    }
    $raw = file_get_contents(RECEIPTS_FILE);
    if ($raw === false || trim($raw) === '') {
        return;
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || $data === []) {
        return;
    }

    $stmt = $db->prepare(
        'INSERT INTO receipts (id, date, vendor, location, category, business_purpose, total, created_at, image_file)
         VALUES (:id, :date, :vendor, :location, :category, :business_purpose, :total, :created_at, :image_file)
         ON DUPLICATE KEY UPDATE
            date = VALUES(date),
            vendor = VALUES(vendor),
            location = VALUES(location),
            category = VALUES(category),
            business_purpose = VALUES(business_purpose),
            total = VALUES(total),
            created_at = VALUES(created_at),
            image_file = VALUES(image_file)'
    );

    foreach ($data as $receipt) {
        if (!is_array($receipt)) {
            continue;
        }
        $id = isset($receipt['id']) ? trim((string) $receipt['id']) : '';
        if ($id === '') {
            continue;
        }
        $stmt->execute([
            ':id' => $id,
            ':date' => isset($receipt['date']) ? (string) $receipt['date'] : '',
            ':vendor' => isset($receipt['vendor']) ? (string) $receipt['vendor'] : '',
            ':location' => isset($receipt['location']) ? (string) $receipt['location'] : '',
            ':category' => isset($receipt['category']) ? (string) $receipt['category'] : '',
            ':business_purpose' => isset($receipt['businessPurpose']) ? (string) $receipt['businessPurpose'] : (isset($receipt['business_purpose']) ? (string) $receipt['business_purpose'] : ''),
            ':total' => isset($receipt['total']) ? (float) $receipt['total'] : 0.0,
            ':created_at' => isset($receipt['createdAt']) ? (string) $receipt['createdAt'] : gmdate('c'),
            ':image_file' => isset($receipt['imageFile']) ? (string) $receipt['imageFile'] : '',
        ]);
    }
}

function sqlite_init_ok(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    if (!sqlite_available()) {
        $ok = false;
        return $ok;
    }
    try {
        $dbExists = is_file(SQLITE_DB_FILE);
        $db = get_db();
        init_receipts_db($db);
        if (!$dbExists) {
            migrate_receipts_from_json($db);
        }
        $ok = true;
        return $ok;
    } catch (Throwable $error) {
        $ok = false;
        return $ok;
    }
}

function mysql_init_ok(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    if (!mysql_available() || !mysql_configured()) {
        $ok = false;
        return $ok;
    }
    try {
        $db = get_mysql_db();
        init_receipts_mysql_db($db);
        migrate_receipts_from_json_to_mysql($db);
        $ok = true;
        return $ok;
    } catch (Throwable $error) {
        $ok = false;
        return $ok;
    }
}

function storage_driver(): string
{
    static $driver = null;
    if ($driver !== null) {
        return $driver;
    }

    $mode = storage_mode();
    if ($mode === 'json') {
        $driver = 'json';
        return $driver;
    }

    if ($mode === 'sqlite') {
        $driver = sqlite_init_ok() ? 'sqlite' : 'json';
        return $driver;
    }

    if ($mode === 'mysql') {
        $driver = mysql_init_ok() ? 'mysql' : 'json';
        return $driver;
    }

    if (mysql_init_ok()) {
        $driver = 'mysql';
        return $driver;
    }

    if (sqlite_init_ok()) {
        $driver = 'sqlite';
        return $driver;
    }

    $driver = 'json';
    return $driver;
}

function receipts_use_sqlite(): bool
{
    return storage_driver() === 'sqlite';
}

function receipts_use_mysql(): bool
{
    return storage_driver() === 'mysql';
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

    $mode = storage_mode();
    if ($mode === 'sqlite') {
        if (!sqlite_init_ok()) {
            return false;
        }
        return is_writable(DATA_DIR) && is_writable(UPLOADS_DIR) && is_writable(SQLITE_DB_FILE);
    }
    if ($mode === 'mysql') {
        if (!mysql_init_ok()) {
            return false;
        }
        return data_store_available() && is_writable(UPLOADS_DIR);
    }

    $driver = storage_driver();
    if ($driver === 'sqlite') {
        return is_writable(DATA_DIR) && is_writable(UPLOADS_DIR) && is_writable(SQLITE_DB_FILE);
    }
    if ($driver === 'mysql') {
        return data_store_available() && is_writable(UPLOADS_DIR);
    }

    return data_store_available() && is_writable(UPLOADS_DIR);
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

function set_veryfi_remaining(int $remaining, ?int $now = null): array
{
    $status = veryfi_usage_status($now);
    $limit = (int) ($status['limit'] ?? 0);
    if ($limit <= 0) {
        return $status;
    }

    $remaining = max(0, min($limit, $remaining));
    $count = $limit - $remaining;
    $date = (string) $status['date'];
    $usage = load_veryfi_usage();
    $usage[$date] = $count;
    save_veryfi_usage($usage);

    $status['count'] = $count;
    $status['remaining'] = $remaining;
    return $status;
}

function normalize_base_path_value(string $path): string
{
    $path = trim(str_replace('\\', '/', $path));
    if ($path === '' || $path === '/') {
        return '';
    }
    if ($path[0] !== '/') {
        $path = '/' . $path;
    }
    $path = preg_replace('#/+#', '/', $path);
    if (!is_string($path)) {
        return '';
    }
    $path = rtrim($path, '/');
    return $path === '' ? '' : $path;
}

function invalidate_runtime_config_cache(): void
{
    clearstatcache(true, LOCAL_CONFIG_FILE);
    clearstatcache(true, __FILE__);

    if (!function_exists('opcache_invalidate')) {
        return;
    }

    @opcache_invalidate(__FILE__, true);
    if (is_file(LOCAL_CONFIG_FILE)) {
        @opcache_invalidate(LOCAL_CONFIG_FILE, true);
    }
}

function map_receipt_row(array $row): array
{
    return [
        'id' => $row['id'] ?? '',
        'date' => $row['date'] ?? '',
        'vendor' => $row['vendor'] ?? '',
        'location' => $row['location'] ?? '',
        'category' => $row['category'] ?? '',
        'businessPurpose' => $row['business_purpose'] ?? '',
        'total' => isset($row['total']) ? (float) $row['total'] : 0.0,
        'createdAt' => $row['created_at'] ?? '',
        'imageFile' => $row['image_file'] ?? '',
    ];
}

function normalize_receipt_record(array $receipt): array
{
    return [
        'id' => isset($receipt['id']) ? (string) $receipt['id'] : '',
        'date' => isset($receipt['date']) ? (string) $receipt['date'] : '',
        'vendor' => isset($receipt['vendor']) ? (string) $receipt['vendor'] : '',
        'location' => isset($receipt['location']) ? (string) $receipt['location'] : '',
        'category' => isset($receipt['category']) ? (string) $receipt['category'] : '',
        'businessPurpose' => isset($receipt['businessPurpose'])
            ? (string) $receipt['businessPurpose']
            : (isset($receipt['business_purpose']) ? (string) $receipt['business_purpose'] : ''),
        'total' => isset($receipt['total']) ? (float) $receipt['total'] : 0.0,
        'createdAt' => isset($receipt['createdAt'])
            ? (string) $receipt['createdAt']
            : (isset($receipt['created_at']) ? (string) $receipt['created_at'] : ''),
        'imageFile' => isset($receipt['imageFile'])
            ? (string) $receipt['imageFile']
            : (isset($receipt['image_file']) ? (string) $receipt['image_file'] : ''),
    ];
}

function load_receipts_json(): array
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

function save_receipts_json(array $receipts): bool
{
    if (!data_store_available()) {
        return false;
    }
    return file_put_contents(RECEIPTS_FILE, json_encode($receipts, JSON_PRETTY_PRINT), LOCK_EX) !== false;
}

function fetch_all_receipts(): array
{
    if (receipts_use_mysql()) {
        $db = get_mysql_db();
        $stmt = $db->query('SELECT id, date, vendor, location, category, business_purpose, total, created_at, image_file FROM receipts ORDER BY date DESC, created_at DESC');
        $rows = $stmt ? $stmt->fetchAll() : [];
        return array_map('map_receipt_row', $rows ?: []);
    }

    if (receipts_use_sqlite()) {
        $db = get_db();
        $stmt = $db->query('SELECT id, date, vendor, location, category, business_purpose, total, created_at, image_file FROM receipts ORDER BY date DESC, created_at DESC');
        $rows = $stmt ? $stmt->fetchAll() : [];
        return array_map('map_receipt_row', $rows ?: []);
    }

    $records = [];
    foreach (load_receipts_json() as $receipt) {
        if (!is_array($receipt)) {
            continue;
        }
        $normalized = normalize_receipt_record($receipt);
        if ($normalized['id'] === '') {
            continue;
        }
        $records[] = $normalized;
    }

    usort($records, function (array $a, array $b): int {
        $aKey = $a['date'] ?: $a['createdAt'];
        $bKey = $b['date'] ?: $b['createdAt'];
        return strcmp((string) $bKey, (string) $aKey);
    });

    return $records;
}

function fetch_receipt_by_id(string $id): ?array
{
    if (receipts_use_mysql()) {
        $db = get_mysql_db();
        $stmt = $db->prepare('SELECT id, date, vendor, location, category, business_purpose, total, created_at, image_file FROM receipts WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return map_receipt_row($row);
    }

    if (receipts_use_sqlite()) {
        $db = get_db();
        $stmt = $db->prepare('SELECT id, date, vendor, location, category, business_purpose, total, created_at, image_file FROM receipts WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return map_receipt_row($row);
    }

    foreach (load_receipts_json() as $receipt) {
        if (!is_array($receipt)) {
            continue;
        }
        $normalized = normalize_receipt_record($receipt);
        if ($normalized['id'] === $id) {
            return $normalized;
        }
    }
    return null;
}

function upsert_receipt(array $record): bool
{
    if (receipts_use_mysql()) {
        $db = get_mysql_db();
        $stmt = $db->prepare(
            'INSERT INTO receipts (id, date, vendor, location, category, business_purpose, total, created_at, image_file)
             VALUES (:id, :date, :vendor, :location, :category, :business_purpose, :total, :created_at, :image_file)
             ON DUPLICATE KEY UPDATE
                date = VALUES(date),
                vendor = VALUES(vendor),
                location = VALUES(location),
                category = VALUES(category),
                business_purpose = VALUES(business_purpose),
                total = VALUES(total),
                created_at = VALUES(created_at),
                image_file = VALUES(image_file)'
        );
        return $stmt->execute([
            ':id' => $record['id'] ?? '',
            ':date' => $record['date'] ?? '',
            ':vendor' => $record['vendor'] ?? '',
            ':location' => $record['location'] ?? '',
            ':category' => $record['category'] ?? '',
            ':business_purpose' => $record['businessPurpose'] ?? '',
            ':total' => $record['total'] ?? 0,
            ':created_at' => $record['createdAt'] ?? '',
            ':image_file' => $record['imageFile'] ?? '',
        ]);
    }

    if (receipts_use_sqlite()) {
        $db = get_db();
        $stmt = $db->prepare(
            'INSERT OR REPLACE INTO receipts (id, date, vendor, location, category, business_purpose, total, created_at, image_file)
             VALUES (:id, :date, :vendor, :location, :category, :business_purpose, :total, :created_at, :image_file)'
        );
        return $stmt->execute([
            ':id' => $record['id'] ?? '',
            ':date' => $record['date'] ?? '',
            ':vendor' => $record['vendor'] ?? '',
            ':location' => $record['location'] ?? '',
            ':category' => $record['category'] ?? '',
            ':business_purpose' => $record['businessPurpose'] ?? '',
            ':total' => $record['total'] ?? 0,
            ':created_at' => $record['createdAt'] ?? '',
            ':image_file' => $record['imageFile'] ?? '',
        ]);
    }

    $records = load_receipts_json();
    $normalized = normalize_receipt_record($record);
    if ($normalized['id'] === '') {
        return false;
    }

    $updated = false;
    foreach ($records as $index => $existing) {
        if (!is_array($existing)) {
            continue;
        }
        if (($existing['id'] ?? '') === $normalized['id']) {
            $records[$index] = $normalized;
            $updated = true;
            break;
        }
    }
    if (!$updated) {
        $records[] = $normalized;
    }
    return save_receipts_json($records);
}

function delete_receipt_by_id(string $id): bool
{
    if (receipts_use_mysql()) {
        $db = get_mysql_db();
        $stmt = $db->prepare('DELETE FROM receipts WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    if (receipts_use_sqlite()) {
        $db = get_db();
        $stmt = $db->prepare('DELETE FROM receipts WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    $records = load_receipts_json();
    $filtered = [];
    $deleted = false;
    foreach ($records as $receipt) {
        if (!is_array($receipt)) {
            continue;
        }
        if (($receipt['id'] ?? '') === $id) {
            $deleted = true;
            continue;
        }
        $filtered[] = $receipt;
    }
    if (!$deleted) {
        return false;
    }
    return save_receipts_json($filtered);
}

function delete_all_receipts(): bool
{
    if (receipts_use_mysql()) {
        $db = get_mysql_db();
        return $db->exec('DELETE FROM receipts') !== false;
    }

    if (receipts_use_sqlite()) {
        $db = get_db();
        return $db->exec('DELETE FROM receipts') !== false;
    }

    return save_receipts_json([]);
}

function build_image_url(string $id): string
{
    return 'image?id=' . rawurlencode($id);
}
