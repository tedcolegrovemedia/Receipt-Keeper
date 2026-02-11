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

    public function forgotPassword(): void
    {
        if (!empty($_SESSION['authenticated'])) {
            redirect_to('');
        }

        $error = '';
        $success = '';
        $codeInput = '';
        $configuredEmail = get_forgot_password_email();
        $configuredPhone = get_forgot_password_phone();
        $delivery = $this->resolveRecoveryDelivery($configuredEmail, $configuredPhone);
        $state = $this->loadForgotPasswordState();
        $now = time();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = (string) ($_POST['action'] ?? '');

            if ($delivery['channel'] === 'none') {
                $error = 'Recovery contact is not configured. Set a recovery email or phone in setup.';
            } else {
                if ($action === 'send_code') {
                    $lastSent = isset($state['sent_at']) ? (int) $state['sent_at'] : 0;
                    $wait = FORGOT_CODE_RESEND_SECONDS - ($now - $lastSent);
                    if ($lastSent > 0 && $wait > 0) {
                        $error = 'Please wait ' . $wait . ' second(s) before sending a new code.';
                    } else {
                        $code = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
                        if (!$this->sendRecoveryCode($delivery, $code)) {
                            $error = 'Could not send reset code. Check recovery delivery settings.';
                        } else {
                            $state = [
                                'code_hash' => password_hash($code, PASSWORD_DEFAULT),
                                'expires_at' => $now + FORGOT_CODE_TTL_SECONDS,
                                'sent_at' => $now,
                                'attempts' => 0,
                                'verified' => false,
                            ];
                            $this->saveForgotPasswordState($state);
                            $success = 'Code sent to ' . $delivery['masked'] . '.';
                        }
                    }
                } elseif ($action === 'verify_code') {
                    $codeInput = trim((string) ($_POST['code'] ?? ''));
                    if ($codeInput === '' || !preg_match('/^\d{4}$/', $codeInput)) {
                        $error = 'Enter the 4-digit code.';
                    } elseif (empty($state['code_hash'])) {
                        $error = 'Send a code first.';
                    } elseif ((int) ($state['expires_at'] ?? 0) <= $now) {
                        $this->clearForgotPasswordState();
                        $state = [];
                        $error = 'Code expired. Send a new code.';
                    } else {
                        $attempts = (int) ($state['attempts'] ?? 0);
                        if ($attempts >= FORGOT_CODE_MAX_ATTEMPTS) {
                            $this->clearForgotPasswordState();
                            $state = [];
                            $error = 'Too many incorrect attempts. Send a new code.';
                        } elseif (!password_verify($codeInput, (string) ($state['code_hash'] ?? ''))) {
                            $state['attempts'] = $attempts + 1;
                            $this->saveForgotPasswordState($state);
                            $remaining = max(0, FORGOT_CODE_MAX_ATTEMPTS - (int) $state['attempts']);
                            $error = $remaining > 0
                                ? "Incorrect code. {$remaining} attempt(s) remaining."
                                : 'Incorrect code.';
                        } else {
                            $state['verified'] = true;
                            $this->saveForgotPasswordState($state);
                            $success = 'Code verified. Set a new password.';
                        }
                    }
                } elseif ($action === 'reset_password') {
                    $new = (string) ($_POST['new_password'] ?? '');
                    $confirm = (string) ($_POST['confirm_password'] ?? '');
                    if (empty($state['code_hash'])) {
                        $error = 'Send and verify a code first.';
                    } elseif ((int) ($state['expires_at'] ?? 0) <= $now) {
                        $this->clearForgotPasswordState();
                        $state = [];
                        $error = 'Code expired. Send a new code.';
                    } elseif (empty($state['verified'])) {
                        $error = 'Verify the code before changing the password.';
                    } elseif (strlen($new) < MIN_PASSWORD_LENGTH) {
                        $error = 'New password is too short. Use at least ' . MIN_PASSWORD_LENGTH . ' characters.';
                    } elseif ($new !== $confirm) {
                        $error = 'New passwords do not match.';
                    } elseif (!set_password_hash(password_hash($new, PASSWORD_DEFAULT))) {
                        $error = 'Could not reset password. Check folder permissions.';
                    } else {
                        $this->clearForgotPasswordState();
                        $state = [];
                        $success = 'Password reset. You can sign in now.';
                    }
                } else {
                    $error = 'Invalid request.';
                }
            }
        }

        $state = $this->loadForgotPasswordState();
        $expiresAt = isset($state['expires_at']) ? (int) $state['expires_at'] : 0;
        $codeSent = !empty($state['code_hash']) && $expiresAt > time();
        $codeVerified = $codeSent && !empty($state['verified']);
        $expiresIn = $codeSent ? max(0, $expiresAt - time()) : 0;

        render('forgot-password', [
            'error' => $error,
            'success' => $success,
            'maskedDestination' => $delivery['masked'],
            'deliveryLabel' => $delivery['channel'] === 'sms' ? 'SMS phone' : 'Recovery email',
            'codeInput' => $codeInput,
            'codeSent' => $codeSent,
            'codeVerified' => $codeVerified,
            'expiresIn' => $expiresIn,
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

    private function loadForgotPasswordState(): array
    {
        $state = $_SESSION['forgot_password'] ?? [];
        return is_array($state) ? $state : [];
    }

    private function saveForgotPasswordState(array $state): void
    {
        $_SESSION['forgot_password'] = $state;
    }

    private function clearForgotPasswordState(): void
    {
        unset($_SESSION['forgot_password']);
    }

    private function maskEmail(string $email): string
    {
        if (strpos($email, '@') === false) {
            return $email;
        }
        [$local, $domain] = explode('@', $email, 2);
        $local = trim($local);
        if ($local === '') {
            return '***@' . $domain;
        }
        if (strlen($local) === 1) {
            return $local . '***@' . $domain;
        }
        return substr($local, 0, 1) . str_repeat('*', max(2, strlen($local) - 2)) . substr($local, -1) . '@' . $domain;
    }

    private function sendRecoveryCode(array $delivery, string $code): bool
    {
        if (($delivery['channel'] ?? '') === 'sms') {
            return $this->sendRecoveryCodeSms((string) $delivery['destination'], $code);
        }
        if (($delivery['channel'] ?? '') === 'email') {
            return $this->sendRecoveryCodeEmail((string) $delivery['destination'], $code);
        }
        return false;
    }

    private function sendRecoveryCodeEmail(string $email, string $code): bool
    {
        if (!function_exists('mail')) {
            return false;
        }
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $host = preg_replace('/:\d+$/', '', $host);
        $host = preg_replace('/^www\./i', '', $host);
        if (!is_string($host) || trim($host) === '') {
            $host = 'localhost.localdomain';
        }
        $subject = 'Receipt Keeper password reset code';
        $body = "Your Receipt Keeper password reset code is: {$code}\n\nThis code expires in 10 minutes.\nIf you did not request this reset, ignore this email.\n";
        $headers = [
            'From: Receipt Keeper <noreply@' . $host . '>',
            'Content-Type: text/plain; charset=UTF-8',
        ];
        return @mail($email, $subject, $body, implode("\r\n", $headers));
    }

    private function sendRecoveryCodeSms(string $phone, string $code): bool
    {
        if (!function_exists('curl_init')) {
            return false;
        }
        if (!$this->twilioConfigured()) {
            return false;
        }

        $url = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode(TWILIO_ACCOUNT_SID) . '/Messages.json';
        $body = "Your Receipt Keeper reset code is {$code}. It expires in 10 minutes.";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, TWILIO_ACCOUNT_SID . ':' . TWILIO_AUTH_TOKEN);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'From' => TWILIO_FROM_NUMBER,
            'To' => $phone,
            'Body' => $body,
        ]));
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ok = $response !== false && $httpCode >= 200 && $httpCode < 300;
        if (function_exists('curl_close')) {
            curl_close($ch);
        }
        return $ok;
    }

    private function twilioConfigured(): bool
    {
        return TWILIO_ACCOUNT_SID !== '' && TWILIO_AUTH_TOKEN !== '' && TWILIO_FROM_NUMBER !== '';
    }

    private function resolveRecoveryDelivery(string $email, string $phone): array
    {
        $normalizedEmail = strtolower(trim($email));
        $normalizedPhone = trim($phone);
        if ($normalizedPhone !== '' && $this->twilioConfigured()) {
            return [
                'channel' => 'sms',
                'destination' => $normalizedPhone,
                'masked' => $this->maskPhone($normalizedPhone),
            ];
        }
        if ($normalizedEmail !== '') {
            return [
                'channel' => 'email',
                'destination' => $normalizedEmail,
                'masked' => $this->maskEmail($normalizedEmail),
            ];
        }
        return [
            'channel' => 'none',
            'destination' => '',
            'masked' => 'not configured',
        ];
    }

    private function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        if (!is_string($digits) || strlen($digits) < 4) {
            return $phone;
        }
        $tail = substr($digits, -4);
        return '***-***-' . $tail;
    }
}
