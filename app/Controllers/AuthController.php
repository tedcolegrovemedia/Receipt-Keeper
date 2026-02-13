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
            $error = 'Password not set. Visit /install to complete setup.';
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
            } elseif (!verify_csrf_or_same_origin($_POST['csrf_token'] ?? null)) {
                $error = 'Session expired. Please refresh and try again.';
            } else {
                $username = normalize_app_username((string) ($_POST['username'] ?? ''));
                $password = $_POST['password'] ?? '';
                $expectedUsername = get_app_username();

                if ($username === $expectedUsername && password_verify($password, get_password_hash())) {
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

    public function forgotPassword(): void
    {
        if (!empty($_SESSION['authenticated'])) {
            redirect_to('');
        }

        $error = '';
        $success = '';
        $pinHash = get_password_reset_pin_hash();
        $pinConfigured = $pinHash !== '';
        $ip = get_client_ip();
        $rateStatus = reset_pin_rate_limit_status($ip);
        if ($rateStatus['blocked']) {
            $minutes = ceil($rateStatus['retry_after'] / 60);
            $error = "Too many reset attempts. Try again in about {$minutes} minute(s).";
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!verify_csrf_or_same_origin($_POST['csrf_token'] ?? null)) {
                $error = 'Session expired. Please refresh and try again.';
            } elseif ($rateStatus['blocked']) {
                // Do not process while rate limited.
            } elseif (!$pinConfigured) {
                $error = 'Reset PIN is not configured. Run installer to set a 4-digit reset PIN.';
            } else {
                $pin = trim((string) ($_POST['reset_pin'] ?? ''));
                $new = (string) ($_POST['new_password'] ?? '');
                $confirm = (string) ($_POST['confirm_password'] ?? '');

                if (!preg_match('/^\d{4}$/', $pin)) {
                    $error = 'Reset PIN must be exactly 4 digits.';
                } elseif (!password_verify($pin, $pinHash)) {
                    $attempts = register_failed_reset_pin_attempt($ip);
                    $remaining = max(0, MAX_RESET_PIN_ATTEMPTS - $attempts);
                    $error = $remaining > 0
                        ? "Invalid reset PIN. {$remaining} attempt(s) remaining."
                        : 'Too many reset attempts. Try again later.';
                } elseif (strlen($new) < MIN_PASSWORD_LENGTH) {
                    $error = 'New password is too short. Use at least ' . MIN_PASSWORD_LENGTH . ' characters.';
                } elseif ($new !== $confirm) {
                    $error = 'New passwords do not match.';
                } elseif (!set_password_hash(password_hash($new, PASSWORD_DEFAULT))) {
                    $error = 'Could not reset password. Check folder permissions.';
                } else {
                    clear_failed_reset_pin_attempts($ip);
                    $success = 'Password reset. You can sign in now.';
                }
            }
        }

        render('forgot-password', [
            'error' => $error,
            'success' => $success,
            'pinConfigured' => $pinConfigured,
        ]);
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
            if (!verify_csrf_or_same_origin($_POST['csrf_token'] ?? null)) {
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
