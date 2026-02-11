<?php
declare(strict_types=1);

class AdminController
{
    public function index(): void
    {
        ensure_authenticated();

        $error = isset($_SESSION['admin_flash_error']) ? (string) $_SESSION['admin_flash_error'] : '';
        $success = isset($_SESSION['admin_flash_success']) ? (string) $_SESSION['admin_flash_success'] : '';
        unset($_SESSION['admin_flash_error'], $_SESSION['admin_flash_success']);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            [$success, $error] = $this->handlePostAction();
            if ($error === '' && $success !== '') {
                $_SESSION['admin_flash_success'] = $success;
                redirect_to('admin');
            }
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
            'Archive Support' => $this->archiveSupportLabel(),
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
            'appUsernameValue' => get_app_username(),
            'appBasePathValue' => defined('APP_BASE_PATH') ? (string) APP_BASE_PATH : '',
            'exportYears' => $this->extractReceiptYears(fetch_all_receipts()),
        ]);
    }

    private function handlePostAction(): array
    {
        if (!verify_csrf_or_same_origin($_POST['csrf_token'] ?? null)) {
            return ['', 'Session expired. Please refresh and try again.'];
        }

        $action = trim((string) ($_POST['admin_action'] ?? ''));
        if ($action === '') {
            return ['', 'Missing admin action.'];
        }

        switch ($action) {
            case 'update_ocr_remaining':
                return $this->handleUpdateOcrRemaining();
            case 'update_app_username':
                return $this->handleUpdateAppUsername();
            case 'export_bundle':
                $this->downloadExportBundle((string) ($_POST['export_year'] ?? 'all'));
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

    private function handleUpdateAppUsername(): array
    {
        $username = (string) ($_POST['app_username'] ?? '');
        $normalized = normalize_app_username($username);
        if ($normalized === '') {
            return ['', 'Username must be 3-32 chars using only letters, numbers, dot, dash, or underscore.'];
        }

        if (!set_app_username($normalized)) {
            return ['', 'Could not save username. Check data folder permissions.'];
        }

        return ['Username updated to ' . $normalized . '.', ''];
    }

    private function handleImportBundle(): array
    {
        if (empty($_FILES['import_bundle'])) {
            return ['', 'Choose an export archive file to import.'];
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
        $originalName = (string) ($file['name'] ?? '');
        $result = $this->importBundle($tmpName, $replaceExisting, $originalName);
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
            $parts[] = (int) $result['missingImages'] . ' receipt image(s) were missing in the archive';
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

    private function importBundle(string $archivePath, bool $replaceExisting, string $originalName = ''): array
    {
        $jsonImport = $this->tryImportJsonBundle($archivePath, $replaceExisting, $originalName);
        if (!empty($jsonImport['recognized'])) {
            return [
                'ok' => !empty($jsonImport['ok']),
                'error' => (string) ($jsonImport['error'] ?? ''),
                'restoredReceipts' => (int) ($jsonImport['restoredReceipts'] ?? 0),
                'restoredImages' => (int) ($jsonImport['restoredImages'] ?? 0),
                'missingImages' => (int) ($jsonImport['missingImages'] ?? 0),
                'skippedReceipts' => (int) ($jsonImport['skippedReceipts'] ?? 0),
                'warnings' => (array) ($jsonImport['warnings'] ?? []),
            ];
        }

        $archive = $this->openArchiveForImport($archivePath, $originalName);
        if (!$archive['ok']) {
            return ['ok' => false, 'error' => (string) $archive['error']];
        }

        $receiptsJson = $this->readArchiveEntry($archive, [
            'receipts/receipts.json',
            'receipts.json',
        ]);
        if ($receiptsJson === null) {
            $this->closeArchive($archive);
            return ['ok' => false, 'error' => 'Archive does not contain receipts/receipts.json.'];
        }

        $decoded = json_decode($receiptsJson, true);
        if (!is_array($decoded)) {
            $this->closeArchive($archive);
            return ['ok' => false, 'error' => 'Invalid receipts JSON in archive.'];
        }

        if ($replaceExisting) {
            if (!$this->deleteAllUploadedFiles()) {
                $this->closeArchive($archive);
                return ['ok' => false, 'error' => 'Could not clear existing uploaded files.'];
            }
            if (!delete_all_receipts()) {
                $this->closeArchive($archive);
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
                $imageData = $this->readArchiveEntry($archive, [
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
        $vendorMemoryJson = $this->readArchiveEntry($archive, ['meta/vendor-memory.json', 'vendor-memory.json']);
        if ($vendorMemoryJson !== null) {
            if (json_decode($vendorMemoryJson, true) !== null || trim($vendorMemoryJson) === '[]' || trim($vendorMemoryJson) === '{}') {
                if (file_put_contents(VENDOR_MEMORY_FILE, $vendorMemoryJson, LOCK_EX) === false) {
                    $warnings[] = 'Could not restore vendor memory';
                }
            }
        }

        $veryfiUsageJson = $this->readArchiveEntry($archive, ['meta/veryfi-usage.json', 'veryfi-usage.json']);
        if ($veryfiUsageJson !== null) {
            if (json_decode($veryfiUsageJson, true) !== null || trim($veryfiUsageJson) === '[]' || trim($veryfiUsageJson) === '{}') {
                if (file_put_contents(VERYFI_USAGE_FILE, $veryfiUsageJson, LOCK_EX) === false) {
                    $warnings[] = 'Could not restore Veryfi usage data';
                }
            }
        }

        $this->closeArchive($archive);

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

    private function openArchiveForImport(string $path, string $originalName = ''): array
    {
        $name = strtolower(trim($originalName));
        if ($name === '') {
            $name = strtolower(basename($path));
        }
        $preferTar = preg_match('/\.(tar\.gz|tgz|tar)$/', $name) === 1;

        if (!$preferTar && class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            $opened = $zip->open($path);
            if ($opened === true) {
                return ['ok' => true, 'type' => 'zip', 'archive' => $zip];
            }
        }

        if (class_exists('PharData')) {
            try {
                $phar = new PharData($path);
                return ['ok' => true, 'type' => 'tar', 'archive' => $phar];
            } catch (Throwable $error) {
                if ($preferTar) {
                    return ['ok' => false, 'error' => 'Could not open tar archive: ' . $error->getMessage()];
                }
            }
        }

        if ($preferTar || !class_exists('ZipArchive')) {
            $missing = class_exists('PharData') ? 'Archive failed to open.' : 'ZipArchive and PharData are not available.';
            return ['ok' => false, 'error' => $missing];
        }

        return ['ok' => false, 'error' => 'Could not open zip archive.'];
    }

    private function readArchiveEntry(array $archive, array $names): ?string
    {
        $type = (string) ($archive['type'] ?? '');
        $handle = $archive['archive'] ?? null;

        foreach ($names as $name) {
            $entry = ltrim(str_replace('\\', '/', (string) $name), '/');
            if ($entry === '') {
                continue;
            }

            if ($type === 'zip' && $handle instanceof ZipArchive) {
                $content = $handle->getFromName($entry);
                if ($content !== false) {
                    return $content;
                }
                continue;
            }

            if ($type === 'tar' && $handle instanceof PharData) {
                if (!isset($handle[$entry])) {
                    continue;
                }
                $file = $handle[$entry];
                if ($file instanceof PharFileInfo) {
                    $content = $file->getContent();
                    if (is_string($content)) {
                        return $content;
                    }
                }
            }
        }
        return null;
    }

    private function closeArchive(array $archive): void
    {
        if (($archive['type'] ?? '') === 'zip' && ($archive['archive'] ?? null) instanceof ZipArchive) {
            $archive['archive']->close();
        }
    }

    private function tryImportJsonBundle(string $path, bool $replaceExisting, string $originalName = ''): array
    {
        $name = strtolower(trim($originalName));
        if ($name === '') {
            $name = strtolower(basename($path));
        }
        if (preg_match('/\.json$/', $name) !== 1) {
            return ['recognized' => false];
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return ['recognized' => true, 'ok' => false, 'error' => 'JSON export bundle is empty.'];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['recognized' => true, 'ok' => false, 'error' => 'Invalid JSON export bundle file.'];
        }

        $format = (string) ($decoded['format'] ?? '');
        if ($format !== 'receipt-keeper-export-json') {
            return ['recognized' => false];
        }

        $receipts = $decoded['receipts'] ?? null;
        if (!is_array($receipts)) {
            return ['recognized' => true, 'ok' => false, 'error' => 'JSON export bundle is missing receipts data.'];
        }

        if ($replaceExisting) {
            if (!$this->deleteAllUploadedFiles()) {
                return ['recognized' => true, 'ok' => false, 'error' => 'Could not clear existing uploaded files.'];
            }
            if (!delete_all_receipts()) {
                return ['recognized' => true, 'ok' => false, 'error' => 'Could not clear existing receipts before import.'];
            }
        }

        $uploads = [];
        if (isset($decoded['uploads']) && is_array($decoded['uploads'])) {
            $uploads = $decoded['uploads'];
        }

        $restoredReceipts = 0;
        $restoredImages = 0;
        $missingImages = 0;
        $skippedReceipts = 0;

        foreach ($receipts as $item) {
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
                $entry = $uploads[$imageFile] ?? null;
                $binary = null;
                if (is_string($entry)) {
                    $binary = base64_decode($entry, true);
                } elseif (is_array($entry)) {
                    $encoding = strtolower(trim((string) ($entry['encoding'] ?? 'base64')));
                    $data = (string) ($entry['data'] ?? '');
                    if ($encoding === 'base64' && $data !== '') {
                        $binary = base64_decode($data, true);
                    }
                }

                if (is_string($binary)) {
                    if (file_put_contents(UPLOADS_DIR . '/' . $imageFile, $binary, LOCK_EX) !== false) {
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
        if (isset($decoded['vendorMemory']) && is_array($decoded['vendorMemory'])) {
            $vendorPayload = json_encode($decoded['vendorMemory'], JSON_PRETTY_PRINT);
            if (is_string($vendorPayload) && file_put_contents(VENDOR_MEMORY_FILE, $vendorPayload, LOCK_EX) === false) {
                $warnings[] = 'Could not restore vendor memory';
            }
        }
        if (isset($decoded['veryfiUsage']) && is_array($decoded['veryfiUsage'])) {
            $usagePayload = json_encode($decoded['veryfiUsage'], JSON_PRETTY_PRINT);
            if (is_string($usagePayload) && file_put_contents(VERYFI_USAGE_FILE, $usagePayload, LOCK_EX) === false) {
                $warnings[] = 'Could not restore Veryfi usage data';
            }
        }

        return [
            'recognized' => true,
            'ok' => true,
            'restoredReceipts' => $restoredReceipts,
            'restoredImages' => $restoredImages,
            'missingImages' => $missingImages,
            'skippedReceipts' => $skippedReceipts,
            'warnings' => $warnings,
        ];
    }

    private function downloadExportBundle(string $requestedYear = 'all'): void
    {
        $allReceipts = fetch_all_receipts();
        $scope = $this->normalizeExportYear($requestedYear);
        $receipts = $scope === 'all'
            ? $allReceipts
            : array_values(array_filter($allReceipts, function ($receipt) use ($scope) {
                if (!is_array($receipt)) {
                    return false;
                }
                return $this->extractReceiptYear($receipt) === $scope;
            }));

        $manifest = [
            'format' => 'receipt-keeper-export',
            'version' => 1,
            'exportedAt' => gmdate('c'),
            'storageMode' => storage_mode(),
            'storageDriver' => storage_driver(),
            'receiptCount' => count($receipts),
            'scopeYear' => $scope,
        ];

        $exportFiles = [];
        foreach ($receipts as $receipt) {
            if (!is_array($receipt)) {
                continue;
            }
            $imageFile = trim((string) ($receipt['imageFile'] ?? ''));
            if ($imageFile === '') {
                continue;
            }
            $safeFile = basename($imageFile);
            if ($safeFile === '' || $safeFile === '.' || $safeFile === '..') {
                continue;
            }
            $exportFiles[$safeFile] = true;
        }

        $suffix = $scope === 'all' ? 'all' : $scope;

        if (class_exists('ZipArchive')) {
            $tmpFile = tempnam(sys_get_temp_dir(), 'rkexp_');
            if ($tmpFile === false) {
                throw new RuntimeException('Could not allocate temporary file for export.');
            }
            $zipPath = $tmpFile . '.zip';
            if (!@rename($tmpFile, $zipPath)) {
                $zipPath = $tmpFile;
            }

            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                @unlink($zipPath);
                throw new RuntimeException('Could not create export zip.');
            }

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
            foreach (array_keys($exportFiles) as $file) {
                $path = UPLOADS_DIR . '/' . $file;
                if (is_file($path)) {
                    $zip->addFile($path, 'uploads/' . $file);
                }
            }

            $zip->close();

            $downloadName = 'receipt-keeper-export-' . $suffix . '-' . gmdate('Ymd-His') . '.zip';
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $downloadName . '"');
            header('Content-Length: ' . (string) filesize($zipPath));
            header('Cache-Control: no-store, max-age=0');
            readfile($zipPath);
            @unlink($zipPath);
            exit;
        }

        if (class_exists('PharData')) {
            $tmpFile = tempnam(sys_get_temp_dir(), 'rkexp_');
            if ($tmpFile === false) {
                throw new RuntimeException('Could not allocate temporary file for export.');
            }
            $tarPath = $tmpFile . '.tar';
            if (!@rename($tmpFile, $tarPath)) {
                $tarPath = $tmpFile;
            }

            try {
                $tar = new PharData($tarPath);
                $tar->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
                $tar->addFromString('receipts/receipts.json', json_encode($receipts, JSON_PRETTY_PRINT));
                $tar->addFromString('receipts/receipts.csv', $this->buildReceiptsCsv($receipts));
                if (is_file(VENDOR_MEMORY_FILE)) {
                    $content = file_get_contents(VENDOR_MEMORY_FILE);
                    if ($content !== false) {
                        $tar->addFromString('meta/vendor-memory.json', $content);
                    }
                }
                if (is_file(VERYFI_USAGE_FILE)) {
                    $content = file_get_contents(VERYFI_USAGE_FILE);
                    if ($content !== false) {
                        $tar->addFromString('meta/veryfi-usage.json', $content);
                    }
                }
                foreach (array_keys($exportFiles) as $file) {
                    $path = UPLOADS_DIR . '/' . $file;
                    if (is_file($path)) {
                        $tar->addFile($path, 'uploads/' . $file);
                    }
                }
                $tarGz = $tar->compress(Phar::GZ);
                $gzPath = $tarGz->getPath();
                unset($tar, $tarGz);
                @unlink($tarPath);
            } catch (Throwable $error) {
                @unlink($tarPath);
                throw new RuntimeException('Could not create export archive: ' . $error->getMessage());
            }

            $downloadName = 'receipt-keeper-export-' . $suffix . '-' . gmdate('Ymd-His') . '.tar.gz';
            header('Content-Type: application/gzip');
            header('Content-Disposition: attachment; filename="' . $downloadName . '"');
            header('Content-Length: ' . (string) filesize($gzPath));
            header('Cache-Control: no-store, max-age=0');
            readfile($gzPath);
            @unlink($gzPath);
            exit;
        }

        $this->downloadJsonBundle($manifest, $receipts, array_keys($exportFiles), $suffix);
    }

    private function downloadJsonBundle(array $manifest, array $receipts, array $files, string $suffix): void
    {
        $bundle = [
            'format' => 'receipt-keeper-export-json',
            'version' => 1,
            'manifest' => $manifest,
            'receipts' => $receipts,
            'uploads' => [],
        ];

        if (is_file(VENDOR_MEMORY_FILE)) {
            $raw = file_get_contents(VENDOR_MEMORY_FILE);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $bundle['vendorMemory'] = $decoded;
                }
            }
        }
        if (is_file(VERYFI_USAGE_FILE)) {
            $raw = file_get_contents(VERYFI_USAGE_FILE);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $bundle['veryfiUsage'] = $decoded;
                }
            }
        }

        foreach ($files as $file) {
            $safe = basename((string) $file);
            if ($safe === '' || $safe === '.' || $safe === '..') {
                continue;
            }
            $path = UPLOADS_DIR . '/' . $safe;
            if (!is_file($path)) {
                continue;
            }
            $binary = file_get_contents($path);
            if ($binary === false) {
                continue;
            }
            $mime = function_exists('mime_content_type') ? (string) mime_content_type($path) : '';
            if ($mime === '') {
                $mime = 'application/octet-stream';
            }
            $bundle['uploads'][$safe] = [
                'encoding' => 'base64',
                'mime' => $mime,
                'data' => base64_encode($binary),
            ];
        }

        $payload = json_encode($bundle, JSON_PRETTY_PRINT);
        if (!is_string($payload)) {
            throw new RuntimeException('Could not encode JSON export bundle.');
        }

        $downloadName = 'receipt-keeper-export-' . $suffix . '-' . gmdate('Ymd-His') . '.json';
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . (string) strlen($payload));
        header('Cache-Control: no-store, max-age=0');
        echo $payload;
        exit;
    }

    private function buildReceiptsCsv(array $receipts): string
    {
        $header = ['Date', 'Vendor', 'Category', 'Business Purpose', 'Total'];
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
                (string) ($receipt['category'] ?? ''),
                (string) ($receipt['businessPurpose'] ?? ''),
                $this->formatUsd($total),
            ];
        }

        $rows[] = ['', 'TOTAL', '', '', $this->formatUsd($sum)];
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

    private function normalizeExportYear(string $value): string
    {
        $trimmed = trim($value);
        if (preg_match('/^\d{4}$/', $trimmed) === 1) {
            return $trimmed;
        }
        return 'all';
    }

    private function extractReceiptYear(array $receipt): ?string
    {
        $date = trim((string) ($receipt['date'] ?? ''));
        if (preg_match('/^(\d{4})-\d{2}-\d{2}/', $date, $match) === 1) {
            return $match[1];
        }
        if (preg_match('/^\d{4}$/', $date) === 1) {
            return $date;
        }

        $createdAt = trim((string) ($receipt['createdAt'] ?? ''));
        if (preg_match('/^(\d{4})-\d{2}-\d{2}/', $createdAt, $match) === 1) {
            return $match[1];
        }

        return null;
    }

    private function extractReceiptYears(array $receipts): array
    {
        $years = [];
        foreach ($receipts as $receipt) {
            if (!is_array($receipt)) {
                continue;
            }
            $year = $this->extractReceiptYear($receipt);
            if ($year === null) {
                continue;
            }
            $years[$year] = true;
        }
        $keys = array_keys($years);
        rsort($keys, SORT_STRING);
        return $keys;
    }

    private function formatUsd(float $value): string
    {
        return '$' . number_format($value, 2, '.', '');
    }

    private function upsertLocalConfigDefine(string $name, $value): bool
    {
        $line = "define('" . $name . "', " . var_export($value, true) . ');';
        $path = LOCAL_CONFIG_FILE;

        if (!is_file($path)) {
            $content = "<?php\n";
            $content .= "declare(strict_types=1);\n\n";
            $content .= "// Local overrides (do not commit).\n";
            $content .= $line . "\n";
            $ok = file_put_contents($path, $content, LOCK_EX) !== false;
            if ($ok) {
                invalidate_runtime_config_cache();
            }
            return $ok;
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
            $ok = file_put_contents($path, $updated, LOCK_EX) !== false;
            if ($ok) {
                invalidate_runtime_config_cache();
            }
            return $ok;
        }

        $updated = rtrim($content) . "\n" . $line . "\n";
        $ok = file_put_contents($path, $updated, LOCK_EX) !== false;
        if ($ok) {
            invalidate_runtime_config_cache();
        }
        return $ok;
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
        $tarAvailable = class_exists('PharData');
        $archiveAvailable = true;
        $archiveDetailParts = [];
        if ($zipAvailable) {
            $archiveDetailParts[] = 'ZipArchive available';
        }
        if ($tarAvailable) {
            $archiveDetailParts[] = 'PharData (tar/tar.gz) available';
        }
        if ($archiveDetailParts === []) {
            $archiveDetailParts[] = 'ZipArchive and PharData missing; JSON bundle fallback available';
        }
        $checks[] = $this->makeCheck(
            'Archive export/import support',
            false,
            $archiveAvailable,
            implode('; ', $archiveDetailParts) . '.'
        );

        $curlAvailable = function_exists('curl_init');
        $checks[] = $this->makeCheck(
            'cURL extension available',
            $veryfiConfigured,
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

    private function archiveSupportLabel(): string
    {
        $parts = [];
        if (class_exists('ZipArchive')) {
            $parts[] = 'zip';
        }
        if (class_exists('PharData')) {
            $parts[] = 'tar/tar.gz';
        }
        if ($parts === []) {
            return 'JSON fallback only';
        }
        return strtoupper(implode(', ', $parts));
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

}
