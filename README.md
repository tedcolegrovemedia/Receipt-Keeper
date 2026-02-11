# Receipt Keeper

Self-hosted receipt logger for tax/business expense tracking.

It supports:
- camera/photo library uploads from phone
- desktop drag/drop uploads
- single PDF receipt upload
- automatic OCR (Veryfi + local fallback)
- required fields for category and business purpose
- edit/delete + bulk delete
- year filters, pagination, and CSV export
- diagnostics/admin tools (mail, OCR quota, import/export, base path)

---

## Core Features

- Authentication:
  - Shared login (`admin` username + hashed password).
  - Forgot password with a 4-digit reset PIN + new password.
  - Rate-limited login attempts.

- Receipt capture:
  - Single upload: images + PDFs.
  - Bulk upload: images only.
  - Uploaded images are converted to black/white before saving (OCR-friendly).
  - Desktop drag-and-drop supported.
  - Image preview with zoom levels and panning.

- OCR:
  - Default path: Veryfi (when configured and quota remains).
  - Fallback: local OCR (Tesseract.js in browser).
  - PDF path: local PDF text extraction via PDF.js first, then Veryfi fallback.
  - OCR provider can be toggled in UI (`Use Veryfi` / `Use Local`).
  - OCR-filled inputs are highlighted briefly.
  - Category can be inferred from OCR text + vendor heuristics.
  - Vendor memory learns from corrected/confirmed entries.

- Receipt management:
  - Required fields: `Date`, `Vendor`, `Category`, `Business Purpose`, `Total`.
  - Optional field: `Location`.
  - Table view: Date, Vendor, Category, Total.
  - Edit in modal.
  - Single delete + page-level bulk delete.
  - Year filtering (`All` or specific year) and pagination.
  - CSV export for selected year or all years.
  - CSV includes total sum row and USD formatting.

- Admin tools:
  - Runtime/installation diagnostics.
  - Storage checks (JSON/SQLite/MySQL).
  - OCR remaining counter editor (Veryfi quota).
  - Reset PIN editor for forgot-password flow.
  - Mail transport settings (`mail()` or SMTP).
  - Test email sender.
  - Full backup export ZIP.
  - Backup import ZIP.
  - Base path override for subfolder deployments.

- UI:
  - Light/dark mode toggle with persistence in localStorage.
  - Category reference table (accordion).
  - Footer disclaimer/copyright.

---

## Tech Stack

- Backend: plain PHP (MVC-style structure)
- Frontend: vanilla JS + CSS (no framework)
- OCR:
  - Veryfi API (server-side call via cURL)
  - Local OCR via Tesseract.js (browser)
  - PDF text extraction via PDF.js (browser)
- Storage:
  - JSON (always available)
  - SQLite (PDO SQLite)
  - MySQL (PDO MySQL)

---

## Project Layout

```text
app/
  Controllers/
  Views/
config/
  bootstrap.php
  config.php
  config.local.php      # local overrides/secrets (gitignored)
data/                   # runtime data (gitignored except .htaccess)
  uploads/
  receipts.json
  receipts.sqlite
  password.json
  vendor-memory.json
  veryfi-usage.json
  api-error.log
  client-error.log
  mail-debug.log
public/
  index.php
  .htaccess
  assets/
    app.js
    ocr-parser.js
    styles.css
    theme.js
  vendor/pdfjs/
    pdf.min.mjs
    pdf.worker.mjs
.htaccess               # root rewrite helper for shared hosting
index.php               # root fallback -> public/index.php
```

---

## Requirements

- PHP `7.4+` (8.x recommended)
- Web server:
  - Apache (tested path)
  - PHP built-in server for local dev
- Writable `data/` and `data/uploads/` directories

Optional PHP extensions by feature:
- `pdo_sqlite` for SQLite storage
- `pdo_mysql` for MySQL storage
- `curl` for Veryfi OCR
- `zip` (`ZipArchive`) for full backup export/import
- `stream_socket_client` for SMTP mail transport

Third-party services (optional):
- Veryfi OCR

---

## Quick Start (Local)

1. Go to project root.
2. Ensure writable directories exist:

```bash
mkdir -p data/uploads data/sessions
chmod -R 775 data
```

3. Start server (either option):

```bash
# Option A: serve project root (uses root index.php fallback)
php -S 127.0.0.1:8000

# Option B: serve public directly
php -S 127.0.0.1:8000 -t public
```

4. Open:

```text
http://127.0.0.1:8000
```

5. First run redirects to `/install` if `data/password.json` is missing.

---

## Installer Flow

Installer appears when no password file exists.

It sets:
- admin password hash
- 4-digit reset PIN for forgot-password
- storage mode (`json`, `sqlite`, `mysql`)
- optional Veryfi credentials

If MySQL is selected, installer validates DB connection.

---

## Configuration

### 1) `config/config.local.php` (recommended for overrides)

This file is gitignored and should hold local secrets/settings.

Common defines:

```php
<?php
declare(strict_types=1);

define('OCR_DEFAULT_ENABLED', true);
define('APP_BASE_PATH', '/writeoff'); // optional subfolder override

// Storage
define('STORAGE_MODE', 'auto'); // auto|json|sqlite|mysql
define('MYSQL_HOST', '');
define('MYSQL_PORT', 3306);
define('MYSQL_DATABASE', '');
define('MYSQL_USERNAME', '');
define('MYSQL_PASSWORD', '');

// Veryfi OCR
define('VERYFI_CLIENT_ID', '');
define('VERYFI_CLIENT_SECRET', '');
define('VERYFI_USERNAME', '');
define('VERYFI_API_KEY', '');

// Mail settings (editable in Admin)
define('MAIL_TRANSPORT', 'mail'); // mail|smtp
define('MAIL_FROM_EMAIL', '');
define('MAIL_FROM_NAME', 'Receipt Keeper');
define('SMTP_HOST', '');
define('SMTP_PORT', 587);
define('SMTP_ENCRYPTION', 'tls'); // none|tls|ssl
define('SMTP_USERNAME', '');
define('SMTP_PASSWORD', '');
define('SMTP_TIMEOUT', 20);
```

### 2) Runtime files in `data/`

- `password.json` stores:
  - bcrypt password hash
  - reset PIN hash
- `receipts.json` or `receipts.sqlite` depending on active storage
- `uploads/` stores processed receipt images/PDFs

---

## Storage Behavior

`STORAGE_MODE=auto` resolution order:
1. MySQL (if driver + config + connection valid)
2. SQLite (if available/valid)
3. JSON fallback

Explicit modes:
- `json`: always JSON
- `sqlite`: SQLite else JSON fallback
- `mysql`: MySQL else JSON fallback

The active driver is shown in Admin diagnostics.

---

## OCR Behavior

### Single image upload
- Uses Veryfi when configured and quota remains.
- Falls back to local OCR if Veryfi unavailable/exhausted.

### Single PDF upload
- Tries local text extraction via PDF.js first.
- If PDF.js unavailable or extraction fails, falls back to Veryfi (if available).

### Bulk upload
- Images only (PDF blocked in bulk).
- OCR attempts to prefill each queue item.

### OCR provider status
- UI displays current OCR mode/status.
- "OCR Remaining" is shown only when Veryfi path is active.

### Local OCR dependencies
- Tesseract.js loaded from CDN:
  - `https://unpkg.com/tesseract.js@5.0.5/dist/tesseract.min.js`
- PDF.js files must exist in:
  - `public/vendor/pdfjs/pdf.min.mjs`
  - `public/vendor/pdfjs/pdf.worker.mjs`

---

## Apache/Subfolder Deployment

Recommended:
- Point web root directly to `public/`.

If you cannot change web root (shared hosting):
- keep root `index.php` and root `.htaccess`
- ensure rewrite rules route traffic into `public/`

Important:
- update `RewriteBase` values in:
  - `/.htaccess`
  - `/public/.htaccess`
- match your subfolder (example: `/writeoff/`)

If deploying without HTTPS yet:
- `/public/.htaccess` currently forces HTTPS.
- remove/comment that redirect rule if needed temporarily.

Also ensure:
- `data/` is writable by PHP
- `data/.htaccess` remains in place (deny direct access)

---

## Admin Panel Reference

`/admin` includes:
- install/runtime checks
- storage diagnostics and connectivity probes
- OCR remaining counter editor
- reset PIN editor
- mail transport config (`mail()` / SMTP)
- email test sender
- full export ZIP generator
- import ZIP restore
- base path updater

Full export ZIP contains:
- `receipts/receipts.json`
- `receipts/receipts.csv`
- `uploads/*`
- `meta/vendor-memory.json` (if present)
- `meta/veryfi-usage.json` (if present)
- `manifest.json`

---

## Logging & Troubleshooting

Logs written under `data/`:
- `api-error.log` (server/API exceptions)
- `client-error.log` (browser-side error reports)
- `mail-debug.log` (mail send attempts/results)

Common issues:
- Login loops after sign-in:
  - ensure `data/` is writable (session files are stored in `data/sessions`)
  - verify base path/subfolder config
- "Session expired":
  - clear cookies and reload
  - verify consistent host/path (especially behind proxy/subfolder)
- OCR unavailable:
  - check Veryfi credentials in `config.local.php` or installer/admin
  - check Veryfi quota remaining
- PDF OCR unavailable:
  - verify PDF.js files exist in `public/vendor/pdfjs/`
- Forgot-password reset not working:
  - verify a reset PIN is configured in Admin
  - ensure PIN is exactly 4 digits

---

## Security Notes

- Keep these out of git:
  - `config/config.local.php`
  - `data/*` runtime files
- Keep `data/.htaccess` in place.
- Use HTTPS in production.
- Rotate shared password periodically.
- Use SMTP with authenticated credentials where possible.

---

## Customization Notes

- Expense categories and explanations are defined in:
  - `public/assets/app.js` (`EXPENSE_CATEGORIES`)
- OCR parsing heuristics live in:
  - `public/assets/ocr-parser.js`
- Theme behavior lives in:
  - `public/assets/theme.js`

---

## Disclaimer

© 2026 Ted Colegrove Media LLC

Use at your own risk. The author makes no guarantees regarding functionality or security and is not responsible for vulnerabilities, exploits, or breaches.
