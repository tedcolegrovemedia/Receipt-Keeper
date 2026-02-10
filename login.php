<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

start_secure_session();

if (!empty($_SESSION['authenticated'])) {
    header('Location: index.php');
    exit;
}

$error = '';
$passwordReady = !(APP_PASSWORD_HASH === 'REPLACE_WITH_PASSWORD_HASH' && !is_file(PASSWORD_FILE));
if (!$passwordReady) {
    $error = 'Password not set. Update config.php with a password hash before signing in.';
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
            header('Location: index.php');
            exit;
        }

        $attempts = register_failed_attempt($ip);
        $remaining = max(0, MAX_LOGIN_ATTEMPTS - $attempts);
        $error = $remaining > 0
            ? "Invalid username or password. {$remaining} attempt(s) remaining."
            : 'Too many attempts. Try again later.';
    }
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Receipt Logger Login</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Source+Sans+3:wght@400;500;600&display=swap"
      rel="stylesheet"
    />
    <style>
      :root {
        color-scheme: light;
        --bg: #f4efe6;
        --bg-accent: #f8dcc0;
        --ink: #1f1a17;
        --muted: #61584f;
        --panel: #fff8f0;
        --line: #e2d3c4;
        --accent: #2f6f65;
        --accent-strong: #20524b;
        --accent-soft: #d8eee9;
        --warning: #b65d3a;
        --shadow: 0 18px 40px rgba(18, 16, 14, 0.1);
        --radius: 0px;
      }

      * {
        box-sizing: border-box;
        border-radius: 0 !important;
      }

      body {
        margin: 0;
        font-family: "Source Sans 3", system-ui, sans-serif;
        background: radial-gradient(circle at top, #fff4e8 0%, #f7eadd 45%, #f1e3d4 100%);
        color: var(--ink);
        min-height: 100vh;
        display: grid;
        place-items: center;
        padding: 24px;
      }

      body::before {
        content: "";
        position: fixed;
        inset: 0;
        background: linear-gradient(120deg, rgba(47, 111, 101, 0.12), transparent 45%),
          linear-gradient(300deg, rgba(182, 93, 58, 0.14), transparent 50%);
        pointer-events: none;
        z-index: 0;
      }

      .login-card {
        position: relative;
        z-index: 1;
        width: min(420px, 100%);
        background: var(--panel);
        border: 1px solid var(--line);
        border-radius: var(--radius);
        padding: 28px;
        box-shadow: var(--shadow);
        display: grid;
        gap: 16px;
        animation: fadeUp 0.6s ease-out both;
      }

      h1 {
        font-family: "Space Grotesk", sans-serif;
        margin: 0 0 6px;
      }

      p {
        margin: 0;
        color: var(--muted);
      }

      .eyebrow {
        text-transform: uppercase;
        letter-spacing: 0.2em;
        font-size: 0.75rem;
        color: var(--accent-strong);
        font-weight: 600;
        margin: 0 0 8px;
      }

      label {
        display: grid;
        gap: 8px;
        font-weight: 600;
      }

      input[type="text"],
      input[type="password"] {
        border-radius: 12px;
        border: 1px solid var(--line);
        padding: 10px 12px;
        font-size: 1rem;
        font-family: "Source Sans 3", sans-serif;
        background: #fffdf9;
      }

      .btn {
        border: 1px solid var(--accent-strong);
        background: var(--accent-strong);
        color: #fffaf4;
        padding: 10px 16px;
        border-radius: 999px;
        font-weight: 600;
        cursor: pointer;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
      }

      .btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 6px 12px rgba(47, 111, 101, 0.18);
      }

      .error {
        color: var(--warning);
        font-weight: 600;
      }

      @keyframes fadeUp {
        from {
          opacity: 0;
          transform: translateY(12px);
        }
        to {
          opacity: 1;
          transform: translateY(0);
        }
      }
    </style>
  </head>
  <body>
    <form class="login-card" method="post" action="">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
      <div>
        <p class="eyebrow">Receipt Logger</p>
        <h1>Sign in</h1>
        <p>Enter your shared password to continue.</p>
      </div>

      <?php if ($error): ?>
      <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
      <?php endif; ?>

      <label>
        Username
        <input type="text" name="username" autocomplete="username" required />
      </label>
      <label>
        Password
        <input type="password" name="password" autocomplete="current-password" required />
      </label>
      <button class="btn" type="submit">Sign in</button>
    </form>
  </body>
</html>
