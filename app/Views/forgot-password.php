<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Reset Password</title>
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
        --warning: #b65d3a;
        --ok: #2f6f65;
        --shadow: 0 18px 40px rgba(18, 16, 14, 0.1);
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

      .card {
        width: min(460px, 100%);
        background: var(--panel);
        border: 1px solid var(--line);
        padding: 28px;
        box-shadow: var(--shadow);
        display: grid;
        gap: 14px;
      }

      h1 {
        font-family: "Space Grotesk", sans-serif;
        margin: 0;
      }

      p {
        margin: 0;
        color: var(--muted);
      }

      label {
        display: grid;
        gap: 8px;
        font-weight: 600;
      }

      input[type="email"],
      input[type="password"] {
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
        font-weight: 600;
        cursor: pointer;
      }

      .error {
        color: var(--warning);
        font-weight: 600;
      }

      .success {
        color: var(--ok);
        font-weight: 600;
      }
    </style>
  </head>
  <body>
    <form class="card" method="post" action="">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
      <h1>Reset password</h1>
      <p>Enter the recovery email and set a new password.</p>

      <?php if (!empty($error)): ?>
      <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
      <?php endif; ?>

      <?php if (!empty($success)): ?>
      <div class="success"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
      <?php endif; ?>

      <label>
        Recovery email
        <input
          type="email"
          name="recovery_email"
          autocomplete="email"
          value="<?php echo htmlspecialchars($recoveryEmail ?? '', ENT_QUOTES, 'UTF-8'); ?>"
          required
        />
      </label>
      <label>
        New password
        <input type="password" name="new_password" autocomplete="new-password" required />
      </label>
      <label>
        Confirm new password
        <input type="password" name="confirm_password" autocomplete="new-password" required />
      </label>
      <button class="btn" type="submit">Reset password</button>
      <p><a href="<?php echo htmlspecialchars(url_path('login'), ENT_QUOTES, 'UTF-8'); ?>">Back to sign in</a></p>
    </form>
  </body>
</html>
