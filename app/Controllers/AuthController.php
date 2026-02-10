<?php
declare(strict_types=1);

class AuthController
{
    public function login(): void
    {
        if (!empty($_SESSION['authenticated'])) {
            redirect_to('');
        }

        $error = '';
        $passwordReady = get_password_hash() !== '';
        if (!$passwordReady) {
            $error = 'Password not set. Create data/password.json with a bcrypt hash before signing in.';
        }

        $ip = get_client_ip();
        $rateStatus = rate_limit_status($ip);
        if ($rateStatus['blocked']) {
            $minutes = ceil($rateStatus['retry_after'] / 60);
            $error = "Too many attempts. Try again in about {$minutes} minute(s).";
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!$passwordReady) {
                // Password file not configured yet.
            } elseif ($rateStatus['blocked']) {
                // Do not process while rate limited.
            } elseif (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
                $error = 'Session expired. Please refresh and try again.';
            } else {
                $username = trim($_POST['username'] ?? '');
                $password = $_POST['password'] ?? '';

                if ($username === APP_USERNAME && password_verify($password, get_password_hash())) {
                    session_regenerate_id(true);
                    $_SESSION['authenticated'] = true;
                    clear_failed_attempts($ip);
                    redirect_to('');
                }

                $attempts = register_failed_attempt($ip);
                $remaining = max(0, MAX_LOGIN_ATTEMPTS - $attempts);
                $error = $remaining > 0
                    ? "Invalid username or password. {$remaining} attempt(s) remaining."
                    : 'Too many attempts. Try again later.';
            }
        }

        render('login', ['error' => $error]);
    }

    public function logout(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
        redirect_to('login');
    }

    public function changePassword(): void
    {
        ensure_authenticated();

        $error = '';
        $success = '';

        if (!data_store_available()) {
            $error = 'Password store is not writable. Ensure the data/ folder is writable by the server.';
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
            if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
                $error = 'Session expired. Please refresh and try again.';
            } else {
                $current = $_POST['current_password'] ?? '';
                $new = $_POST['new_password'] ?? '';
                $confirm = $_POST['confirm_password'] ?? '';

                if (!password_verify($current, get_password_hash())) {
                    $error = 'Current password is incorrect.';
                } elseif (strlen($new) < MIN_PASSWORD_LENGTH) {
                    $error = 'New password is too short. Use at least ' . MIN_PASSWORD_LENGTH . ' characters.';
                } elseif ($new !== $confirm) {
                    $error = 'New passwords do not match.';
                } else {
                    $hash = password_hash($new, PASSWORD_DEFAULT);
                    if (!set_password_hash($hash)) {
                        $error = 'Could not update password. Check folder permissions.';
                    } else {
                        session_regenerate_id(true);
                        $success = 'Password updated.';
                    }
                }
            }
        }

        render('change-password', ['error' => $error, 'success' => $success]);
    }
}
