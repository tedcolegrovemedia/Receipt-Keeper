<?php
declare(strict_types=1);

class AdminController
{
    public function index(): void
    {
        ensure_authenticated();

        $checks = $this->buildChecks();
        $summary = ['pass' => 0, 'warning' => 0, 'fail' => 0];
        foreach ($checks as $check) {
            $status = (string) ($check['status'] ?? '');
            if (isset($summary[$status])) {
                $summary[$status] += 1;
            }
        }

        $runtime = [
            'Checked At' => (new DateTime('now', new DateTimeZone('America/New_York')))->format('Y-m-d H:i:s T'),
            'PHP Version' => PHP_VERSION,
            'SAPI' => PHP_SAPI,
            'Server Software' => (string) ($_SERVER['SERVER_SOFTWARE'] ?? 'unknown'),
            'Base Path' => base_path() === '' ? '/' : base_path(),
            'Configured Storage Mode' => strtoupper(storage_mode()),
            'Active Storage Driver' => strtoupper(storage_driver()),
            'Default OCR' => $this->defaultOcrProvider(),
            'Veryfi OCR Remaining' => $this->veryfiRemainingLabel(),
        ];

        render('admin', [
            'checks' => $checks,
            'summary' => $summary,
            'runtime' => $runtime,
        ]);
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
