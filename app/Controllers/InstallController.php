<?php
declare(strict_types=1);

class InstallController
{
    public function index(): void
    {
        if (!needs_install()) {
            redirect_to('login');
        }

        $error = '';
        $availability = [
            'sqlite' => sqlite_available(),
            'mysql' => mysql_available(),
        ];
        $defaultMode = $availability['sqlite'] ? 'sqlite' : 'json';
        $defaultRecoveryEmail = get_forgot_password_email() !== '' ? get_forgot_password_email() : 'primerx24@gmail.com';
        $values = [
            'storage_mode' => $defaultMode,
            'forgot_email' => $defaultRecoveryEmail,
            'veryfi_client_id' => '',
            'veryfi_client_secret' => '',
            'veryfi_username' => '',
            'veryfi_api_key' => '',
            'mysql_host' => '',
            'mysql_port' => '3306',
            'mysql_database' => '',
            'mysql_username' => '',
            'mysql_password' => '',
        ];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $values = [
                'storage_mode' => trim((string) ($_POST['storage_mode'] ?? $defaultMode)),
                'forgot_email' => strtolower(trim((string) ($_POST['forgot_email'] ?? $defaultRecoveryEmail))),
                'veryfi_client_id' => trim((string) ($_POST['veryfi_client_id'] ?? '')),
                'veryfi_client_secret' => trim((string) ($_POST['veryfi_client_secret'] ?? '')),
                'veryfi_username' => trim((string) ($_POST['veryfi_username'] ?? '')),
                'veryfi_api_key' => trim((string) ($_POST['veryfi_api_key'] ?? '')),
                'mysql_host' => trim((string) ($_POST['mysql_host'] ?? '')),
                'mysql_port' => trim((string) ($_POST['mysql_port'] ?? '3306')),
                'mysql_database' => trim((string) ($_POST['mysql_database'] ?? '')),
                'mysql_username' => trim((string) ($_POST['mysql_username'] ?? '')),
                'mysql_password' => (string) ($_POST['mysql_password'] ?? ''),
            ];
            if (!in_array($values['storage_mode'], ['json', 'sqlite', 'mysql'], true)) {
                $values['storage_mode'] = $defaultMode;
            }

            if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
                $error = 'Session expired. Please refresh and try again.';
            } elseif (!data_store_available()) {
                $error = 'Data folder is not writable. Ensure the data/ folder is writable by the server.';
            } else {
                $password = (string) ($_POST['password'] ?? '');
                $confirm = (string) ($_POST['confirm_password'] ?? '');
                if (strlen($password) < MIN_PASSWORD_LENGTH) {
                    $error = 'Password is too short. Use at least ' . MIN_PASSWORD_LENGTH . ' characters.';
                } elseif ($password !== $confirm) {
                    $error = 'Passwords do not match.';
                } elseif (!filter_var($values['forgot_email'], FILTER_VALIDATE_EMAIL)) {
                    $error = 'Recovery email is required and must be a valid email address.';
                } elseif ($values['storage_mode'] === 'sqlite' && !$availability['sqlite']) {
                    $error = 'SQLite is not available on this server.';
                } elseif ($values['storage_mode'] === 'mysql') {
                    if (!$availability['mysql']) {
                        $error = 'MySQL is not available on this server.';
                    } elseif ($values['mysql_host'] === '' || $values['mysql_database'] === '' || $values['mysql_username'] === '') {
                        $error = 'MySQL host, database, and username are required.';
                    } else {
                        $mysqlError = $this->testMysqlConnection($values);
                        if ($mysqlError !== '') {
                            $error = $mysqlError;
                        }
                    }
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    if (!set_password_hash($hash)) {
                        $error = 'Could not save password. Check folder permissions.';
                    } elseif (!set_forgot_password_email($values['forgot_email'])) {
                        $error = 'Could not save recovery email. Check folder permissions.';
                    } else {
                        $this->writeLocalConfig($values);
                        session_regenerate_id(true);
                        $_SESSION['authenticated'] = true;
                        redirect_to('');
                    }
                }
            }
        }

        render('install', [
            'error' => $error,
            'values' => $values,
            'minLength' => MIN_PASSWORD_LENGTH,
            'availability' => $availability,
        ]);
    }

    private function writeLocalConfig(array $values): void
    {
        if (is_file(LOCAL_CONFIG_FILE)) {
            return;
        }

        $lines = [
            '<?php',
            'declare(strict_types=1);',
            '',
        ];

        $lines[] = "define('STORAGE_MODE', " . var_export($values['storage_mode'] ?? 'auto', true) . ');';

        if (($values['storage_mode'] ?? '') === 'mysql') {
            $lines[] = "define('MYSQL_HOST', " . var_export($values['mysql_host'] ?? '', true) . ');';
            $lines[] = "define('MYSQL_PORT', " . var_export((int) ($values['mysql_port'] ?? 3306), true) . ');';
            $lines[] = "define('MYSQL_DATABASE', " . var_export($values['mysql_database'] ?? '', true) . ');';
            $lines[] = "define('MYSQL_USERNAME', " . var_export($values['mysql_username'] ?? '', true) . ');';
            $lines[] = "define('MYSQL_PASSWORD', " . var_export($values['mysql_password'] ?? '', true) . ');';
            $lines[] = '';
        }

        $hasVeryfi = false;
        foreach (['veryfi_client_id', 'veryfi_client_secret', 'veryfi_username', 'veryfi_api_key'] as $key) {
            if (!empty($values[$key])) {
                $hasVeryfi = true;
                break;
            }
        }

        if ($hasVeryfi) {
            $lines[] = "define('VERYFI_CLIENT_ID', " . var_export($values['veryfi_client_id'], true) . ');';
            $lines[] = "define('VERYFI_CLIENT_SECRET', " . var_export($values['veryfi_client_secret'], true) . ');';
            $lines[] = "define('VERYFI_USERNAME', " . var_export($values['veryfi_username'], true) . ');';
            $lines[] = "define('VERYFI_API_KEY', " . var_export($values['veryfi_api_key'], true) . ');';
        } else {
            $lines[] = '// No local overrides.';
        }

        $payload = implode(PHP_EOL, $lines) . PHP_EOL;
        @file_put_contents(LOCAL_CONFIG_FILE, $payload, LOCK_EX);
    }

    private function testMysqlConnection(array $values): string
    {
        $host = $values['mysql_host'] ?? '';
        $database = $values['mysql_database'] ?? '';
        $username = $values['mysql_username'] ?? '';
        $password = $values['mysql_password'] ?? '';
        $port = (int) ($values['mysql_port'] ?? 3306);
        if ($port <= 0) {
            $port = 3306;
        }
        try {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database);
            $db = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            init_receipts_mysql_db($db);
        } catch (Throwable $error) {
            return 'Could not connect to MySQL. ' . $error->getMessage();
        }
        return '';
    }
}
