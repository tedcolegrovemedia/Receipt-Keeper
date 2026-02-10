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
        $values = [
            'veryfi_client_id' => '',
            'veryfi_client_secret' => '',
            'veryfi_username' => '',
            'veryfi_api_key' => '',
        ];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $values = [
                'veryfi_client_id' => trim((string) ($_POST['veryfi_client_id'] ?? '')),
                'veryfi_client_secret' => trim((string) ($_POST['veryfi_client_secret'] ?? '')),
                'veryfi_username' => trim((string) ($_POST['veryfi_username'] ?? '')),
                'veryfi_api_key' => trim((string) ($_POST['veryfi_api_key'] ?? '')),
            ];

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
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    if (!set_password_hash($hash)) {
                        $error = 'Could not save password. Check folder permissions.';
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
}
