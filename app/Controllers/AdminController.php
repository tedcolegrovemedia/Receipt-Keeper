<?php
declare(strict_types=1);

class AdminController
{
    public function index(): void
    {
        ensure_authenticated();

        $error = '';
        $success = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            [$success, $error] = $this->handlePostAction();
        }

        $checks = $this->buildChecks();
        $summary = ['pass' => 0, 'warning' => 0, 'fail' => 0];
        foreach ($checks as $check) {
            $status = (string) ($check['status'] ?? '');
            if (isset($summary[$status])) {
                $summary[$status] += 1;
            }
        }

        $usage = veryfi_usage_status();
        $runtime = [
            'Checked At' => (new DateTime('now', new DateTimeZone('America/New_York')))->format('Y-m-d H:i:s T'),
            'PHP Version' => PHP_VERSION,
            'SAPI' => PHP_SAPI,
            'Server Software' => (string) ($_SERVER['SERVER_SOFTWARE'] ?? 'unknown'),
            'Detected Base Path' => base_path() === '' ? '/' : base_path(),
            'Configured Base Path Override' => $this->configuredBasePathLabel(),
            'Configured Storage Mode' => strtoupper(storage_mode()),
            'Active Storage Driver' => strtoupper(storage_driver()),
            'Default OCR' => $this->defaultOcrProvider(),
            'Veryfi OCR Remaining' => $this->veryfiRemainingLabel(),
        ];

        $ocrRemaining = $usage['remaining'];
        $ocrLimit = (int) ($usage['limit'] ?? 0);

        render('admin', [
            'checks' => $checks,
            'summary' => $summary,
            'runtime' => $runtime,
            'error' => $error,
            'success' => $success,
            'ocrRemainingValue' => $ocrRemaining === null ? '' : (string) $ocrRemaining,
            'ocrLimit' => $ocrLimit,
            'appBasePathValue' => defined('APP_BASE_PATH') ? (string) APP_BASE_PATH : '',
            'defaultTestEmail' => get_forgot_password_email(),
        ]);
    }

    private function handlePostAction(): array
    {
        if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
            return ['', 'Session expired. Please refresh and try again.'];
        }

        $action = trim((string) ($_POST['admin_action'] ?? ''));
        if ($action === '') {
            return ['', 'Missing admin action.'];
        }

        switch ($action) {
            case 'update_ocr_remaining':
                return $this->handleUpdateOcrRemaining();
            case 'test_email':
                return $this->handleTestEmail();
            case 'export_bundle':
                $this->downloadExportBundle();
                return ['', 'Failed to start export download.'];
            case 'import_bundle':
                return $this->handleImportBundle();
            case 'update_base_path':
                return $this->handleUpdateBasePath();
            default:
                return ['', 'Unknown admin action.'];
        }
    }

    private function handleUpdateOcrRemaining(): array
    {
        $limit = (int) VERYFI_MONTHLY_LIMIT;
        if ($limit <= 0) {
            return ['', 'Veryfi monthly limit is not configured as a finite number, so remaining count cannot be set manually.'];
        }

        $raw = trim((string) ($_POST['ocr_remaining'] ?? ''));
        if ($raw === '' || preg_match('/^-?\d+$/', $raw) !== 1) {
            return ['', 'OCR remaining must be a whole number.'];
        }

        $remaining = (int) $raw;
        $status = set_veryfi_remaining($remaining);
        $date = (string) ($status['date'] ?? date('Y-m'));
        $savedRemaining = isset($status['remaining']) ? (int) $status['remaining'] : 0;
        $savedCount = isset($status['count']) ? (int) $status['count'] : 0;

        return [sprintf('Veryfi OCR remaining set to %d for %s (used: %d).', $savedRemaining, $date, $savedCount), ''];
    }

    private function handleUpdateBasePath(): array
    {
        $input = (string) ($_POST['app_base_path'] ?? '');
        $normalized = normalize_base_path_value($input);

        if (!$this->upsertLocalConfigDefine('APP_BASE_PATH', $normalized)) {
            return ['', 'Failed to update base path in config/config.local.php. Check file permissions.'];
        }

        $label = $normalized === '' ? 'auto-detect' : $normalized;
        return ["Base path saved as {$label}. Refresh the page to apply.", ''];
    }

    private function handleTestEmail(): array
    {
        $destination = strtolower(trim((string) ($_POST['test_email'] ?? '')));
        if ($destination === '') {
            $destination = get_forgot_password_email();
        }

        if ($destination === '') {
            return ['', 'No destination email provided and no recovery email is configured.'];
        }
        if (!filter_var($destination, FILTER_VALIDATE_EMAIL)) {
            return ['', 'Test email must be a valid email address.'];
        }
        if (!function_exists('mail')) {
            return ['', 'PHP mail() is not available on this server.'];
        }

        $ok = $this->sendAdminTestEmail($destination);
        if (!$ok) {
            return ['', 'Mail send failed. Check sendmail/SMTP configuration on this server.'];
        }

        return ['Test email sent to ' . $destination . '.', ''];
    }

    private function handleImportBundle(): array
    {
        if (!class_exists('ZipArchive')) {
            return ['', 'ZipArchive extension is not available on this server.'];
        }

        if (empty($_FILES['import_bundle'])) {
            return ['', 'Choose an export zip file to import.'];
        }

        $file = $_FILES['import_bundle'];
        $errorCode = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
        if ($errorCode !== UPLOAD_ERR_OK) {
            $messages = [
                UPLOAD_ERR_INI_SIZE => 'Import file exceeds server upload size limit.',
                UPLOAD_ERR_FORM_SIZE => 'Import file exceeds form upload size limit.',
                UPLOAD_ERR_PARTIAL => 'Import upload was interrupted.',
                UPLOAD_ERR_NO_TMP_DIR => 'Server is missing a temp upload folder.',
                UPLOAD_ERR_CANT_WRITE => 'Server failed to write the uploaded import file.',
                UPLOAD_ERR_EXTENSION => 'Upload blocked by a server extension.',
            ];
            return ['', $messages[$errorCode] ?? 'Import upload failed.'];
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            return ['', 'Invalid uploaded import file.'];
        }

        $replaceExisting = !empty($_POST['import_replace']);
        $result = $this->importBundle($tmpName, $replaceExisting);
        if (!$result['ok']) {
            return ['', (string) $result['error']];
        }

        $message = sprintf(
            'Import complete: %d receipt(s) restored, %d image(s) restored.',
            (int) $result['restoredReceipts'],
            (int) $result['restoredImages']
        );

        $parts = [];
        if (!empty($result['missingImages'])) {
            $parts[] = (int) $result['missingImages'] . ' receipt image(s) were missing in the zip';
        }
        if (!empty($result['skippedReceipts'])) {
            $parts[] = (int) $result['skippedReceipts'] . ' receipt record(s) were skipped';
        }
        if (!empty($result['warnings']) && is_array($result['warnings'])) {
            foreach ($result['warnings'] as $warning) {
                if (is_string($warning) && $warning !== '') {
                    $parts[] = $warning;
                }
            }
        }
        if ($parts !== []) {
            $message .= ' Notes: ' . implode('; ', $parts) . '.';
        }

        return [$message, ''];
    }

    private function importBundle(string $zipPath, bool $replaceExisting): array
    {
        $zip = new ZipArchive();
        $opened = $zip->open($zipPath);
        if ($opened !== true) {
            return ['ok' => false, 'error' => 'Could not open zip file.'];
        }

        $receiptsJson = $this->readZipEntry($zip, [
            'receipts/receipts.json',
            'receipts.json',
        ]);
        if ($receiptsJson === null) {
            $zip->close();
            return ['ok' => false, 'error' => 'Zip does not contain receipts/receipts.json.'];
        }

        $decoded = json_decode($receiptsJson, true);
        if (!is_array($decoded)) {
            $zip->close();
            return ['ok' => false, 'error' => 'Invalid receipts JSON in zip.'];
        }

        if ($replaceExisting) {
            if (!$this->deleteAllUploadedFiles()) {
                $zip->close();
                return ['ok' => false, 'error' => 'Could not clear existing uploaded files.'];
            }
            if (!delete_all_receipts()) {
                $zip->close();
                return ['ok' => false, 'error' => 'Could not clear existing receipts before import.'];
            }
        }

        $restoredReceipts = 0;
        $restoredImages = 0;
        $missingImages = 0;
        $skippedReceipts = 0;

        foreach ($decoded as $item) {
            if (!is_array($item)) {
                $skippedReceipts += 1;
                continue;
            }

            $normalized = normalize_receipt_record($item);
            if ($normalized['id'] === '') {
                $skippedReceipts += 1;
                continue;
            }
            if ($normalized['createdAt'] === '') {
                $normalized['createdAt'] = gmdate('c');
            }

            $imageFile = $this->sanitizeImportImageFile((string) ($normalized['imageFile'] ?? ''), $normalized['id']);
            $normalized['imageFile'] = $imageFile;

            if ($imageFile !== '') {
                $imageData = $this->readZipEntry($zip, [
                    'uploads/' . $imageFile,
                    'receipts/uploads/' . $imageFile,
                ]);

                if ($imageData !== null) {
                    if (file_put_contents(UPLOADS_DIR . '/' . $imageFile, $imageData, LOCK_EX) !== false) {
                        $restoredImages += 1;
                    } else {
                        $normalized['imageFile'] = '';
                        $missingImages += 1;
                    }
                } else {
                    $existingPath = UPLOADS_DIR . '/' . $imageFile;
                    if (!is_file($existingPath)) {
                        $normalized['imageFile'] = '';
                        $missingImages += 1;
                    }
                }
            }

            if (!upsert_receipt($normalized)) {
                $skippedReceipts += 1;
                continue;
            }

            $restoredReceipts += 1;
        }

        $warnings = [];
        $vendorMemoryJson = $this->readZipEntry($zip, ['meta/vendor-memory.json', 'vendor-memory.json']);
        if ($vendorMemoryJson !== null) {
            if (json_decode($vendorMemoryJson, true) !== null || trim($vendorMemoryJson) === '[]' || trim($vendorMemoryJson) === '{}') {
                if (file_put_contents(VENDOR_MEMORY_FILE, $vendorMemoryJson, LOCK_EX) === false) {
                    $warnings[] = 'Could not restore vendor memory';
                }
            }
        }

        $veryfiUsageJson = $this->readZipEntry($zip, ['meta/veryfi-usage.json', 'veryfi-usage.json']);
        if ($veryfiUsageJson !== null) {
            if (json_decode($veryfiUsageJson, true) !== null || trim($veryfiUsageJson) === '[]' || trim($veryfiUsageJson) === '{}') {
                if (file_put_contents(VERYFI_USAGE_FILE, $veryfiUsageJson, LOCK_EX) === false) {
                    $warnings[] = 'Could not restore Veryfi usage data';
                }
            }
        }

        $zip->close();

        return [
            'ok' => true,
            'restoredReceipts' => $restoredReceipts,
            'restoredImages' => $restoredImages,
            'missingImages' => $missingImages,
            'skippedReceipts' => $skippedReceipts,
            'warnings' => $warnings,
        ];
    }

    private function deleteAllUploadedFiles(): bool
    {
        if (!is_dir(UPLOADS_DIR)) {
            return true;
        }
        $entries = scandir(UPLOADS_DIR);
        if ($entries === false) {
            return false;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = UPLOADS_DIR . '/' . $entry;
            if (is_file($path) && !unlink($path)) {
                return false;
            }
        }
        return true;
    }

    private function sanitizeImportImageFile(string $filename, string $id): string
    {
        $filename = trim(str_replace('\\', '/', $filename));
        if ($filename === '') {
            return '';
        }

        $filename = basename($filename);
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '-', $filename);
        if (!is_string($filename) || $filename === '' || $filename === '.' || $filename === '..') {
            return '';
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif', 'pdf'];
        if ($ext === '' || !in_array($ext, $allowed, true)) {
            return '';
        }

        $basename = pathinfo($filename, PATHINFO_FILENAME);
        if ($basename === '') {
            $basename = $id;
        }

        return $basename . '.' . $ext;
    }

    private function readZipEntry(ZipArchive $zip, array $names): ?string
    {
        foreach ($names as $name) {
            $content = $zip->getFromName($name);
            if ($content !== false) {
                return $content;
            }
        }
        return null;
    }

    private function downloadExportBundle(): void
    {
        if (!class_exists('ZipArchive')) {
            render('admin', [
                'checks' => $this->buildChecks(),
                'summary' => ['pass' => 0, 'warning' => 0, 'fail' => 0],
                'runtime' => [],
                'error' => 'ZipArchive extension is not available on this server.',
                'success' => '',
                'ocrRemainingValue' => '',
                'ocrLimit' => (int) VERYFI_MONTHLY_LIMIT,
                'appBasePathValue' => defined('APP_BASE_PATH') ? (string) APP_BASE_PATH : '',
            ]);
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'rkexp_');
        if ($tmpFile === false) {
            throw new RuntimeException('Could not allocate temporary file for export.');
        }
        $zipPath = $tmpFile . '.zip';
        if (!@rename($tmpFile, $zipPath)) {
            $zipPath = $tmpFile;
        }

        $receipts = fetch_all_receipts();
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($zipPath);
            throw new RuntimeException('Could not create export zip.');
        }

        $manifest = [
            'format' => 'receipt-keeper-export',
            'version' => 1,
            'exportedAt' => gmdate('c'),
            'storageMode' => storage_mode(),
            'storageDriver' => storage_driver(),
            'receiptCount' => count($receipts),
        ];
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
        $zip->addFromString('receipts/receipts.json', json_encode($receipts, JSON_PRETTY_PRINT));
        $zip->addFromString('receipts/receipts.csv', $this->buildReceiptsCsv($receipts));

        if (is_file(VENDOR_MEMORY_FILE)) {
            $content = file_get_contents(VENDOR_MEMORY_FILE);
            if ($content !== false) {
                $zip->addFromString('meta/vendor-memory.json', $content);
            }
        }
        if (is_file(VERYFI_USAGE_FILE)) {
            $content = file_get_contents(VERYFI_USAGE_FILE);
            if ($content !== false) {
                $zip->addFromString('meta/veryfi-usage.json', $content);
            }
        }

        if (is_dir(UPLOADS_DIR)) {
            $entries = scandir(UPLOADS_DIR);
            if (is_array($entries)) {
                foreach ($entries as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }
                    $path = UPLOADS_DIR . '/' . $entry;
                    if (is_file($path)) {
                        $zip->addFile($path, 'uploads/' . $entry);
                    }
                }
            }
        }

        $zip->close();

        $downloadName = 'receipt-keeper-export-' . gmdate('Ymd-His') . '.zip';
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . (string) filesize($zipPath));
        header('Cache-Control: no-store, max-age=0');
        readfile($zipPath);
        @unlink($zipPath);
        exit;
    }

    private function buildReceiptsCsv(array $receipts): string
    {
        $header = ['Date', 'Vendor', 'Location', 'Category', 'Business Purpose', 'Total'];
        $rows = [];
        $sum = 0.0;

        foreach ($receipts as $receipt) {
            if (!is_array($receipt)) {
                continue;
            }
            $total = isset($receipt['total']) ? (float) $receipt['total'] : 0.0;
            $sum += $total;
            $rows[] = [
                (string) ($receipt['date'] ?? ''),
                (string) ($receipt['vendor'] ?? ''),
                (string) ($receipt['location'] ?? ''),
                (string) ($receipt['category'] ?? ''),
                (string) ($receipt['businessPurpose'] ?? ''),
                $this->formatUsd($total),
            ];
        }

        $rows[] = ['', 'TOTAL', '', '', '', $this->formatUsd($sum)];
        array_unshift($rows, $header);

        $lines = [];
        foreach ($rows as $row) {
            $cells = [];
            foreach ($row as $cell) {
                $escaped = str_replace('"', '""', (string) $cell);
                $cells[] = '"' . $escaped . '"';
            }
            $lines[] = implode(',', $cells);
        }

        return implode("\n", $lines);
    }

    private function formatUsd(float $value): string
    {
        return '$' . number_format($value, 2, '.', '');
    }

    private function sendAdminTestEmail(string $destination): bool
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $host = preg_replace('/:\d+$/', '', $host);
        $host = preg_replace('/^www\./i', '', $host);
        if (!is_string($host) || trim($host) === '') {
            $host = 'localhost.localdomain';
        }

        $subject = 'Receipt Keeper email test';
        $body = "This is a test email from Receipt Keeper admin.\n\nSent at: " . gmdate('c') . "\nHost: {$host}\n";
        $headers = [
            'From: Receipt Keeper <noreply@' . $host . '>',
            'Content-Type: text/plain; charset=UTF-8',
        ];

        return @mail($destination, $subject, $body, implode("\r\n", $headers));
    }

    private function upsertLocalConfigDefine(string $name, string $value): bool
    {
        $line = "define('" . $name . "', " . var_export($value, true) . ');';
        $path = LOCAL_CONFIG_FILE;

        if (!is_file($path)) {
            $content = "<?php\n";
            $content .= "declare(strict_types=1);\n\n";
            $content .= "// Local overrides (do not commit).\n";
            $content .= $line . "\n";
            return file_put_contents($path, $content, LOCK_EX) !== false;
        }

        $content = file_get_contents($path);
        if (!is_string($content)) {
            return false;
        }

        $pattern = '/^\s*define\(\s*[\'"]' . preg_quote($name, '/') . '[\'"]\s*,\s*.*?\);\s*$/m';
        if (preg_match($pattern, $content) === 1) {
            $updated = preg_replace($pattern, $line, $content, 1);
            if (!is_string($updated)) {
                return false;
            }
            return file_put_contents($path, $updated, LOCK_EX) !== false;
        }

        $updated = rtrim($content) . "\n" . $line . "\n";
        return file_put_contents($path, $updated, LOCK_EX) !== false;
    }

    private function buildChecks(): array
    {
        $checks = [];
        $storageMode = storage_mode();
        $storageDriver = storage_driver();

        $dataDirExists = is_dir(DATA_DIR);
        $dataDirWritable = $dataDirExists && is_writable(DATA_DIR);

        $uploadsExists = is_dir(UPLOADS_DIR);
        $uploadsWritable = $uploadsExists && is_writable(UPLOADS_DIR);

        $storageReady = ensure_storage_ready();
        $passwordSet = get_password_hash() !== '';
        $recoveryEmail = get_forgot_password_email();
        $recoveryPhone = get_forgot_password_phone();
        $recoveryReady = $recoveryEmail !== '' || $recoveryPhone !== '';
        $veryfiConfigured = $this->veryfiConfigured();

        $checks[] = $this->makeCheck(
            'Data directory exists',
            true,
            $dataDirExists,
            $dataDirExists ? DATA_DIR : 'Missing directory: ' . DATA_DIR
        );
        $checks[] = $this->makeCheck(
            'Data directory writable',
            true,
            $dataDirWritable,
            $dataDirWritable ? 'Writable.' : 'Not writable: ' . DATA_DIR
        );
        $checks[] = $this->makeCheck(
            'Uploads directory exists',
            true,
            $uploadsExists,
            $uploadsExists ? UPLOADS_DIR : 'Missing directory: ' . UPLOADS_DIR
        );
        $checks[] = $this->makeCheck(
            'Uploads directory writable',
            true,
            $uploadsWritable,
            $uploadsWritable ? 'Writable.' : 'Not writable: ' . UPLOADS_DIR
        );
        $checks[] = $this->makeCheck(
            'Storage ready',
            true,
            $storageReady,
            $storageReady ? 'Storage checks passed.' : 'Storage checks failed. Verify write permissions and DB setup.'
        );
        $checks[] = $this->makeCheck(
            'Admin password configured',
            true,
            $passwordSet,
            $passwordSet ? 'Password hash present.' : 'Password is not configured. Run installer.'
        );
        $checks[] = $this->makeCheck(
            'Recovery contact configured',
            false,
            $recoveryReady,
            $recoveryReady ? $this->recoveryLabel($recoveryEmail, $recoveryPhone) : 'No recovery email or phone configured.'
        );
        $checks[] = $this->makeCheck(
            'Storage mode resolved',
            true,
            $storageDriver !== '',
            'Configured: ' . strtoupper($storageMode) . '. Active: ' . strtoupper($storageDriver) . '.'
        );

        $jsonWritable = is_file(RECEIPTS_FILE) ? is_writable(RECEIPTS_FILE) : $dataDirWritable;
        $checks[] = $this->makeCheck(
            'JSON data writable',
            $storageDriver === 'json',
            $jsonWritable,
            $jsonWritable ? 'JSON storage is writable.' : 'Cannot write to JSON storage.'
        );

        $sqliteAvailable = sqlite_available();
        $sqliteRequired = $storageMode === 'sqlite' || $storageDriver === 'sqlite';
        $checks[] = $this->makeCheck(
            'SQLite driver available',
            $sqliteRequired,
            $sqliteAvailable,
            $sqliteAvailable ? 'PDO SQLite driver detected.' : 'PDO SQLite driver not installed.'
        );
        $sqliteProbe = $this->probeSqlite($sqliteAvailable);
        $checks[] = $this->makeCheck(
            'SQLite connection',
            $storageDriver === 'sqlite',
            $sqliteProbe['ok'],
            $sqliteProbe['detail']
        );

        $mysqlAvailable = mysql_available();
        $mysqlRequired = $storageMode === 'mysql' || $storageDriver === 'mysql';
        $checks[] = $this->makeCheck(
            'MySQL driver available',
            $mysqlRequired,
            $mysqlAvailable,
            $mysqlAvailable ? 'PDO MySQL driver detected.' : 'PDO MySQL driver not installed.'
        );
        $mysqlConfigured = mysql_configured();
        $checks[] = $this->makeCheck(
            'MySQL settings configured',
            $storageMode === 'mysql',
            $mysqlConfigured,
            $mysqlConfigured ? 'Host/database/user configured.' : 'Missing MySQL host, database, or username.'
        );
        $mysqlProbe = $this->probeMySql($mysqlAvailable, $mysqlConfigured);
        $checks[] = $this->makeCheck(
            'MySQL connection',
            $storageDriver === 'mysql',
            $mysqlProbe['ok'],
            $mysqlProbe['detail']
        );

        $zipAvailable = class_exists('ZipArchive');
        $checks[] = $this->makeCheck(
            'Zip export/import support',
            false,
            $zipAvailable,
            $zipAvailable ? 'ZipArchive is available.' : 'ZipArchive extension missing.'
        );

        $curlAvailable = function_exists('curl_init');
        $checks[] = $this->makeCheck(
            'cURL extension available',
            $veryfiConfigured || $this->twilioConfigured(),
            $curlAvailable,
            $curlAvailable ? 'cURL is available.' : 'cURL extension missing.'
        );
        $checks[] = $this->makeCheck(
            'Veryfi credentials configured',
            false,
            $veryfiConfigured,
            $veryfiConfigured ? $this->veryfiRemainingLabel() : 'Veryfi credentials not set.'
        );
        $pdfJsAvailable = $this->pdfJsAvailable();
        $checks[] = $this->makeCheck(
            'PDF.js assets found',
            false,
            $pdfJsAvailable,
            $pdfJsAvailable ? 'PDF text extraction files are present.' : 'Missing PDF.js files in public/vendor/pdfjs.'
        );
        $mailAvailable = function_exists('mail');
        $checks[] = $this->makeCheck(
            'PHP mail() available',
            false,
            $mailAvailable,
            $mailAvailable ? 'mail() function is available.' : 'mail() function unavailable.'
        );
        $twilioConfigured = $this->twilioConfigured();
        $checks[] = $this->makeCheck(
            'Twilio SMS configured',
            false,
            $twilioConfigured,
            $twilioConfigured ? 'Twilio credentials configured.' : 'Twilio credentials not configured.'
        );

        return $checks;
    }

    private function makeCheck(string $name, bool $required, bool $ok, string $detail): array
    {
        $status = 'pass';
        if (!$ok) {
            $status = $required ? 'fail' : 'warning';
        }

        return [
            'name' => $name,
            'required' => $required,
            'ok' => $ok,
            'status' => $status,
            'detail' => $detail,
        ];
    }

    private function probeSqlite(bool $available): array
    {
        if (!$available) {
            return ['ok' => false, 'detail' => 'SQLite driver not available.'];
        }

        try {
            $db = get_db();
            init_receipts_db($db);
            return ['ok' => true, 'detail' => 'SQLite connection and table checks passed.'];
        } catch (Throwable $error) {
            return ['ok' => false, 'detail' => 'SQLite error: ' . $error->getMessage()];
        }
    }

    private function probeMySql(bool $available, bool $configured): array
    {
        if (!$available) {
            return ['ok' => false, 'detail' => 'MySQL driver not available.'];
        }
        if (!$configured) {
            return ['ok' => false, 'detail' => 'MySQL credentials are not configured.'];
        }

        try {
            $db = get_mysql_db();
            init_receipts_mysql_db($db);
            return ['ok' => true, 'detail' => 'MySQL connection and table checks passed.'];
        } catch (Throwable $error) {
            return ['ok' => false, 'detail' => 'MySQL error: ' . $error->getMessage()];
        }
    }

    private function veryfiConfigured(): bool
    {
        $required = [VERYFI_CLIENT_ID, VERYFI_USERNAME, VERYFI_API_KEY, VERYFI_CLIENT_SECRET];
        foreach ($required as $value) {
            if (!is_string($value) || $value === '' || strpos($value, 'REPLACE_WITH') === 0) {
                return false;
            }
        }
        return true;
    }

    private function twilioConfigured(): bool
    {
        return TWILIO_ACCOUNT_SID !== '' && TWILIO_AUTH_TOKEN !== '' && TWILIO_FROM_NUMBER !== '';
    }

    private function pdfJsAvailable(): bool
    {
        $scriptCandidates = ['pdf.min.mjs', 'pdf.min.js'];
        $workerCandidates = ['pdf.worker.mjs', 'pdf.worker.min.mjs', 'pdf.worker.min.js'];

        foreach ($scriptCandidates as $script) {
            $scriptPath = PUBLIC_DIR . '/vendor/pdfjs/' . $script;
            if (!is_file($scriptPath) || !is_readable($scriptPath) || filesize($scriptPath) <= 0) {
                continue;
            }
            foreach ($workerCandidates as $worker) {
                $workerPath = PUBLIC_DIR . '/vendor/pdfjs/' . $worker;
                if (is_file($workerPath) && is_readable($workerPath) && filesize($workerPath) > 0) {
                    return true;
                }
            }
        }
        return false;
    }

    private function veryfiRemainingLabel(): string
    {
        if (!$this->veryfiConfigured()) {
            return 'Veryfi not configured.';
        }

        $usage = veryfi_usage_status();
        $remaining = $usage['remaining'];
        if ($remaining === null) {
            return 'Remaining: unlimited this month.';
        }

        return 'Remaining: ' . (int) $remaining . ' of ' . (int) $usage['limit'] . ' this month.';
    }

    private function configuredBasePathLabel(): string
    {
        if (!defined('APP_BASE_PATH')) {
            return 'auto-detect';
        }
        $value = normalize_base_path_value((string) APP_BASE_PATH);
        return $value === '' ? 'auto-detect' : $value;
    }

    private function defaultOcrProvider(): string
    {
        if (!OCR_DEFAULT_ENABLED) {
            return 'Manual (OCR disabled)';
        }

        if ($this->veryfiConfigured()) {
            $usage = veryfi_usage_allowed();
            if (!empty($usage['allowed'])) {
                return 'Veryfi OCR';
            }
            return 'Local OCR (Veryfi limit reached)';
        }

        return 'Local OCR';
    }

    private function recoveryLabel(string $email, string $phone): string
    {
        $parts = [];
        $email = trim($email);
        $phone = trim($phone);

        if ($email !== '') {
            $parts[] = 'Email: ' . $this->maskEmail($email);
        }
        if ($phone !== '') {
            $parts[] = 'Phone: ' . $this->maskPhone($phone);
        }

        return implode(' | ', $parts);
    }

    private function maskEmail(string $email): string
    {
        if (strpos($email, '@') === false) {
            return $email;
        }
        $parts = explode('@', $email, 2);
        $local = trim($parts[0]);
        $domain = trim($parts[1]);
        if ($local === '') {
            return '***@' . $domain;
        }
        if (strlen($local) <= 2) {
            return substr($local, 0, 1) . '***@' . $domain;
        }
        return substr($local, 0, 1) . str_repeat('*', strlen($local) - 2) . substr($local, -1) . '@' . $domain;
    }

    private function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        if (!is_string($digits) || strlen($digits) < 4) {
            return $phone;
        }
        return '***-***-' . substr($digits, -4);
    }
}
