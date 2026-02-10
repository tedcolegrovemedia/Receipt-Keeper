<?php
declare(strict_types=1);

define('IS_API', true);
require_once __DIR__ . '/guard.php';

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('error_log', DATA_DIR . '/api-error.log');

header('Content-Type: application/json; charset=utf-8');

set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(function (): void {
    $error = error_get_last();
    if ($error !== null) {
        error_log($error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $error['message']]);
    }
});

function veryfi_configured(): bool
{
    $required = [VERYFI_CLIENT_ID, VERYFI_USERNAME, VERYFI_API_KEY, VERYFI_CLIENT_SECRET];
    foreach ($required as $value) {
        if (!is_string($value) || $value === '' || strpos($value, 'REPLACE_WITH') === 0) {
            return false;
        }
    }
    return true;
}

function veryfi_serialize_value($value): string
{
    if (is_array($value)) {
        $isList = array_keys($value) === range(0, count($value) - 1);
        if ($isList) {
            $parts = array_map('veryfi_serialize_value', $value);
            return '[' . implode(', ', $parts) . ']';
        }
        $pairs = [];
        foreach ($value as $key => $val) {
            $pairs[] = $key . ': ' . veryfi_serialize_value($val);
        }
        return '{' . implode(', ', $pairs) . '}';
    }
    return json_encode($value, JSON_UNESCAPED_SLASHES);
}

function veryfi_payload_string(array $payload, int $timestamp): string
{
    $parts = [];
    foreach ($payload as $key => $value) {
        $parts[] = $key . ':' . veryfi_serialize_value($value);
    }
    $payloadStr = 'timestamp:' . $timestamp;
    if (!empty($parts)) {
        $payloadStr .= ',' . implode(',', $parts);
    }
    return $payloadStr;
}

function veryfi_signature(array $payload, int $timestamp): string
{
    $payloadStr = veryfi_payload_string($payload, $timestamp);
    $hash = hash_hmac('sha256', $payloadStr, VERYFI_CLIENT_SECRET, true);
    return base64_encode($hash);
}

function veryfi_extract_field(array $data, array $keys, string $default = ''): string
{
    foreach ($keys as $path) {
        $parts = explode('.', $path);
        $value = $data;
        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                $value = null;
                break;
            }
            $value = $value[$part];
        }
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
        if (is_numeric($value)) {
            return (string) $value;
        }
    }
    return $default;
}

function parse_city_state(string $address): string
{
    $addr = trim(preg_replace('/\s+/', ' ', $address));
    if ($addr === '') {
        return '';
    }

    if (preg_match('/([A-Za-z][A-Za-z .\'-]+),\s*([A-Z]{2})\s*\d{5}(?:-\d{4})?/', $addr, $matches)) {
        return trim($matches[1]) . ', ' . trim($matches[2]);
    }

    $parts = array_map('trim', explode(',', $addr));
    if (count($parts) >= 2) {
        $statePart = $parts[count($parts) - 1];
        $cityPart = $parts[count($parts) - 2];
        if (preg_match('/\b([A-Z]{2})\b/', $statePart, $matches)) {
            $state = trim($matches[1]);
            if ($cityPart !== '' && $state !== '') {
                return $cityPart . ', ' . $state;
            }
        }
    }

    if (preg_match('/([A-Za-z][A-Za-z .\'-]+)\s+([A-Z]{2})\s*\d{5}(?:-\d{4})?/', $addr, $matches)) {
        return trim($matches[1]) . ', ' . trim($matches[2]);
    }

    return '';
}

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function close_curl_handle($handle): void
{
    if (PHP_VERSION_ID >= 80500) {
        return;
    }
    if (is_resource($handle)) {
        curl_close($handle);
        return;
    }
    if (class_exists('CurlHandle') && $handle instanceof CurlHandle) {
        curl_close($handle);
    }
}

try {
    if (!ensure_storage_ready()) {
        respond(['ok' => false, 'error' => 'Storage not ready. Ensure data/ is writable and SQLite is available.'], 500);
    }

    $action = $_GET['action'] ?? '';

    if ($action === 'ping') {
        $configured = veryfi_configured();
        $usage = veryfi_usage_status();
        respond([
            'ok' => true,
            'mode' => 'server',
            'veryfi' => $configured,
            'veryfiLimit' => $usage['limit'],
            'veryfiRemaining' => $usage['remaining'],
        ]);
    }

    if ($action === 'list') {
        $receipts = fetch_all_receipts();
        $normalized = array_map(function ($receipt) {
            if (!empty($receipt['id'])) {
                $receipt['imageUrl'] = build_image_url($receipt['id']);
            }
            return $receipt;
        }, $receipts);
        respond(['ok' => true, 'receipts' => $normalized]);
    }

    if ($action === 'save') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            respond(['ok' => false, 'error' => 'Invalid request method.'], 405);
        }

        $id = trim($_POST['id'] ?? '');
        if ($id === '') {
            $id = bin2hex(random_bytes(8));
        }

        $date = trim($_POST['date'] ?? '');
        $vendor = trim($_POST['vendor'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $totalRaw = trim($_POST['total'] ?? '');
        $createdAt = trim($_POST['createdAt'] ?? '') ?: gmdate('c');

        if ($date === '' || $vendor === '' || $totalRaw === '') {
            respond(['ok' => false, 'error' => 'Missing required fields.'], 422);
        }

        $total = (float) $totalRaw;

        $existing = fetch_receipt_by_id($id);
        $imageFile = $existing['imageFile'] ?? '';

        if (!empty($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                $errorMap = [
                    UPLOAD_ERR_INI_SIZE => 'Image exceeds server upload limit.',
                    UPLOAD_ERR_FORM_SIZE => 'Image exceeds form upload limit.',
                    UPLOAD_ERR_PARTIAL => 'Image upload was interrupted.',
                    UPLOAD_ERR_NO_TMP_DIR => 'Server is missing a temp folder.',
                    UPLOAD_ERR_CANT_WRITE => 'Server failed to write the upload.',
                    UPLOAD_ERR_EXTENSION => 'Image upload blocked by server extension.',
                ];
                $message = $errorMap[$_FILES['image']['error']] ?? 'Image upload failed.';
                respond(['ok' => false, 'error' => $message], 413);
            }

            $tmp = $_FILES['image']['tmp_name'];
            $mime = '';
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = finfo_file($finfo, $tmp) ?: '';
            }
        }

            $extMap = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/heic' => 'heic',
                'image/heif' => 'heif',
            ];
            $ext = $extMap[$mime] ?? '';
            if ($ext === '') {
                $nameExt = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
                $ext = preg_match('/^[a-z0-9]+$/', $nameExt) ? $nameExt : 'jpg';
            }

            $filename = $id . '.' . $ext;
            $destination = UPLOADS_DIR . '/' . $filename;
            if (!move_uploaded_file($tmp, $destination)) {
                respond(['ok' => false, 'error' => 'Failed to save image.'], 500);
            }

            if ($imageFile && $imageFile !== $filename) {
                $oldPath = UPLOADS_DIR . '/' . $imageFile;
                if (is_file($oldPath)) {
                    unlink($oldPath);
                }
            }

            $imageFile = $filename;
        } elseif (!$imageFile) {
            respond(['ok' => false, 'error' => 'Image is required.'], 422);
        }

        $record = [
            'id' => $id,
            'date' => $date,
            'vendor' => $vendor,
            'location' => $location,
            'total' => $total,
            'createdAt' => $createdAt,
            'imageFile' => $imageFile,
        ];

        if (!upsert_receipt($record)) {
            respond(['ok' => false, 'error' => 'Failed to save receipt.'], 500);
        }

        $record['imageUrl'] = build_image_url($id);
        respond(['ok' => true, 'receipt' => $record]);
    }

    if ($action === 'delete') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            respond(['ok' => false, 'error' => 'Invalid request method.'], 405);
        }

        $raw = file_get_contents('php://input');
        $data = $raw ? json_decode($raw, true) : [];
        $id = $data['id'] ?? ($_POST['id'] ?? '');
        $id = is_string($id) ? trim($id) : '';

        if ($id === '') {
            respond(['ok' => false, 'error' => 'Missing receipt id.'], 422);
        }

        $existing = fetch_receipt_by_id($id);
        if (!$existing) {
            respond(['ok' => false, 'error' => 'Receipt not found.'], 404);
        }

        $imageFile = $existing['imageFile'] ?? '';
        if (!delete_receipt_by_id($id)) {
            respond(['ok' => false, 'error' => 'Failed to delete receipt.'], 500);
        }

        if ($imageFile) {
            $path = UPLOADS_DIR . '/' . $imageFile;
            if (is_file($path)) {
                unlink($path);
            }
        }

        respond(['ok' => true]);
    }

    if ($action === 'veryfi_ocr') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            respond(['ok' => false, 'error' => 'Invalid request method.'], 405);
        }
        if (!veryfi_configured()) {
            respond(['ok' => false, 'error' => 'Veryfi is not configured.'], 400);
        }
        $usage = veryfi_usage_allowed();
        if (!$usage['allowed']) {
            $limit = $usage['limit'];
            respond([
                'ok' => false,
                'error' => sprintf('Veryfi monthly limit reached (%d). Try next month or switch to local OCR.', $limit),
                'veryfiLimit' => $limit,
                'veryfiRemaining' => $usage['remaining'],
            ], 429);
        }
        if (empty($_FILES['image'])) {
            respond(['ok' => false, 'error' => 'Image is required.'], 422);
        }

        $file = $_FILES['image'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            respond(['ok' => false, 'error' => 'Image upload failed.'], 400);
        }
        if ($file['size'] > 20 * 1024 * 1024) {
            respond(['ok' => false, 'error' => 'Image exceeds 20MB limit.'], 413);
        }

        $timestamp = (int) round(microtime(true) * 1000);
        $payload = [
            'document_type' => 'receipt',
            'parse_address' => true,
        ];
        $signature = veryfi_signature($payload, $timestamp);

        $mime = function_exists('mime_content_type') ? mime_content_type($file['tmp_name']) : 'application/octet-stream';
        $curlFile = new CURLFile($file['tmp_name'], $mime, $file['name']);

        $ch = curl_init(VERYFI_ENDPOINT);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'CLIENT-ID: ' . VERYFI_CLIENT_ID,
            'Authorization: apikey ' . VERYFI_USERNAME . ':' . VERYFI_API_KEY,
            'X-Veryfi-Request-Timestamp: ' . $timestamp,
            'X-Veryfi-Request-Signature: ' . $signature,
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            'file' => $curlFile,
            'document_type' => 'receipt',
            'parse_address' => 'true',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($response === false) {
            $message = curl_error($ch) ?: 'Veryfi request failed.';
            close_curl_handle($ch);
            respond(['ok' => false, 'error' => $message], 500);
        }
        close_curl_handle($ch);

        $data = json_decode($response, true);
        if (!is_array($data)) {
            respond(['ok' => false, 'error' => 'Invalid response from Veryfi.'], 502);
        }
        if ($httpCode >= 400) {
            $errorMessage = $data['error'] ?? $data['message'] ?? 'Veryfi returned an error.';
            respond(['ok' => false, 'error' => $errorMessage], 502);
        }

        $dateRaw = veryfi_extract_field($data, ['date', 'document_date', 'invoice_date']);
        $date = $dateRaw;
        if ($dateRaw && preg_match('/^(\\d{4}-\\d{2}-\\d{2})/', $dateRaw, $matches)) {
            $date = $matches[1];
        }

        $vendor = veryfi_extract_field($data, ['vendor.name', 'vendor', 'vendor_name', 'merchant_name', 'merchant']);
        $totalRaw = veryfi_extract_field($data, ['total', 'total_amount', 'amount']);
        $total = is_numeric($totalRaw) ? (float) $totalRaw : null;
        $text = veryfi_extract_field($data, ['ocr_text', 'text'], '');
        $location = '';
        if (!empty($data['parsed_address']) && is_array($data['parsed_address'])) {
            $parsed = $data['parsed_address'];
            $city = isset($parsed['city']) ? trim((string) $parsed['city']) : '';
            $state = isset($parsed['state']) ? trim((string) $parsed['state']) : '';
            if ($city !== '' && $state !== '') {
                $location = $city . ', ' . $state;
            } elseif ($city !== '') {
                $location = $city;
            } elseif ($state !== '') {
                $location = $state;
            }
        }
        if ($location === '') {
            $address = veryfi_extract_field($data, ['vendor.address', 'vendor_address', 'store_address', 'address']);
            if ($address !== '') {
                $location = parse_city_state($address);
            }
        }

        $usage = increment_veryfi_usage();
        respond([
            'ok' => true,
            'text' => $text,
            'suggestions' => [
                'date' => $date ?: null,
                'vendor' => $vendor ?: null,
                'location' => $location ?: null,
                'total' => $total,
            ],
            'veryfiLimit' => $usage['limit'],
            'veryfiRemaining' => $usage['remaining'],
        ]);
    }

    respond(['ok' => false, 'error' => 'Unknown action.'], 404);
} catch (Throwable $error) {
    error_log($error->getMessage() . ' in ' . $error->getFile() . ':' . $error->getLine());
    respond(['ok' => false, 'error' => $error->getMessage()], 500);
}
