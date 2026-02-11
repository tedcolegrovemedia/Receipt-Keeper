<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Change Password</title>
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
        --input-bg: #fffdf9;
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

      body.dark {
        color-scheme: dark;
        --bg: #11100e;
        --bg-accent: #1c1a16;
        --ink: #f2eee8;
        --muted: #b7aca3;
        --panel: #1a1916;
        --line: #2f2b26;
        --accent: #5bc4b2;
        --accent-strong: #3ea493;
        --accent-soft: #1e332f;
        --warning: #e19a74;
        --shadow: 0 18px 40px rgba(0, 0, 0, 0.45);
        --input-bg: #1f1d19;
      }

      body.dark {
        background: radial-gradient(circle at top, #191815 0%, #13110f 55%, #0f0e0c 100%);
      }

      body.dark::before {
        background: linear-gradient(120deg, rgba(91, 196, 178, 0.12), transparent 45%),
          linear-gradient(300deg, rgba(225, 154, 116, 0.12), transparent 50%);
      }

      .card {
        position: relative;
        z-index: 1;
        width: min(460px, 100%);
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

      input[type="password"] {
        border-radius: 12px;
        border: 1px solid var(--line);
        padding: 10px 12px;
        font-size: 1rem;
        font-family: "Source Sans 3", sans-serif;
        background: var(--input-bg);
        color: var(--ink);
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

      .btn.ghost {
        background: transparent;
        color: var(--accent-strong);
      }

      .actions {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        align-items: center;
      }

      .error {
        color: var(--warning);
        font-weight: 600;
      }

      .success {
        color: var(--accent-strong);
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
    <form class="card" method="post" action="">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
      <div>
        <p class="eyebrow">Receipt Logger</p>
        <h1>Change password</h1>
        <p>Use at least <?php echo MIN_PASSWORD_LENGTH; ?> characters.</p>
      </div>

      <?php if ($error): ?>
      <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
      <?php endif; ?>

      <?php if ($success): ?>
      <div class="success"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
      <?php endif; ?>

      <label>
        Current password
        <input type="password" name="current_password" autocomplete="current-password" required />
      </label>
      <label>
        New password
        <input type="password" name="new_password" autocomplete="new-password" required />
      </label>
      <label>
        Confirm new password
        <input type="password" name="confirm_password" autocomplete="new-password" required />
      </label>

      <div class="actions">
        <button class="btn ghost" id="themeToggle" type="button" aria-pressed="false">Dark mode</button>
        <button class="btn" type="submit">Update password</button>
        <a class="btn ghost" href="<?php echo htmlspecialchars(url_path(''), ENT_QUOTES, 'UTF-8'); ?>">Back to app</a>
      </div>
    </form>
    <script src="<?php echo htmlspecialchars(asset_path('assets/theme.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
  </body>
</html>
