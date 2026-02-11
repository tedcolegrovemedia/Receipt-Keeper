<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Admin Diagnostics</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Source+Sans+3:wght@400;500;600&display=swap"
      rel="stylesheet"
    />
    <link rel="stylesheet" href="<?php echo htmlspecialchars(asset_path('assets/styles.css'), ENT_QUOTES, 'UTF-8'); ?>" />
  </head>
  <body data-base="<?php echo htmlspecialchars(base_path(), ENT_QUOTES, 'UTF-8'); ?>">
    <main class="app">
      <nav class="top-nav">
        <div class="nav-brand"></div>
        <div class="nav-actions">
          <button class="btn ghost small" id="themeToggle" type="button" aria-pressed="false">
            Dark mode
          </button>
          <a class="btn ghost" href="<?php echo htmlspecialchars(url_path(''), ENT_QUOTES, 'UTF-8'); ?>">Receipts</a>
          <a class="btn ghost" href="<?php echo htmlspecialchars(url_path('change-password'), ENT_QUOTES, 'UTF-8'); ?>">
            Change password
          </a>
          <a class="btn ghost" href="<?php echo htmlspecialchars(url_path('logout'), ENT_QUOTES, 'UTF-8'); ?>">
            Sign out
          </a>
        </div>
      </nav>

      <header class="hero">
        <div>
          <p class="eyebrow">Admin</p>
          <h1>Installation Diagnostics</h1>
          <p class="lede">Quick checks for storage, OCR, and recovery setup.</p>
        </div>
        <div class="hero-card">
          <div class="stat">
            <span class="stat-label">Passed</span>
            <span class="stat-value"><?php echo (int) ($summary['pass'] ?? 0); ?></span>
          </div>
          <div class="stat">
            <span class="stat-label">Warnings</span>
            <span class="stat-value"><?php echo (int) ($summary['warning'] ?? 0); ?></span>
          </div>
          <div class="stat">
            <span class="stat-label">Failures</span>
            <span class="stat-value"><?php echo (int) ($summary['fail'] ?? 0); ?></span>
          </div>
          <a class="btn ghost" href="<?php echo htmlspecialchars(url_path('admin'), ENT_QUOTES, 'UTF-8'); ?>">Run checks again</a>
        </div>
      </header>

      <?php if (!empty($success)): ?>
      <section class="panel">
        <div class="admin-flash admin-flash-success"><?php echo htmlspecialchars((string) $success, ENT_QUOTES, 'UTF-8'); ?></div>
      </section>
      <?php endif; ?>

      <?php if (!empty($error)): ?>
      <section class="panel">
        <div class="admin-flash admin-flash-error"><?php echo htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8'); ?></div>
      </section>
      <?php endif; ?>

      <section class="panel">
        <div class="panel-header">
          <div>
            <h2>Admin Tools</h2>
            <p>Manual controls for OCR quota, export/import, and routing.</p>
          </div>
        </div>

        <div class="admin-tools-grid">
          <form class="admin-tool" method="post" action="">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
            <input type="hidden" name="admin_action" value="update_ocr_remaining" />
            <h3>OCR Remaining</h3>
            <p>Set the remaining Veryfi OCR requests for the current month.</p>
            <label>
              Remaining (0 - <?php echo (int) ($ocrLimit ?? 0); ?>)
              <input
                type="number"
                name="ocr_remaining"
                min="0"
                max="<?php echo (int) ($ocrLimit ?? 0); ?>"
                value="<?php echo htmlspecialchars((string) ($ocrRemainingValue ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                <?php echo ((int) ($ocrLimit ?? 0)) > 0 ? '' : 'disabled'; ?>
              />
            </label>
            <button class="btn primary" type="submit" <?php echo ((int) ($ocrLimit ?? 0)) > 0 ? '' : 'disabled'; ?>>
              Save OCR Remaining
            </button>
          </form>

          <form class="admin-tool" method="post" action="">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
            <input type="hidden" name="admin_action" value="export_bundle" />
            <h3>Full Export</h3>
            <p>Download a full backup zip with receipts JSON, receipts CSV, and uploaded receipt files.</p>
            <button class="btn primary" type="submit">Download Export Zip</button>
          </form>

          <form class="admin-tool" method="post" action="" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
            <input type="hidden" name="admin_action" value="import_bundle" />
            <h3>Import Export Zip</h3>
            <p>Restore receipts and receipt files from a previous full export.</p>
            <label>
              Export zip file
              <input type="file" name="import_bundle" accept=".zip,application/zip" required />
            </label>
            <label class="admin-checkbox">
              <input type="checkbox" name="import_replace" value="1" checked />
              Replace existing receipts and files before import
            </label>
            <button class="btn primary" type="submit">Import Backup Zip</button>
          </form>

          <form class="admin-tool" method="post" action="">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
            <input type="hidden" name="admin_action" value="update_base_path" />
            <h3>Base Path</h3>
            <p>Set an explicit URL subfolder (for example <code>/writeoff</code>). Leave blank for auto-detect.</p>
            <label>
              App base path
              <input
                type="text"
                name="app_base_path"
                placeholder="/writeoff"
                value="<?php echo htmlspecialchars((string) ($appBasePathValue ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
              />
            </label>
            <button class="btn primary" type="submit">Save Base Path</button>
          </form>
        </div>
      </section>

      <section class="panel">
        <div class="panel-header">
          <div>
            <h2>Checks</h2>
            <p>Required items should all pass before production use.</p>
          </div>
        </div>
        <div class="receipts-table diagnostics-table">
          <table>
            <thead>
              <tr>
                <th>Check</th>
                <th>Type</th>
                <th>Status</th>
                <th>Details</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach (($checks ?? []) as $check): ?>
              <tr class="diag-row-<?php echo htmlspecialchars((string) ($check['status'] ?? 'pass'), ENT_QUOTES, 'UTF-8'); ?>">
                <td><?php echo htmlspecialchars((string) ($check['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo !empty($check['required']) ? 'Required' : 'Optional'; ?></td>
                <td>
                  <?php
                    $status = (string) ($check['status'] ?? 'pass');
                    $label = 'Pass';
                    if ($status === 'warning') {
                        $label = 'Warning';
                    } elseif ($status === 'fail') {
                        $label = 'Fail';
                    }
                  ?>
                  <span class="diag-badge diag-<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                  </span>
                </td>
                <td><?php echo htmlspecialchars((string) ($check['detail'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>

      <section class="panel">
        <div class="panel-header">
          <div>
            <h2>Runtime</h2>
            <p>Current environment details.</p>
          </div>
        </div>
        <div class="runtime-grid">
          <?php foreach (($runtime ?? []) as $key => $value): ?>
          <div class="runtime-item">
            <div class="runtime-label"><?php echo htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="runtime-value"><?php echo htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </section>
    </main>
    <script src="<?php echo htmlspecialchars(asset_path('assets/theme.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
  </body>
</html>
