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
const VERYFI_ENDPOINT = 'https://api.veryfi.com/api/v8/partner/documents';
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
    return '';
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

function sqlite_available(): bool
{
    if (!class_exists('PDO')) {
        return false;
    }
    $drivers = PDO::getAvailableDrivers();
    return in_array('sqlite', $drivers, true);
}

function receipts_use_sqlite(): bool
{
    static $useSqlite = null;
    if ($useSqlite !== null) {
        return $useSqlite;
    }
    if (!sqlite_available()) {
        $useSqlite = false;
        return false;
    }
    try {
        $db = get_db();
        init_receipts_db($db);
        $useSqlite = true;
    } catch (Throwable $error) {
        $useSqlite = false;
    }
    return $useSqlite;
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

    if (receipts_use_sqlite()) {
        $dbExists = is_file(SQLITE_DB_FILE);
        try {
            $db = get_db();
            init_receipts_db($db);
            if (!$dbExists) {
                migrate_receipts_from_json($db);
            }
        } catch (Throwable $error) {
            return false;
        }
        return is_writable(DATA_DIR) && is_writable(UPLOADS_DIR) && is_writable(SQLITE_DB_FILE);
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

function build_image_url(string $id): string
{
    return 'image?id=' . rawurlencode($id);
}
