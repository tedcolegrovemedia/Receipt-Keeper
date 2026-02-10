<?php
declare(strict_types=1);
require_once __DIR__ . '/guard.php';
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Receipt Logger</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Source+Sans+3:wght@400;500;600&display=swap"
      rel="stylesheet"
    />
    <link rel="stylesheet" href="styles.css" />
  </head>
  <body>
    <main class="app">
      <nav class="top-nav">
        <div class="nav-brand">
          <span class="nav-title">Receipt Logger</span>
          <span class="nav-subtitle">Private workspace</span>
        </div>
        <div class="nav-actions">
          <a class="btn ghost" href="change-password.php">Change password</a>
          <a class="btn ghost" href="logout.php">Sign out</a>
        </div>
      </nav>
      <header class="hero">
        <div>
          <p class="eyebrow">Receipt Logger</p>
          <h1>Capture receipts on the go.</h1>
          <p class="lede">
            Snap a photo or upload a receipt, then log the basics. Everything stays
            local in your browser.
          </p>
        </div>
        <div class="hero-card">
          <div class="stat">
            <span class="stat-label">Receipts saved</span>
            <span class="stat-value" id="receiptCount">0</span>
          </div>
          <div class="stat">
            <span class="stat-label">Total logged</span>
            <span class="stat-value" id="receiptTotal">$0.00</span>
          </div>
          <div class="stat">
            <span class="stat-label">Year</span>
            <select id="yearSelect" class="year-select"></select>
          </div>
        </div>
      </header>

      <section class="panel">
        <div class="panel-header">
          <h2>New Receipt</h2>
          <p>Use your phone camera or upload an existing image.</p>
        </div>

        <div class="capture-grid">
          <label class="file-drop" id="singleDrop" for="receiptImage">
            <input
              id="receiptImage"
              type="file"
              accept="image/*"
            />
            <span class="file-title">Add receipt image</span>
            <span class="file-subtitle">Drag & drop, camera, or photo library</span>
          </label>

          <div class="preview">
            <div class="preview-frame" id="previewDrop">
              <img id="previewImage" alt="Receipt preview" />
              <div class="preview-placeholder" id="previewPlaceholder">
                No image yet
              </div>
            </div>
            <div class="preview-meta" id="previewMeta">Choose a photo to preview.</div>
          </div>
        </div>

        <div class="ocr-panel">
          <div class="ocr-header">
            <div>
              <h3>OCR (optional)</h3>
              <p>Extract text using OCR.</p>
            </div>
            <label class="toggle">
              <input type="checkbox" id="ocrToggle" />
              <span>Enable OCR</span>
            </label>
          </div>
          <label class="ocr-provider">
            OCR Provider
            <select id="ocrProvider">
              <option value="local">Local (Tesseract)</option>
              <option value="veryfi">Veryfi (Cloud)</option>
            </select>
          </label>
          <div class="ocr-status">
            <span class="badge" id="veryfiBadge">Veryfi: checking...</span>
          </div>
          <div class="ocr-actions">
            <button class="btn" id="runOcr" type="button" disabled>Run OCR</button>
            <button class="btn ghost" id="applyOcr" type="button" disabled>
              Apply OCR Suggestions
            </button>
          </div>
          <div class="ocr-progress" id="ocrProgress"></div>
          <textarea
            id="ocrText"
            rows="4"
            placeholder="OCR text will appear here..."
            readonly
          ></textarea>
        </div>

        <form id="receiptForm" class="form-grid">
          <label>
            Date
            <input type="date" id="receiptDate" required />
          </label>
          <label>
            Vendor
            <input type="text" id="receiptVendor" placeholder="Coffee shop" required />
          </label>
          <label>
            Location
            <input type="text" id="receiptLocation" placeholder="City, State" />
          </label>
          <label>
            Total Spent
            <input
              type="number"
              id="receiptTotalInput"
              placeholder="0.00"
              min="0"
              step="0.01"
              required
            />
          </label>
          <button class="btn primary" id="saveReceipt" type="submit">Save Receipt</button>
          <button class="btn ghost" id="resetForm" type="button">Reset</button>
        </form>

        <div class="bulk-upload">
          <div class="bulk-header">
            <div>
              <h3>Bulk Upload</h3>
              <p>Add multiple receipt images and save them in one go.</p>
            </div>
            <label class="file-drop small" id="bulkDrop" for="bulkReceiptImages">
              <input id="bulkReceiptImages" type="file" accept="image/*" multiple />
              <span class="file-title">Add multiple images</span>
              <span class="file-subtitle">Drag & drop or select from your library</span>
            </label>
          </div>

          <div class="bulk-actions">
            <button class="btn primary" id="bulkSaveAll" type="button" disabled>
              Save All
            </button>
            <button class="btn ghost" id="bulkClear" type="button" disabled>
              Clear Queue
            </button>
            <div class="bulk-status" id="bulkStatus"></div>
          </div>

          <div id="bulkList" class="bulk-list"></div>
        </div>
      </section>

      <section class="panel">
        <div class="panel-header">
          <div>
            <h2>Receipts</h2>
            <p>Click a row to view or edit receipts.</p>
          </div>
          <div class="panel-actions">
            <input
              id="searchInput"
              type="search"
              placeholder="Search vendor or location"
            />
            <a class="btn ghost" href="#years">Years</a>
            <button class="btn ghost" id="exportCsv" type="button">Export CSV</button>
          </div>
        </div>

        <div class="years-bar" id="years">
          <span class="years-label">Years</span>
          <div id="yearFilters" class="years-list"></div>
        </div>

        <div class="receipt-toolbar">
          <label class="select-all">
            <input type="checkbox" id="selectAll" />
            Select page
          </label>
          <button class="btn ghost" id="deleteSelected" type="button" disabled>
            Delete selected
          </button>
          <div class="toolbar-status" id="selectionStatus"></div>
        </div>

        <div class="receipts-table" id="receiptsTable">
          <table>
            <thead>
              <tr>
                <th class="checkbox-col"></th>
                <th>Date</th>
                <th>Vendor</th>
                <th class="amount">Total</th>
              </tr>
            </thead>
            <tbody id="receiptTableBody"></tbody>
          </table>
        </div>
        <div id="emptyState" class="empty-state">No receipts logged yet.</div>
        <div class="pagination">
          <button class="btn ghost" id="prevPage" type="button">Prev</button>
          <span class="page-info" id="pageInfo">Page 1 of 1</span>
          <button class="btn ghost" id="nextPage" type="button">Next</button>
        </div>
      </section>
    </main>

    <div class="modal" id="imageModal" aria-hidden="true">
      <div class="modal-backdrop" id="modalBackdrop"></div>
      <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
        <div class="modal-header">
          <h3 id="modalTitle">Receipt</h3>
          <button class="btn ghost" id="modalClose" type="button">Close</button>
        </div>
        <img id="modalImage" alt="Receipt image" />
        <div class="modal-form">
          <label>
            Date
            <input type="date" id="modalDate" />
          </label>
          <label>
            Vendor
            <input type="text" id="modalVendor" />
          </label>
          <label>
            Location
            <input type="text" id="modalLocation" />
          </label>
          <label>
            Total
            <input type="number" id="modalTotal" min="0" step="0.01" />
          </label>
        </div>
        <div class="modal-meta" id="modalMeta"></div>
        <div class="modal-status" id="modalStatus"></div>
        <div class="modal-actions">
          <button class="btn primary" id="modalSave" type="button">Save changes</button>
          <button class="btn ghost" id="modalDelete" type="button">Delete</button>
        </div>
      </div>
    </div>

    <script src="app.js"></script>
  </body>
</html>
