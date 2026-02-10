# Receipt Keeper

A lightweight, self-hosted receipt logger with OCR, bulk upload, and CSV export.

## Features
- Capture receipts from camera or photo library
- Single upload supports PDFs (bulk is images only)
- Auto OCR via Veryfi (local fallback available)
- Bulk upload with auto-filled OCR fields
- Business purpose required on every receipt
- Category required on every receipt
- Year filtering, pagination, CSV export
- Simple password-protected access
- SQLite/MySQL/JSON storage (auto-fallback available)

## Requirements
- PHP 8.0+ (tested on 8.x)
- `pdo_sqlite` enabled (optional; falls back to JSON if unavailable)
- `pdo_mysql` enabled (optional; needed for MySQL storage)
- Web server (Apache / Nginx) or PHP built-in server for local testing

## Setup

### 1) Configure storage and permissions
Ensure the `data/` folder is writable by the web server:

```
data/
  uploads/
  password.json
  receipts.sqlite
```

### 2) Set admin password
Create `data/password.json` with a bcrypt hash:

```bash
php -r 'echo json_encode(["hash" => password_hash("YOUR_PASSWORD", PASSWORD_DEFAULT)], JSON_PRETTY_PRINT);' > data/password.json
```

Default username is `admin`.

### 3) (Optional) Configure Veryfi OCR
Create `config/config.local.php` (this file is git-ignored) with your Veryfi credentials:

```php
<?php
declare(strict_types=1);

define('VERYFI_CLIENT_ID', 'YOUR_CLIENT_ID');
define('VERYFI_USERNAME', 'YOUR_USERNAME');
define('VERYFI_API_KEY', 'YOUR_API_KEY');
define('VERYFI_CLIENT_SECRET', 'YOUR_CLIENT_SECRET');
```

If not configured, the app falls back to **local OCR** (Tesseract.js) in the browser.

To disable OCR entirely, add this to `config/config.local.php`:

```php
define('OCR_DEFAULT_ENABLED', false);
```

### 4) (Optional) Local PDF text extraction
PDF text extraction requires PDF.js. This repo includes the PDF.js files under `public/vendor/pdfjs/` by default. If you remove them, re-add these:

```
public/vendor/pdfjs/pdf.min.mjs
public/vendor/pdfjs/pdf.worker.mjs
```

Legacy `.mjs` and `.js` builds also work if you prefer them:

```
public/vendor/pdfjs/pdf.worker.min.mjs
```

```
public/vendor/pdfjs/pdf.min.js
public/vendor/pdfjs/pdf.worker.min.js
```

If those files are missing, PDF uploads will fall back to Veryfi (when available) or skip OCR with a notice.

### 5) (Optional) Configure MySQL storage
If you prefer MySQL, add these to `config/config.local.php` (or use the installer):

```php
define('STORAGE_MODE', 'mysql');
define('MYSQL_HOST', 'localhost');
define('MYSQL_PORT', 3306);
define('MYSQL_DATABASE', 'receipt_keeper');
define('MYSQL_USERNAME', 'db_user');
define('MYSQL_PASSWORD', 'db_pass');
```

To force JSON or SQLite:

```php
define('STORAGE_MODE', 'json');   // or 'sqlite'
```

## Run Locally

```bash
php -S 127.0.0.1:8000 -t public
```

Open: `http://127.0.0.1:8000`

## First-time Setup
If `data/password.json` is missing, the app will redirect you to `/install` to set the admin password and (optionally) Veryfi credentials.

The installer also lets you pick storage:
- JSON (default, always available)
- SQLite (if `pdo_sqlite` is enabled)
- MySQL (if `pdo_mysql` is enabled and credentials are provided)

## Project Structure
- `public/` web root (front controller, assets, PDF.js)
- `app/Controllers/` request handlers
- `app/Views/` templates
- `config/` app config + local secrets
- `data/` receipts, uploads, logs

## Production Notes
- Point your web root to `public/` (recommended).
- If you cannot change the web root (e.g., shared hosting), keep the root `.htaccess` so `/public` is used automatically.
- Ensure `data/` is writable and not publicly accessible.
- Keep `config/config.local.php` and `data/password.json` out of version control.
- If SQLite is not available on your host, the app will use `data/receipts.json` instead.

## OCR Notes
- OCR runs automatically when enabled (no UI toggle).
- **Local OCR** runs in the browser and does not send data to third parties.
- **Veryfi OCR** sends images to Veryfi. Make sure your API plan matches your usage.

## License
Private use by default. Add a license if you intend to distribute.
