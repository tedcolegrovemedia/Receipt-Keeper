# Receipt Keeper

A lightweight, self-hosted receipt logger with OCR, bulk upload, and CSV export.

## Features
- Capture receipts from camera or photo library
- Auto OCR via Veryfi (local fallback available)
- Bulk upload with auto-filled OCR fields
- Year filtering, pagination, CSV export
- Simple password-protected access
- SQLite storage

## Requirements
- PHP 8.0+ (tested on 8.x)
- `pdo_sqlite` enabled
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
Create `config.local.php` (this file is git-ignored) with your Veryfi credentials:

```php
<?php
declare(strict_types=1);

define('VERYFI_CLIENT_ID', 'YOUR_CLIENT_ID');
define('VERYFI_USERNAME', 'YOUR_USERNAME');
define('VERYFI_API_KEY', 'YOUR_API_KEY');
define('VERYFI_CLIENT_SECRET', 'YOUR_CLIENT_SECRET');
```

If not configured, the app falls back to **local OCR** (Tesseract.js) in the browser.

To disable OCR entirely, add this to `config.local.php`:

```php
define('OCR_DEFAULT_ENABLED', false);
```

## Run Locally

```bash
php -S 127.0.0.1:8000 -t .
```

Open: `http://127.0.0.1:8000`

## Production Notes
- Place the app in a subfolder and protect with HTTPS if possible.
- Ensure `data/` is writable and not publicly accessible.
- Keep `config.local.php` and `data/password.json` out of version control.
- If SQLite is not available on your host, the API will return a storage error.

## OCR Notes
- OCR runs automatically when enabled (no UI toggle).
- **Local OCR** runs in the browser and does not send data to third parties.
- **Veryfi OCR** sends images to Veryfi. Make sure your API plan matches your usage.

## License
Private use by default. Add a license if you intend to distribute.
