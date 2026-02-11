<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Receipt Keeper Setup</title>
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

      .setup-card {
        position: relative;
        z-index: 1;
        width: min(640px, 100%);
        background: var(--panel);
        border: 1px solid var(--line);
        padding: 28px;
        box-shadow: var(--shadow);
        display: grid;
        gap: 18px;
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

      .grid {
        display: grid;
        gap: 12px;
      }

      fieldset {
        border: 1px solid var(--line);
        padding: 12px;
        background: #fffdf9;
        display: grid;
        gap: 10px;
      }

      legend {
        font-weight: 600;
        padding: 0 6px;
      }

      .option {
        display: flex;
        gap: 10px;
        align-items: center;
        font-weight: 600;
      }

      .option input {
        margin: 0;
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

      details {
        border: 1px solid var(--line);
        padding: 12px;
        background: #fffdf9;
      }

      summary {
        cursor: pointer;
        font-weight: 600;
      }

      .btn {
        border: 1px solid var(--accent-strong);
        background: var(--accent-strong);
        color: #fffaf4;
        padding: 10px 16px;
        font-weight: 600;
        cursor: pointer;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        text-decoration: none;
        width: fit-content;
      }

      .btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 6px 12px rgba(47, 111, 101, 0.18);
      }

      .hint {
        font-size: 0.95rem;
        color: var(--muted);
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
    <form class="setup-card" method="post" action="">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
      <div>
        <p class="eyebrow">Receipt Keeper</p>
        <h1>Initial setup</h1>
        <p>Set your admin password and (optionally) add Veryfi OCR credentials.</p>
      </div>

      <?php if (!empty($error)): ?>
      <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
      <?php endif; ?>

      <div class="grid">
        <label>
          Recovery email for forgot password
          <input
            type="text"
            name="forgot_email"
            value="<?php echo htmlspecialchars($values['forgot_email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
          />
        </label>
        <label>
          Recovery phone for SMS (optional, E.164 format)
          <input
            type="text"
            name="forgot_phone"
            value="<?php echo htmlspecialchars($values['forgot_phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
            placeholder="+15551234567"
          />
        </label>
        <label>
          Admin password (min <?php echo (int) $minLength; ?> characters)
          <input type="password" name="password" autocomplete="new-password" required />
        </label>
        <label>
          Confirm password
          <input type="password" name="confirm_password" autocomplete="new-password" required />
        </label>
      </div>

      <fieldset>
        <legend>Storage</legend>
        <label class="option">
          <input
            type="radio"
            name="storage_mode"
            value="json"
            <?php echo ($values['storage_mode'] ?? '') === 'json' ? 'checked' : ''; ?>
          />
          JSON (works everywhere)
        </label>
        <label class="option">
          <input
            type="radio"
            name="storage_mode"
            value="sqlite"
            <?php echo ($values['storage_mode'] ?? '') === 'sqlite' ? 'checked' : ''; ?>
          />
          SQLite (if available)
        </label>
        <label class="option">
          <input
            type="radio"
            name="storage_mode"
            value="mysql"
            <?php echo ($values['storage_mode'] ?? '') === 'mysql' ? 'checked' : ''; ?>
          />
          MySQL
        </label>
        <p class="hint">
          Detected on this server: SQLite available: <?php echo !empty($availability['sqlite']) ? 'yes' : 'no'; ?>.
          MySQL driver available: <?php echo !empty($availability['mysql']) ? 'yes' : 'no'; ?>.
        </p>
      </fieldset>

      <details id="mysqlDetails">
        <summary>MySQL settings (required if MySQL selected)</summary>
        <div class="grid" style="margin-top: 12px;">
          <label>
            Host
            <input type="text" name="mysql_host" value="<?php echo htmlspecialchars($values['mysql_host'], ENT_QUOTES, 'UTF-8'); ?>" />
          </label>
          <label>
            Port
            <input type="text" name="mysql_port" value="<?php echo htmlspecialchars($values['mysql_port'], ENT_QUOTES, 'UTF-8'); ?>" />
          </label>
          <label>
            Database
            <input type="text" name="mysql_database" value="<?php echo htmlspecialchars($values['mysql_database'], ENT_QUOTES, 'UTF-8'); ?>" />
          </label>
          <label>
            Username
            <input type="text" name="mysql_username" value="<?php echo htmlspecialchars($values['mysql_username'], ENT_QUOTES, 'UTF-8'); ?>" />
          </label>
          <label>
            Password
            <input type="password" name="mysql_password" value="<?php echo htmlspecialchars($values['mysql_password'], ENT_QUOTES, 'UTF-8'); ?>" />
          </label>
          <p class="hint">Make sure the database exists and the user has create/update permissions.</p>
        </div>
      </details>

      <details>
        <summary>Veryfi OCR (optional but suggested for accuracy)</summary>
        <div class="grid" style="margin-top: 12px;">
          <label>
            Client ID
            <input type="text" name="veryfi_client_id" value="<?php echo htmlspecialchars($values['veryfi_client_id'], ENT_QUOTES, 'UTF-8'); ?>" />
          </label>
          <label>
            Client Secret
            <input type="text" name="veryfi_client_secret" value="<?php echo htmlspecialchars($values['veryfi_client_secret'], ENT_QUOTES, 'UTF-8'); ?>" />
          </label>
          <label>
            Username
            <input type="text" name="veryfi_username" value="<?php echo htmlspecialchars($values['veryfi_username'], ENT_QUOTES, 'UTF-8'); ?>" />
          </label>
          <label>
            API Key
            <input type="text" name="veryfi_api_key" value="<?php echo htmlspecialchars($values['veryfi_api_key'], ENT_QUOTES, 'UTF-8'); ?>" />
          </label>
          <p class="hint">
            Learn more at <a href="https://www.veryfi.com/" target="_blank" rel="noopener">veryfi.com</a>.
            Leave blank to use local OCR only. You can edit `config/config.local.php` later.
          </p>
        </div>
      </details>

      <button class="btn" type="submit">Complete setup</button>
    </form>
    <script>
      (function () {
        const details = document.getElementById("mysqlDetails");
        if (!details) return;
        const radios = Array.from(document.querySelectorAll('input[name="storage_mode"]'));
        const update = () => {
          const selected = radios.find((radio) => radio.checked);
          const isMysql = selected && selected.value === "mysql";
          details.open = Boolean(isMysql);
          details.hidden = !isMysql;
        };
        radios.forEach((radio) => {
          radio.addEventListener("change", update);
        });
        update();
      })();
    </script>
  </body>
</html>
