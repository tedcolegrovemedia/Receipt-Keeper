const DB_NAME = "receipt_logger";
const STORE_NAME = "receipts";
const DB_VERSION = 1;
const PAGE_SIZE = 10;
const OCR_MAX_DIM = 1600;
const PDFJS_SOURCES = [
  {
    script: "vendor/pdfjs/pdf.min.mjs",
    worker: "vendor/pdfjs/pdf.worker.min.mjs",
  },
  {
    script: "vendor/pdfjs/pdf.min.js",
    worker: "vendor/pdfjs/pdf.worker.min.js",
  },
];

const CURRENT_YEAR = new Date().getFullYear().toString();
const OCR_OVERRIDE_KEY = "receipt_ocr_override";

const EXPENSE_CATEGORIES = [
  {
    value: "Equipment & Gear",
    description:
      "Includes cameras, lenses, tripods, lighting, audio gear, computers, monitors, hard drives, memory cards, and other physical tools used to produce work. Also includes repairs, maintenance, and accessories related to this equipment. Higher-cost items may be depreciated over multiple years instead of expensed all at once, depending on tax treatment.",
  },
  {
    value: "Software & Subscriptions",
    description:
      "Covers software and digital services required to run the business, such as photo and video editing tools, design software, cloud storage, website hosting, domain registrations, email services, AI tools, and project management platforms. Both monthly and annual subscriptions are included.",
  },
  {
    value: "Vehicle & Travel",
    description:
      "Includes business-related mileage or actual vehicle expenses, fuel, maintenance, parking, tolls, flights, hotels, rental cars, and other travel costs incurred for client work, meetings, or shoots. Only the business portion of mixed-use travel should be included, and mileage logs should be maintained if applicable.",
  },
  {
    value: "Home Office / Workspace",
    description:
      "Applies to expenses related to a dedicated workspace, whether a home office or rented workspace. Includes a portion of rent or mortgage interest, utilities, internet, insurance, and maintenance based on the percentage of the home used exclusively for business. Also includes coworking spaces or day-pass workspaces.",
  },
  {
    value: "Meals & Entertainment",
    description:
      "Includes business meals with clients, collaborators, or for business planning purposes. The record should include who the meal was with and the business purpose discussed. Most meals are only partially deductible, so clear documentation is important.",
  },
  {
    value: "Marketing & Advertising",
    description:
      "Covers costs used to promote the business, including website development, hosting, printing, advertising on social platforms or search engines, business cards, promotional materials, branding work, and sponsored posts or campaigns.",
  },
  {
    value: "Professional Services",
    description:
      "Includes fees paid to professionals who support the business, such as accountants, bookkeepers, lawyers, consultants, coaches, and contractors like editors, designers, or second shooters. Also includes fees for compliance, filings, and professional advice.",
  },
  {
    value: "Income Processing Fees",
    description:
      "Includes transaction and platform fees deducted by payment processors such as Stripe, PayPal, Square, or online marketplaces. These are often not invoiced separately but reduce gross income and should be tracked to ensure accurate net income reporting.",
  },
  {
    value: "Other Business Expenses",
    description:
      "Catches legitimate business costs that do not fit cleanly into other categories, such as office supplies, shipping, postage, education, workshops, certifications, insurance premiums, and minor tools. Clear notes should explain the business purpose for each item.",
  },
];

const state = {
  currentFile: null,
  ocrText: "",
  ocrSuggestions: null,
  ocrLoaded: false,
  pdfLoaded: false,
  previewMetaBase: "Choose a photo to preview.",
  ocrStatusText: "",
  currentYear: CURRENT_YEAR,
  currentPage: 1,
  selectedIds: new Set(),
  visibleIds: [],
  processToken: 0,
  modalUrl: null,
  modalReceipt: null,
  pdfObjectUrls: [],
};

function createZoomState() {
  return {
    mode: "none",
    scale: 1,
    x: 0,
    y: 0,
    baseWidth: 0,
    baseHeight: 0,
    minScale: 1,
    maxScale: 3,
    zoomSteps: [2, 3],
    isPinching: false,
    isPanning: false,
    startDist: 0,
    startScale: 1,
    lastX: 0,
    lastY: 0,
  };
}

const previewZoom = createZoomState();

const storage = {
  mode: "local",
  veryfiAvailable: false,
  veryfiLimit: null,
  veryfiRemaining: null,
  ocrDefaultEnabled: false,
  ocrOverride: "auto",
  pdfJsAvailable: null,
};

const bulkState = {
  items: [],
};

const ocrHighlightTimers = new WeakMap();

const elements = {
  receiptImage: document.getElementById("receiptImage"),
  previewImage: document.getElementById("previewImage"),
  previewPdf: document.getElementById("previewPdf"),
  previewPlaceholder: document.getElementById("previewPlaceholder"),
  previewHint: document.getElementById("previewHint"),
  ocrStatus: document.getElementById("ocrStatus"),
  ocrProgress: document.getElementById("ocrProgress"),
  ocrProgressFill: document.getElementById("ocrProgressFill"),
  ocrTypeToggle: document.getElementById("ocrTypeToggle"),
  singleDrop: document.getElementById("singleDrop"),
  previewDrop: document.getElementById("previewDrop"),
  receiptForm: document.getElementById("receiptForm"),
  receiptDate: document.getElementById("receiptDate"),
  receiptVendor: document.getElementById("receiptVendor"),
  receiptLocation: document.getElementById("receiptLocation"),
  receiptCategory: document.getElementById("receiptCategory"),
  receiptPurpose: document.getElementById("receiptPurpose"),
  receiptTotalInput: document.getElementById("receiptTotalInput"),
  saveReceipt: document.getElementById("saveReceipt"),
  resetForm: document.getElementById("resetForm"),
  receiptsTable: document.getElementById("receiptsTable"),
  receiptTableBody: document.getElementById("receiptTableBody"),
  emptyState: document.getElementById("emptyState"),
  receiptCount: document.getElementById("receiptCount"),
  receiptTotal: document.getElementById("receiptTotal"),
  ocrRemaining: document.getElementById("ocrRemaining"),
  searchInput: document.getElementById("searchInput"),
  exportCsv: document.getElementById("exportCsv"),
  yearFilters: document.getElementById("yearFilters"),
  yearSelect: document.getElementById("yearSelect"),
  selectAll: document.getElementById("selectAll"),
  deleteSelected: document.getElementById("deleteSelected"),
  selectionStatus: document.getElementById("selectionStatus"),
  prevPage: document.getElementById("prevPage"),
  nextPage: document.getElementById("nextPage"),
  pageInfo: document.getElementById("pageInfo"),
  imageModal: document.getElementById("imageModal"),
  modalBackdrop: document.getElementById("modalBackdrop"),
  modalClose: document.getElementById("modalClose"),
  modalImage: document.getElementById("modalImage"),
  modalDate: document.getElementById("modalDate"),
  modalVendor: document.getElementById("modalVendor"),
  modalLocation: document.getElementById("modalLocation"),
  modalCategory: document.getElementById("modalCategory"),
  modalPurpose: document.getElementById("modalPurpose"),
  modalTotal: document.getElementById("modalTotal"),
  modalMeta: document.getElementById("modalMeta"),
  modalStatus: document.getElementById("modalStatus"),
  modalSave: document.getElementById("modalSave"),
  modalDelete: document.getElementById("modalDelete"),
  bulkReceiptImages: document.getElementById("bulkReceiptImages"),
  bulkDrop: document.getElementById("bulkDrop"),
  bulkList: document.getElementById("bulkList"),
  bulkSaveAll: document.getElementById("bulkSaveAll"),
  bulkClear: document.getElementById("bulkClear"),
  bulkStatus: document.getElementById("bulkStatus"),
  toggleCategories: document.getElementById("toggleCategories"),
  categoryGuide: document.getElementById("categoryGuide"),
  categoryTableBody: document.getElementById("categoryTableBody"),
};

const todayISO = () => new Date().toISOString().slice(0, 10);

function formatCurrency(value) {
  if (Number.isNaN(value)) return "$0.00";
  return new Intl.NumberFormat("en-US", {
    style: "currency",
    currency: "USD",
  }).format(value);
}

function formatSize(bytes) {
  if (!bytes) return "";
  const sizes = ["B", "KB", "MB", "GB"];
  const index = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), sizes.length - 1);
  const value = bytes / Math.pow(1024, index);
  return `${value.toFixed(value < 10 ? 1 : 0)} ${sizes[index]}`;
}

function formatShortDate(value) {
  if (!value) return "—";
  const match = String(value).match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (match) {
    return `${match[2]}/${match[3]}/${match[1].slice(2)}`;
  }
  const parsed = new Date(value);
  if (!Number.isNaN(parsed.getTime())) {
    const month = String(parsed.getMonth() + 1).padStart(2, "0");
    const day = String(parsed.getDate()).padStart(2, "0");
    const year = String(parsed.getFullYear()).slice(2);
    return `${month}/${day}/${year}`;
  }
  return String(value);
}

function populateCategorySelect(select, value = "") {
  if (!select) return;
  select.innerHTML = "";
  const placeholder = document.createElement("option");
  placeholder.value = "";
  placeholder.textContent = "Select category";
  placeholder.disabled = true;
  placeholder.selected = !value;
  select.append(placeholder);

  EXPENSE_CATEGORIES.forEach((category) => {
    const option = document.createElement("option");
    option.value = category.value;
    option.textContent = category.value;
    select.append(option);
  });

  if (value) {
    select.value = value;
  }
}

function renderCategoryGuide() {
  if (!elements.categoryTableBody) return;
  elements.categoryTableBody.innerHTML = "";
  EXPENSE_CATEGORIES.forEach((category) => {
    const row = document.createElement("tr");
    const nameCell = document.createElement("td");
    nameCell.textContent = category.value;
    const descCell = document.createElement("td");
    descCell.textContent = category.description;
    row.append(nameCell, descCell);
    elements.categoryTableBody.append(row);
  });
}

function setCategoryGuideOpen(isOpen) {
  if (!elements.categoryGuide || !elements.toggleCategories) return;
  elements.categoryGuide.hidden = !isOpen;
  elements.toggleCategories.textContent = isOpen ? "Hide categories" : "Show categories";
  elements.toggleCategories.setAttribute("aria-expanded", isOpen ? "true" : "false");
}

function applyOcrHighlight(input, durationMs = 5000) {
  if (!input) return;
  input.classList.add("ocr-filled");
  const existing = ocrHighlightTimers.get(input);
  if (existing) {
    clearTimeout(existing);
  }
  const timeout = window.setTimeout(() => {
    input.classList.remove("ocr-filled");
    ocrHighlightTimers.delete(input);
  }, Math.max(0, durationMs));
  ocrHighlightTimers.set(input, timeout);
}

function clearOcrHighlight(input) {
  if (!input) return;
  input.classList.remove("ocr-filled");
  const existing = ocrHighlightTimers.get(input);
  if (existing) {
    clearTimeout(existing);
    ocrHighlightTimers.delete(input);
  }
}

function isImageFile(file) {
  if (!file) return false;
  if (file.type && file.type.startsWith("image/")) return true;
  const ext = file.name ? file.name.split(".").pop().toLowerCase() : "";
  return ["jpg", "jpeg", "png", "webp", "heic", "heif", "gif", "tiff"].includes(ext);
}

function isPdfFile(file) {
  if (!file) return false;
  if (file.type === "application/pdf") return true;
  const ext = file.name ? file.name.split(".").pop().toLowerCase() : "";
  return ext === "pdf";
}

function pickFirstReceiptFile(files) {
  const list = Array.from(files || []);
  return list.find((file) => isImageFile(file) || isPdfFile(file)) || null;
}

function setupDropTarget(target, onFiles) {
  if (!target) return;

  const highlight = () => target.classList.add("drop-active");
  const unhighlight = () => target.classList.remove("drop-active");

  const stop = (event) => {
    event.preventDefault();
    event.stopPropagation();
  };

  target.addEventListener("dragenter", (event) => {
    stop(event);
    highlight();
  });

  target.addEventListener("dragover", (event) => {
    stop(event);
    highlight();
    if (event.dataTransfer) {
      event.dataTransfer.dropEffect = "copy";
    }
  });

  target.addEventListener("dragleave", (event) => {
    stop(event);
    if (event.relatedTarget && target.contains(event.relatedTarget)) return;
    unhighlight();
  });

  target.addEventListener("drop", (event) => {
    stop(event);
    unhighlight();
    const files = event.dataTransfer ? event.dataTransfer.files : null;
    if (files && files.length > 0) {
      void onFiles(files);
    }
  });
}

function loadImageFromFile(file) {
  return new Promise((resolve, reject) => {
    const img = new Image();
    const url = URL.createObjectURL(file);
    img.onload = () => {
      URL.revokeObjectURL(url);
      resolve(img);
    };
    img.onerror = () => {
      URL.revokeObjectURL(url);
      reject(new Error("Failed to load image."));
    };
    img.src = url;
  });
}

function canvasToBlob(canvas, type = "image/jpeg", quality = 0.85) {
  return new Promise((resolve, reject) => {
    canvas.toBlob((blob) => {
      if (!blob) {
        reject(new Error("Failed to encode image."));
        return;
      }
      resolve(blob);
    }, type, quality);
  });
}

async function preprocessForOcr(file) {
  const img = await loadImageFromFile(file);
  const scale = Math.min(1, OCR_MAX_DIM / Math.max(img.width, img.height));
  const width = Math.max(1, Math.round(img.width * scale));
  const height = Math.max(1, Math.round(img.height * scale));
  const canvas = document.createElement("canvas");
  canvas.width = width;
  canvas.height = height;
  const ctx = canvas.getContext("2d", { willReadFrequently: true });
  ctx.drawImage(img, 0, 0, width, height);

  const imageData = ctx.getImageData(0, 0, width, height);
  const data = imageData.data;
  for (let i = 0; i < data.length; i += 4) {
    const r = data[i];
    const g = data[i + 1];
    const b = data[i + 2];
    const gray = Math.round(0.299 * r + 0.587 * g + 0.114 * b);
    data[i] = gray;
    data[i + 1] = gray;
    data[i + 2] = gray;
    data[i + 3] = 255;
  }

  ctx.putImageData(imageData, 0, 0);
  return canvas;
}

async function buildBwFile(file) {
  const canvas = await preprocessForOcr(file);
  const blob = await canvasToBlob(canvas, "image/jpeg", 0.85);
  const base = file.name ? file.name.replace(/\.[^/.]+$/, "") : "receipt";
  const name = `${base}-bw.jpg`;
  return new File([blob], name, { type: blob.type || "image/jpeg", lastModified: file.lastModified || Date.now() });
}

function generateId() {
  if (crypto && crypto.randomUUID) return crypto.randomUUID();
  return `r_${Date.now()}_${Math.random().toString(16).slice(2)}`;
}

function getDb() {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, DB_VERSION);
    request.onupgradeneeded = () => {
      const db = request.result;
      if (!db.objectStoreNames.contains(STORE_NAME)) {
        db.createObjectStore(STORE_NAME, { keyPath: "id" });
      }
    };
    request.onerror = () => reject(request.error);
    request.onsuccess = () => resolve(request.result);
  });
}

async function initStorage() {
  if (window.location.protocol === "file:") {
    storage.mode = "local";
    storage.ocrDefaultEnabled = true;
    return;
  }
  try {
    const response = await fetch("api.php?action=ping", { credentials: "same-origin" });
    if (response.ok) {
      const data = await response.json();
      storage.mode = "server";
      storage.veryfiAvailable = Boolean(data.veryfi);
      storage.veryfiLimit = Number.isFinite(data.veryfiLimit) ? data.veryfiLimit : null;
      storage.veryfiRemaining = Number.isFinite(data.veryfiRemaining) ? data.veryfiRemaining : null;
      storage.ocrDefaultEnabled = Boolean(data.ocrDefaultEnabled);
      storage.pdfJsAvailable = Boolean(data.pdfJsAvailable);
    } else {
      storage.mode = "local";
      storage.ocrDefaultEnabled = true;
    }
  } catch (error) {
    storage.mode = "local";
    storage.ocrDefaultEnabled = true;
  }
}

async function apiRequest(action, options = {}) {
  const response = await fetch(`api.php?action=${encodeURIComponent(action)}`, {
    credentials: "same-origin",
    ...options,
  });
  const contentType = response.headers.get("content-type") || "";
  const text = await response.text();
  let data = null;
  if (contentType.includes("application/json")) {
    try {
      data = text ? JSON.parse(text) : null;
    } catch (error) {
      throw new Error(`Server returned invalid JSON. ${text.slice(0, 120)}`);
    }
  }
  if (!response.ok) {
    const message = data && data.error ? data.error : `Server error (${response.status}). ${text.slice(0, 120)}`;
    throw new Error(message);
  }
  return data;
}

function isVeryfiConfigured() {
  return storage.mode === "server" && storage.veryfiAvailable;
}

function isVeryfiExhausted() {
  return (
    isVeryfiConfigured() &&
    typeof storage.veryfiRemaining === "number" &&
    storage.veryfiRemaining <= 0
  );
}

function updateOcrRemaining() {
  if (!elements.ocrRemaining) return;
  if (!isVeryfiConfigured()) {
    elements.ocrRemaining.textContent = "—";
    elements.ocrRemaining.title = "";
    return;
  }
  if (typeof storage.veryfiRemaining === "number") {
    elements.ocrRemaining.textContent = storage.veryfiRemaining.toString();
    if (typeof storage.veryfiLimit === "number" && storage.veryfiLimit > 0) {
      elements.ocrRemaining.title = `${storage.veryfiRemaining}/${storage.veryfiLimit} remaining`;
    } else {
      elements.ocrRemaining.title = "";
    }
    return;
  }
  elements.ocrRemaining.textContent = "—";
  elements.ocrRemaining.title = "";
}

function loadOcrOverride() {
  try {
    const value = localStorage.getItem(OCR_OVERRIDE_KEY);
    if (value === "local" || value === "veryfi" || value === "auto") {
      return value;
    }
  } catch (error) {
    return "auto";
  }
  return "auto";
}

function setOcrOverride(value) {
  storage.ocrOverride = value;
  try {
    localStorage.setItem(OCR_OVERRIDE_KEY, value);
  } catch (error) {
    // Ignore storage errors.
  }
  updateOcrStatusLabel();
  updateOcrToggleButton();
}

function updateOcrStatusLabel() {
  if (!elements.ocrStatus) return;
  if (!storage.ocrDefaultEnabled) {
    elements.ocrStatus.textContent = "OCR: off";
    return;
  }
  const override = storage.ocrOverride || "auto";
  if (storage.mode !== "server") {
    elements.ocrStatus.textContent =
      override === "local" ? "OCR: local (manual)" : "OCR: local";
    return;
  }
  if (override === "veryfi") {
    elements.ocrStatus.textContent = shouldRunVeryfi() ? "OCR: Veryfi (manual)" : "OCR: Veryfi unavailable";
    return;
  }
  if (override === "local") {
    elements.ocrStatus.textContent = "OCR: local (manual)";
    return;
  }
  if (override === "auto") {
    elements.ocrStatus.textContent = shouldRunVeryfi() ? "OCR: Veryfi" : "OCR: local";
    return;
  }
  if (shouldRunVeryfi()) {
    elements.ocrStatus.textContent = "OCR: Veryfi";
    return;
  }
  elements.ocrStatus.textContent = shouldRunLocalOcr() ? "OCR: local" : "OCR: off";
}

function updateOcrToggleButton() {
  if (!elements.ocrTypeToggle) return;
  const override = storage.ocrOverride || "auto";
  const veryfiAvailable = shouldRunVeryfi() || (isVeryfiConfigured() && !isVeryfiExhausted());
  if (override === "local") {
    elements.ocrTypeToggle.textContent = "Use Veryfi";
    elements.ocrTypeToggle.disabled = !veryfiAvailable;
    return;
  }
  elements.ocrTypeToggle.textContent = "Use Local";
  elements.ocrTypeToggle.disabled = false;
}

function setOcrProgressState({ active, value, indeterminate } = {}) {
  if (!elements.ocrProgress || !elements.ocrProgressFill) return;
  if (!active) {
    elements.ocrProgress.hidden = true;
    elements.ocrProgress.classList.remove("indeterminate");
    elements.ocrProgressFill.style.width = "0%";
    return;
  }
  elements.ocrProgress.hidden = false;
  if (indeterminate) {
    elements.ocrProgress.classList.add("indeterminate");
    elements.ocrProgressFill.style.width = "35%";
    return;
  }
  elements.ocrProgress.classList.remove("indeterminate");
  const pct = Math.max(0, Math.min(100, Number.isFinite(value) ? value : 0));
  elements.ocrProgressFill.style.width = `${pct}%`;
}

function setOcrProgressForToken(token, options = {}) {
  if (!isActiveToken(token)) return;
  setOcrProgressState(options);
}

function loadScript(src) {
  return new Promise((resolve, reject) => {
    const script = document.createElement("script");
    script.src = src;
    script.async = true;
    script.onload = () => resolve();
    script.onerror = () => reject(new Error(`Failed to load script: ${src}`));
    document.head.appendChild(script);
  });
}

function resolveAssetUrl(path) {
  try {
    return new URL(path, window.location.href).href;
  } catch (error) {
    return path;
  }
}

function trackPdfObjectUrl(url) {
  if (!url) return;
  state.pdfObjectUrls.push(url);
}

async function fetchText(url) {
  const response = await fetch(url, { credentials: "same-origin" });
  if (!response.ok) {
    throw new Error(`Failed to load script: ${url}`);
  }
  return response.text();
}

function normalizePdfModule(module) {
  if (module && module.default && module.default.getDocument) {
    return module.default;
  }
  return module;
}

async function loadPdfModuleWithFallback(src) {
  try {
    const module = await import(src);
    return normalizePdfModule(module);
  } catch (error) {
    const source = await fetchText(src);
    const blobUrl = URL.createObjectURL(new Blob([source], { type: "text/javascript" }));
    trackPdfObjectUrl(blobUrl);
    const module = await import(blobUrl);
    return normalizePdfModule(module);
  }
}

async function loadPdfWorkerBlob(src) {
  const source = await fetchText(src);
  const blobUrl = URL.createObjectURL(new Blob([source], { type: "text/javascript" }));
  trackPdfObjectUrl(blobUrl);
  return blobUrl;
}

async function loadPdfJs() {
  if (state.pdfLoaded) return;
  let lastError = null;
  for (const source of PDFJS_SOURCES) {
    try {
      const scriptUrl = resolveAssetUrl(source.script);
      const workerUrl = resolveAssetUrl(source.worker);
      let workerSrc = workerUrl;
      if (source.script.endsWith(".mjs")) {
        const pdfModule = await loadPdfModuleWithFallback(scriptUrl);
        if (pdfModule) {
          window.pdfjsLib = pdfModule;
        }
        workerSrc = await loadPdfWorkerBlob(workerUrl);
      } else {
        await loadScript(scriptUrl);
      }
      if (window.pdfjsLib && window.pdfjsLib.GlobalWorkerOptions) {
        window.pdfjsLib.GlobalWorkerOptions.workerSrc = workerSrc;
      }
      if (window.pdfjsLib && window.pdfjsLib.getDocument) {
        state.pdfLoaded = true;
        return;
      }
      lastError = new Error("PDF reader loaded but pdfjsLib was not found.");
    } catch (error) {
      lastError = error;
    }
  }
  throw (
    lastError ||
    new Error(
      "PDF reader unavailable. Add vendor/pdfjs/pdf.min.mjs and pdf.worker.min.mjs (or legacy .js builds)."
    )
  );
}

async function extractPdfText(file, token) {
  setOcrStatusForToken(token, "OCR: reading PDF...");
  setOcrProgressForToken(token, { active: true, indeterminate: true });
  await loadPdfJs();
  const buffer = await file.arrayBuffer();
  if (!window.pdfjsLib) {
    throw new Error("PDF reader not available.");
  }
  const pdf = await window.pdfjsLib.getDocument({ data: buffer }).promise;
  let text = "";
  for (let pageNum = 1; pageNum <= pdf.numPages; pageNum += 1) {
    if (!isActiveToken(token)) return "";
    const page = await pdf.getPage(pageNum);
    const content = await page.getTextContent();
    const pageText = content.items.map((item) => item.str || "").join(" ");
    text += `${pageText}\n`;
  }
  const normalized = text.trim();
  if (!normalized) {
    throw new Error("No text extracted from PDF.");
  }
  return normalized;
}

function logClientError(message, context = {}) {
  if (!message) return;
  if (storage.mode !== "server") return;
  const payload = {
    message: String(message),
    context: context && typeof context === "object" ? context : { value: String(context) },
    url: window.location.href,
  };
  fetch("api.php?action=log", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    credentials: "same-origin",
    body: JSON.stringify(payload),
  }).catch(() => {});
}

async function withStore(mode, callback) {
  const db = await getDb();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE_NAME, mode);
    const store = tx.objectStore(STORE_NAME);
    let result;
    tx.oncomplete = () => resolve(result);
    tx.onerror = () => reject(tx.error);
    tx.onabort = () => reject(tx.error);
    result = callback(store);
  });
}

async function addReceipt(receipt) {
  if (storage.mode === "server") {
    const formData = new FormData();
    if (receipt.id) formData.append("id", receipt.id);
    formData.append("date", receipt.date || "");
    formData.append("vendor", receipt.vendor || "");
    formData.append("location", receipt.location || "");
    formData.append("category", receipt.category || "");
    formData.append("businessPurpose", receipt.businessPurpose || "");
    formData.append("total", receipt.total != null ? receipt.total.toString() : "");
    formData.append("createdAt", receipt.createdAt || "");
    if (receipt.image) {
      formData.append("image", receipt.image);
    }
    const data = await apiRequest("save", { method: "POST", body: formData });
    return data.receipt;
  }
  return withStore("readwrite", (store) => store.put(receipt));
}

async function deleteReceipt(id) {
  if (storage.mode === "server") {
    await apiRequest("delete", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ id }),
    });
    return;
  }
  return withStore("readwrite", (store) => store.delete(id));
}

async function getAllReceipts() {
  if (storage.mode === "server") {
    const data = await apiRequest("list");
    return data.receipts || [];
  }
  return withStore("readonly", (store) => store.getAll());
}

function addReceiptsBulk(receipts) {
  if (storage.mode === "server") {
    return receipts.reduce(
      (promise, receipt) => promise.then(() => addReceipt(receipt)),
      Promise.resolve()
    );
  }
  return withStore("readwrite", (store) => {
    receipts.forEach((receipt) => store.put(receipt));
  });
}

function setBulkStatus(message) {
  if (!elements.bulkStatus) return;
  elements.bulkStatus.textContent = message || "";
}

function updateBulkControls(message) {
  if (!elements.bulkSaveAll || !elements.bulkClear) return;
  const hasItems = bulkState.items.length > 0;
  elements.bulkSaveAll.disabled = !hasItems;
  elements.bulkClear.disabled = !hasItems;
  if (message !== undefined) {
    setBulkStatus(message);
  } else {
    setBulkStatus(
      hasItems
        ? `${bulkState.items.length} receipt${bulkState.items.length === 1 ? "" : "s"} queued.`
        : ""
    );
  }
}

function createBulkItem(file) {
  return {
    id: generateId(),
    file,
    originalName: file.name,
    previewUrl: URL.createObjectURL(file),
    date: todayISO(),
    vendor: "",
    location: "",
    category: "",
    businessPurpose: "",
    total: "",
    ocrStatus: "",
    ocrMessage: "",
    errors: [],
  };
}

function revokeBulkPreview(item) {
  if (!item.previewUrl) return;
  URL.revokeObjectURL(item.previewUrl);
  item.previewUrl = null;
}

function clearBulkQueue() {
  bulkState.items.forEach(revokeBulkPreview);
  bulkState.items = [];
  renderBulkList();
  updateBulkControls("");
}

function validateBulkItem(item) {
  const errors = [];
  if (!item.date) errors.push("date");
  if (!item.vendor.trim()) errors.push("vendor");
  if (!item.category.trim()) errors.push("category");
  if (!item.businessPurpose.trim()) errors.push("business purpose");
  const totalValue = parseFloat(item.total);
  if (Number.isNaN(totalValue)) errors.push("total");
  return { errors, totalValue };
}

function renderBulkList() {
  if (!elements.bulkList) return;
  elements.bulkList.innerHTML = "";
  if (bulkState.items.length === 0) return;

  bulkState.items.forEach((item, index) => {
    const card = document.createElement("div");
    card.className = "bulk-card";
    if (item.errors && item.errors.length > 0) {
      card.classList.add("invalid");
    }

    const header = document.createElement("div");
    header.className = "bulk-card-header";

    const left = document.createElement("div");
    left.className = "bulk-left";

    const previewFrame = document.createElement("div");
    previewFrame.className = "bulk-preview-frame";

    const img = document.createElement("img");
    img.src = item.previewUrl;
    img.alt = `Queued receipt ${index + 1}`;
    img.title = "Click to zoom: 2x, 3x";
    previewFrame.append(img);

    const fileInfo = document.createElement("div");
    const fileName = document.createElement("div");
    fileName.textContent = item.originalName || item.file.name;
    const fileMeta = document.createElement("div");
    fileMeta.className = "receipt-meta";
    fileMeta.textContent = formatSize(item.file.size);
    const zoomHint = document.createElement("div");
    zoomHint.className = "bulk-zoom-hint";
    zoomHint.textContent = "Zoom: tap/click (2x, 3x)";
    fileInfo.append(fileName, fileMeta, zoomHint);

    left.append(previewFrame, fileInfo);

    const zoomState = createZoomState();
    resetPreviewZoom(zoomState, previewFrame, img);
    attachZoomHandlers(previewFrame, img, zoomState);

    const actions = document.createElement("div");
    actions.className = "bulk-card-actions";
    const removeBtn = document.createElement("button");
    removeBtn.className = "btn ghost";
    removeBtn.type = "button";
    removeBtn.textContent = "Remove";
    removeBtn.addEventListener("click", () => {
      revokeBulkPreview(item);
      bulkState.items = bulkState.items.filter((entry) => entry.id !== item.id);
      renderBulkList();
      updateBulkControls();
    });
    actions.append(removeBtn);

    header.append(left, actions);

    const fields = document.createElement("div");
    fields.className = "bulk-fields";

    const dateLabel = document.createElement("label");
    dateLabel.textContent = "Date";
    const dateInput = document.createElement("input");
    dateInput.type = "date";
    dateInput.value = item.date;
    dateInput.required = true;
    dateInput.addEventListener("input", () => {
      item.date = dateInput.value;
      clearOcrHighlight(dateInput);
      if (item.errors.length) {
        item.errors = [];
        card.classList.remove("invalid");
        errorMsg.textContent = "";
      }
    });
    dateLabel.append(dateInput);

    const vendorLabel = document.createElement("label");
    vendorLabel.textContent = "Vendor";
    const vendorInput = document.createElement("input");
    vendorInput.type = "text";
    vendorInput.placeholder = "Coffee shop";
    vendorInput.value = item.vendor;
    vendorInput.required = true;
    vendorInput.addEventListener("input", () => {
      item.vendor = vendorInput.value;
      clearOcrHighlight(vendorInput);
      if (item.errors.length) {
        item.errors = [];
        card.classList.remove("invalid");
        errorMsg.textContent = "";
      }
    });
    vendorLabel.append(vendorInput);

    const locationLabel = document.createElement("label");
    locationLabel.textContent = "Location";
    const locationInput = document.createElement("input");
    locationInput.type = "text";
    locationInput.placeholder = "City, State";
    locationInput.value = item.location;
    locationInput.addEventListener("input", () => {
      item.location = locationInput.value;
      clearOcrHighlight(locationInput);
    });
    locationLabel.append(locationInput);

    const categoryLabel = document.createElement("label");
    categoryLabel.textContent = "Category";
    const categorySelect = document.createElement("select");
    categorySelect.required = true;
    populateCategorySelect(categorySelect, item.category);
    categorySelect.addEventListener("change", () => {
      item.category = categorySelect.value;
      clearOcrHighlight(categorySelect);
      if (item.errors.length) {
        item.errors = [];
        card.classList.remove("invalid");
        errorMsg.textContent = "";
      }
    });
    categoryLabel.append(categorySelect);

    const purposeLabel = document.createElement("label");
    purposeLabel.textContent = "Business Purpose";
    const purposeInput = document.createElement("input");
    purposeInput.type = "text";
    purposeInput.placeholder = "Client lunch";
    purposeInput.value = item.businessPurpose;
    purposeInput.required = true;
    purposeInput.addEventListener("input", () => {
      item.businessPurpose = purposeInput.value;
      clearOcrHighlight(purposeInput);
      if (item.errors.length) {
        item.errors = [];
        card.classList.remove("invalid");
        errorMsg.textContent = "";
      }
    });
    purposeLabel.append(purposeInput);

    const totalLabel = document.createElement("label");
    totalLabel.textContent = "Total Spent";
    const totalInput = document.createElement("input");
    totalInput.type = "number";
    totalInput.placeholder = "0.00";
    totalInput.min = "0";
    totalInput.step = "0.01";
    totalInput.value = item.total;
    totalInput.required = true;
    totalInput.addEventListener("input", () => {
      item.total = totalInput.value;
      clearOcrHighlight(totalInput);
      if (item.errors.length) {
        item.errors = [];
        card.classList.remove("invalid");
        errorMsg.textContent = "";
      }
    });
    totalLabel.append(totalInput);

    fields.append(dateLabel, vendorLabel, locationLabel, categoryLabel, purposeLabel, totalLabel);

    const highlightUntil = item.ocrHighlightUntil || 0;
    if (highlightUntil > Date.now() && item.ocrHighlights) {
      const remaining = highlightUntil - Date.now();
      if (item.ocrHighlights.date) applyOcrHighlight(dateInput, remaining);
      if (item.ocrHighlights.vendor) applyOcrHighlight(vendorInput, remaining);
      if (item.ocrHighlights.location) applyOcrHighlight(locationInput, remaining);
      if (item.ocrHighlights.category) applyOcrHighlight(categorySelect, remaining);
      if (item.ocrHighlights.total) applyOcrHighlight(totalInput, remaining);
    } else if (item.ocrHighlights) {
      item.ocrHighlights = null;
      item.ocrHighlightUntil = 0;
    }

    const errorMsg = document.createElement("div");
    errorMsg.className = "bulk-error";
    if (item.errors && item.errors.length > 0) {
      errorMsg.textContent = `Missing: ${item.errors.join(", ")}`;
    }

    card.append(header, fields, errorMsg);
    elements.bulkList.append(card);
  });
}

async function addBulkFiles(files) {
  const fileArray = Array.from(files || []);
  if (fileArray.length === 0) return;
  const pdfFiles = fileArray.filter((file) => isPdfFile(file));
  if (pdfFiles.length > 0) {
    setBulkStatus("PDFs aren't supported in bulk. Use single upload for PDFs.");
  }
  const imageFiles = fileArray.filter((file) => isImageFile(file));
  if (imageFiles.length === 0) return;
  setBulkStatus(`Processing ${imageFiles.length} receipt${imageFiles.length === 1 ? "" : "s"}...`);
  let processedCount = 0;
  let failedCount = 0;
  const ocrEnabled = storage.ocrDefaultEnabled;
  const ocrAvailable = ocrEnabled && storage.mode === "server" && storage.veryfiAvailable;
  for (const file of imageFiles) {
    let processed = file;
    try {
      processed = await buildBwFile(file);
    } catch (error) {
      failedCount += 1;
      continue;
    }
    const item = createBulkItem(processed);
    item.originalName = file.name;
    const limitReached = typeof storage.veryfiRemaining === "number" && storage.veryfiRemaining <= 0;
    if (!ocrEnabled) {
      item.ocrStatus = "skipped";
      item.ocrMessage = "OCR: disabled in config.";
    } else if (!ocrAvailable) {
      item.ocrStatus = "skipped";
      item.ocrMessage =
        storage.mode === "server"
          ? "OCR: Veryfi not configured."
          : "OCR: unavailable in local mode.";
    } else if (limitReached) {
      item.ocrStatus = "skipped";
      item.ocrMessage = "OCR: Veryfi limit reached.";
    } else {
      item.ocrStatus = "running";
      item.ocrMessage = "OCR: running...";
    }
    bulkState.items.push(item);
    processedCount += 1;
    renderBulkList();
    updateBulkControls();

    if (item.ocrStatus === "running") {
      try {
        const { text, suggestions } = await runVeryfiOcrForFile(processed);
        applyOcrToBulkItem(item, suggestions);
        if (suggestions && Object.keys(suggestions).length > 0) {
          item.ocrStatus = "applied";
          item.ocrMessage = "OCR: applied.";
          if (!item.category) {
            const inferred = inferCategoryFromText(text, suggestions?.vendor || "");
            if (inferred) {
              item.category = inferred;
              item.ocrHighlights = item.ocrHighlights || {};
              item.ocrHighlights.category = true;
              item.ocrHighlightUntil = Date.now() + 5000;
            }
          }
        } else {
          item.ocrStatus = "done";
          item.ocrMessage = "OCR: no suggestions.";
        }
      } catch (error) {
        item.ocrStatus = "failed";
        item.ocrMessage = `OCR: ${error.message}`;
      }
      renderBulkList();
      updateBulkControls();
    }
  }
  if (failedCount > 0) {
    setBulkStatus(
      `Processed ${processedCount} receipt${processedCount === 1 ? "" : "s"}, skipped ${failedCount} that failed B/W processing.`
    );
  }
}

async function saveBulkReceipts() {
  if (bulkState.items.length === 0) return;
  let hasErrors = false;
  bulkState.items.forEach((item) => {
    const { errors, totalValue } = validateBulkItem(item);
    item.errors = errors;
    item.totalValue = totalValue;
    if (errors.length > 0) hasErrors = true;
  });

  if (hasErrors) {
    renderBulkList();
    updateBulkControls("Fill in Date, Vendor, Category, Business Purpose, and Total for all queued receipts.");
    return;
  }

  const receipts = bulkState.items.map((item) => ({
    id: generateId(),
    date: item.date,
    vendor: item.vendor.trim(),
    location: item.location.trim(),
    category: item.category.trim(),
    businessPurpose: item.businessPurpose.trim(),
    total: item.totalValue,
    createdAt: new Date().toISOString(),
    image: item.file,
    ocrText: "",
  }));

  try {
    await addReceiptsBulk(receipts);
    const savedCount = receipts.length;
    clearBulkQueue();
    updateBulkControls(`Saved ${savedCount} receipt${savedCount === 1 ? "" : "s"}.`);
    await refreshList();
  } catch (error) {
    updateBulkControls(`Save failed: ${error.message}`);
    logClientError("Bulk save failed", { error: error.message });
  }
}

function resetPreviewZoom(state = previewZoom, frame = elements.previewDrop, img = elements.previewImage) {
  state.mode = "none";
  state.scale = 1;
  state.x = 0;
  state.y = 0;
  state.isPinching = false;
  state.isPanning = false;
  state.startDist = 0;
  state.startScale = 1;
  state.lastX = 0;
  state.lastY = 0;
  if (img) {
    img.style.transform = "scale(1)";
    img.style.transformOrigin = "50% 50%";
  }
  if (frame) {
    frame.classList.remove("mouse-zoom", "touch-zoom");
  }
}

function updatePreviewBaseSize(state = previewZoom, img = elements.previewImage) {
  if (!img || img.style.display === "none") return;
  const rect = img.getBoundingClientRect();
  state.baseWidth = rect.width;
  state.baseHeight = rect.height;
}

function clampPreviewPan(state = previewZoom, frame = elements.previewDrop) {
  if (!frame) return;
  const container = frame.getBoundingClientRect();
  const scaledWidth = state.baseWidth * state.scale;
  const scaledHeight = state.baseHeight * state.scale;
  const maxX = Math.max(0, (scaledWidth - container.width) / 2);
  const maxY = Math.max(0, (scaledHeight - container.height) / 2);
  state.x = Math.max(-maxX, Math.min(maxX, state.x));
  state.y = Math.max(-maxY, Math.min(maxY, state.y));
}

function applyPreviewTransform(
  state = previewZoom,
  frame = elements.previewDrop,
  img = elements.previewImage
) {
  if (!img) return;
  if (state.mode === "touch") {
    img.style.transformOrigin = "50% 50%";
    img.style.transform = `translate(${state.x}px, ${state.y}px) scale(${state.scale})`;
  } else if (state.mode === "mouse" && state.scale > 1) {
    img.style.transform = `scale(${state.scale})`;
  } else {
    img.style.transform = "scale(1)";
  }

  if (frame) {
    frame.classList.toggle("mouse-zoom", state.mode === "mouse" && state.scale > 1);
    frame.classList.toggle("touch-zoom", state.mode === "touch" && state.scale > 1);
  }
}

function setMouseZoomOrigin(
  event,
  state = previewZoom,
  frame = elements.previewDrop,
  img = elements.previewImage
) {
  if (!img || !frame) return;
  if (state.mode !== "mouse" || state.scale <= 1) return;
  const rect = frame.getBoundingClientRect();
  const x = Math.max(0, Math.min(1, (event.clientX - rect.left) / rect.width));
  const y = Math.max(0, Math.min(1, (event.clientY - rect.top) / rect.height));
  img.style.transformOrigin = `${(x * 100).toFixed(2)}% ${(y * 100).toFixed(2)}%`;
}

function touchDistance(t1, t2) {
  const dx = t2.clientX - t1.clientX;
  const dy = t2.clientY - t1.clientY;
  return Math.hypot(dx, dy);
}

function attachZoomHandlers(frame, img, state) {
  if (!frame || !img || !state) return;
  const isFinePointer = window.matchMedia && window.matchMedia("(pointer: fine)").matches;
  const syncBaseSize = () => updatePreviewBaseSize(state, img);

  img.addEventListener("load", syncBaseSize);
  requestAnimationFrame(syncBaseSize);

  img.addEventListener("click", (event) => {
    if (!isFinePointer) return;
    if (img.style.display === "none") return;
    const steps = state.zoomSteps && state.zoomSteps.length ? state.zoomSteps : [2];
    const currentIndex = steps.findIndex((value) => Math.abs(value - state.scale) < 0.01);
    if (state.mode === "mouse" && state.scale > 1 && currentIndex >= 0) {
      if (currentIndex < steps.length - 1) {
        state.scale = steps[currentIndex + 1];
      } else {
        state.mode = "none";
        state.scale = 1;
        img.style.transformOrigin = "50% 50%";
        applyPreviewTransform(state, frame, img);
        return;
      }
    } else {
      state.mode = "mouse";
      state.scale = steps[0];
    }
    state.x = 0;
    state.y = 0;
    applyPreviewTransform(state, frame, img);
    setMouseZoomOrigin(event, state, frame, img);
  });

  frame.addEventListener("mousemove", (event) => {
    setMouseZoomOrigin(event, state, frame, img);
  });

  frame.addEventListener("mouseleave", () => {
    if (state.mode === "mouse") {
      img.style.transformOrigin = "50% 50%";
    }
  });

  frame.addEventListener(
    "touchstart",
    (event) => {
      if (img.style.display === "none") return;
      updatePreviewBaseSize(state, img);
      if (event.touches.length === 2) {
        state.mode = "touch";
        state.isPinching = true;
        state.isPanning = false;
        state.startDist = touchDistance(event.touches[0], event.touches[1]);
        state.startScale = state.scale;
      } else if (event.touches.length === 1 && state.scale > 1) {
        state.mode = "touch";
        state.isPanning = true;
        state.isPinching = false;
        state.lastX = event.touches[0].clientX;
        state.lastY = event.touches[0].clientY;
      }
    },
    { passive: false }
  );

  frame.addEventListener(
    "touchmove",
    (event) => {
      if (state.mode !== "touch") return;
      if (event.touches.length === 2 && state.isPinching) {
        const dist = touchDistance(event.touches[0], event.touches[1]);
        if (state.startDist > 0) {
          const nextScale = state.startScale * (dist / state.startDist);
          state.scale = Math.max(state.minScale, Math.min(state.maxScale, nextScale));
          clampPreviewPan(state, frame);
          applyPreviewTransform(state, frame, img);
          event.preventDefault();
        }
        return;
      }

      if (event.touches.length === 1 && state.isPanning) {
        const touch = event.touches[0];
        const dx = touch.clientX - state.lastX;
        const dy = touch.clientY - state.lastY;
        state.x += dx;
        state.y += dy;
        state.lastX = touch.clientX;
        state.lastY = touch.clientY;
        clampPreviewPan(state, frame);
        applyPreviewTransform(state, frame, img);
        event.preventDefault();
      }
    },
    { passive: false }
  );

  const endTouch = (event) => {
    if (event.touches.length < 2) {
      state.isPinching = false;
    }
    if (event.touches.length === 0) {
      state.isPanning = false;
    }
    if (state.scale <= 1.01) {
      state.mode = "none";
      state.scale = 1;
      state.x = 0;
      state.y = 0;
      applyPreviewTransform(state, frame, img);
    }
  };

  frame.addEventListener("touchend", endTouch);
  frame.addEventListener("touchcancel", endTouch);
}

function updatePreviewMeta() {
  if (!elements.previewMeta) return;
  const base = state.previewMetaBase || "Choose a photo to preview.";
  const ocr = state.ocrStatusText;
  elements.previewMeta.textContent = ocr ? `${base} · ${ocr}` : base;
}

function setPreviewMessage(message) {
  state.previewMetaBase = message || "Choose a photo to preview.";
  updatePreviewMeta();
}

function setOcrStatus(message) {
  state.ocrStatusText = message || "";
  updatePreviewMeta();
}

function clearPreview() {
  elements.previewImage.src = "";
  elements.previewImage.style.display = "none";
  if (elements.previewPdf) {
    elements.previewPdf.src = "";
    elements.previewPdf.style.display = "none";
  }
  elements.previewPlaceholder.style.display = "block";
  elements.previewPlaceholder.textContent = "No image yet";
  setPreviewMessage("Choose a photo to preview.");
  setOcrStatus("");
  if (elements.previewHint) elements.previewHint.classList.add("hidden");
  resetPreviewZoom();
}

function setPreview(file, metaText) {
  if (!file) {
    clearPreview();
    return;
  }
  if (elements.previewPdf) {
    elements.previewPdf.src = "";
    elements.previewPdf.style.display = "none";
  }
  const url = URL.createObjectURL(file);
  elements.previewImage.src = url;
  elements.previewImage.style.display = "block";
  elements.previewPlaceholder.style.display = "none";
  elements.previewImage.title = "Click to zoom: 2x, 3x";
  if (elements.previewHint) elements.previewHint.classList.remove("hidden");
  resetPreviewZoom();
  const name = file.name ? file.name : "receipt image";
  setPreviewMessage(metaText || `${name} · ${formatSize(file.size)}`);
  setOcrStatus("");
  elements.previewImage.onload = () => {
    URL.revokeObjectURL(url);
    updatePreviewBaseSize();
  };
}

function setPdfPreview(file, metaText) {
  if (!file) {
    clearPreview();
    return;
  }
  elements.previewImage.src = "";
  elements.previewImage.style.display = "none";
  if (elements.previewPdf) {
    const url = URL.createObjectURL(file);
    elements.previewPdf.src = url;
    elements.previewPdf.style.display = "block";
    elements.previewPdf.onload = () => {
      URL.revokeObjectURL(url);
    };
  }
  elements.previewPlaceholder.style.display = "none";
  if (elements.previewHint) elements.previewHint.classList.add("hidden");
  resetPreviewZoom();
  const name = file.name ? file.name : "receipt PDF";
  setPreviewMessage(metaText || `${name} · ${formatSize(file.size)} (PDF)`);
  setOcrStatus("");
}

function resetOcrState() {
  state.ocrText = "";
  state.ocrSuggestions = null;
  setOcrStatus("");
  setOcrProgressState({ active: false });
}

function isActiveToken(token) {
  return token === state.processToken;
}

function setOcrStatusForToken(token, message) {
  if (!isActiveToken(token)) return;
  setOcrStatus(message);
}

function getVeryfiBlockReason() {
  if (storage.mode !== "server") {
    return "Veryfi unavailable in local mode.";
  }
  if (!storage.veryfiAvailable) {
    return "Veryfi not configured.";
  }
  if (isVeryfiExhausted()) {
    if (storage.veryfiLimit) {
      return `Veryfi limit reached (${storage.veryfiLimit}).`;
    }
    return "Veryfi limit reached.";
  }
  return "";
}

function shouldRunVeryfi() {
  return storage.ocrDefaultEnabled && isVeryfiConfigured() && !isVeryfiExhausted();
}

function canRunLocalOcr() {
  return storage.ocrDefaultEnabled;
}

function shouldRunLocalOcr() {
  if (!storage.ocrDefaultEnabled) return false;
  if (isVeryfiExhausted()) return false;
  return storage.mode === "local" || !isVeryfiConfigured();
}

function canUsePdfText() {
  if (storage.mode === "server") {
    return Boolean(storage.pdfJsAvailable);
  }
  return true;
}

function buildOcrSummary(suggestions) {
  if (!suggestions) return "";
  const summary = [];
  if (suggestions.date) summary.push(`Date: ${suggestions.date}`);
  if (suggestions.vendor) summary.push(`Vendor: ${suggestions.vendor}`);
  if (suggestions.location) summary.push(`Location: ${suggestions.location}`);
  if (suggestions.total !== null && suggestions.total !== undefined) {
    const totalValue = Number(suggestions.total);
    if (Number.isFinite(totalValue)) {
      summary.push(`Total: ${formatCurrency(totalValue)}`);
    }
  }
  return summary.join(" · ");
}

function normalizeText(value) {
  return String(value || "").toLowerCase();
}

function inferCategoryFromText(text, vendor) {
  const haystack = `${normalizeText(vendor)} ${normalizeText(text)}`;
  if (!haystack.trim()) return "";

  const rules = [
    {
      category: "Software & Subscriptions",
      terms: [
        "adobe",
        "lightroom",
        "photoshop",
        "dropbox",
        "google workspace",
        "gsuite",
        "microsoft",
        "office 365",
        "creative cloud",
        "slack",
        "figma",
        "notion",
        "airtable",
        "github",
        "aws",
        "digitalocean",
        "stripe",
        "domain",
        "hosting",
        "subscription",
      ],
    },
    {
      category: "Equipment & Gear",
      terms: [
        "camera",
        "lens",
        "tripod",
        "lighting",
        "light stand",
        "softbox",
        "memory card",
        "sd card",
        "hard drive",
        "ssd",
        "monitor",
        "macbook",
        "laptop",
        "microphone",
        "audio",
        "battery",
        "canon",
        "nikon",
        "sony",
        "panasonic",
      ],
    },
    {
      category: "Vehicle & Travel",
      terms: [
        "uber",
        "lyft",
        "delta",
        "united",
        "american airlines",
        "southwest",
        "hotel",
        "airbnb",
        "rental car",
        "hertz",
        "avis",
        "enterprise",
        "parking",
        "toll",
        "gas",
        "fuel",
        "shell",
        "chevron",
        "exxon",
        "marriott",
        "hilton",
      ],
    },
    {
      category: "Meals & Entertainment",
      terms: [
        "restaurant",
        "cafe",
        "coffee",
        "starbucks",
        "dunkin",
        "chipotle",
        "panera",
        "ubereats",
        "doordash",
        "grubhub",
        "bar",
        "lunch",
        "dinner",
        "breakfast",
      ],
    },
    {
      category: "Marketing & Advertising",
      terms: [
        "facebook ads",
        "instagram ads",
        "google ads",
        "adwords",
        "marketing",
        "advertising",
        "printing",
        "flyer",
        "brochure",
        "business cards",
        "sponsored",
        "campaign",
      ],
    },
    {
      category: "Professional Services",
      terms: [
        "accountant",
        "bookkeeper",
        "legal",
        "law",
        "attorney",
        "consulting",
        "contractor",
        "invoice",
        "freelance",
        "coach",
      ],
    },
    {
      category: "Income Processing Fees",
      terms: [
        "stripe fee",
        "paypal fee",
        "square fee",
        "processing fee",
        "transaction fee",
        "marketplace fee",
      ],
    },
    {
      category: "Home Office / Workspace",
      terms: [
        "coworking",
        "wework",
        "office rent",
        "workspace",
        "internet",
        "utilities",
        "electric",
        "water",
        "rent",
        "mortgage",
      ],
    },
  ];

  for (const rule of rules) {
    if (rule.terms.some((term) => haystack.includes(term))) {
      return rule.category;
    }
  }

  return "";
}

async function loadTesseract() {
  if (state.ocrLoaded) return;
  await new Promise((resolve, reject) => {
    const script = document.createElement("script");
    script.src = "https://unpkg.com/tesseract.js@5.0.5/dist/tesseract.min.js";
    script.async = true;
    script.onload = () => resolve();
    script.onerror = () => reject(new Error("Failed to load OCR library."));
    document.head.appendChild(script);
  });
  state.ocrLoaded = true;
}

function extractAmounts(text) {
  const matches = [];
  const regex = /(\$\s*)?\d{1,3}(?:,\d{3})*(?:\.\d{2})|(?:\$\s*)?\d+\.\d{2}/g;
  let match;
  while ((match = regex.exec(text)) !== null) {
    const raw = match[0].replace(/[^0-9.]/g, "");
    const value = parseFloat(raw);
    if (!Number.isNaN(value)) matches.push(value);
  }
  return matches;
}

function parseTotalFromText(text) {
  const lines = text.split(/\r?\n/).map((line) => line.trim()).filter(Boolean);
  const keywordRegex = /(grand\s*total|total|amount|balance\s*due|amount\s*due|paid)/i;
  let totals = [];

  for (const line of lines) {
    if (keywordRegex.test(line)) {
      totals = totals.concat(extractAmounts(line));
    }
  }

  if (totals.length > 0) {
    return Math.max(...totals);
  }

  const allAmounts = extractAmounts(text);
  if (allAmounts.length === 0) return null;
  return Math.max(...allAmounts);
}

function parseDateFromText(text) {
  const lines = text
    .split(/\r?\n/)
    .map((line) => line.replace(/\s+/g, " ").trim())
    .filter(Boolean);
  const monthNames =
    "(Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)";
  const monthRegex = new RegExp(`\\b${monthNames}\\s+(\\d{1,2})(?:st|nd|rd|th)?(?:,)?\\s+(\\d{4})\\b`, "i");
  const dayFirstRegex = new RegExp(`\\b(\\d{1,2})(?:st|nd|rd|th)?\\s+${monthNames}\\s+(\\d{4})\\b`, "i");
  const numericPatterns = [
    { regex: /\b(20\d{2}|19\d{2})[\/\-.](0?[1-9]|1[0-2])[\/\-.](0?[1-9]|[12]\d|3[01])\b/, order: "ymd" },
    { regex: /\b(0?[1-9]|1[0-2])[\/\-.](0?[1-9]|[12]\d|3[01])[\/\-.]((?:20)?\d{2})\b/, order: "mdy" },
  ];

  const monthIndex = (value) => {
    const key = value.toLowerCase().slice(0, 3);
    const map = {
      jan: 1,
      feb: 2,
      mar: 3,
      apr: 4,
      may: 5,
      jun: 6,
      jul: 7,
      aug: 8,
      sep: 9,
      oct: 10,
      nov: 11,
      dec: 12,
    };
    return map[key] || 0;
  };

  const buildDate = (year, month, day) => {
    const date = new Date(year, month - 1, day);
    if (date.getMonth() + 1 !== month || date.getDate() !== day) return null;
    return `${year.toString().padStart(4, "0")}-${month.toString().padStart(2, "0")}-${day
      .toString()
      .padStart(2, "0")}`;
  };

  const parseMonthDate = (value) => {
    let match = value.match(monthRegex);
    if (match) {
      const month = monthIndex(match[1]);
      const day = parseInt(match[2], 10);
      const year = parseInt(match[3], 10);
      if (month) return buildDate(year, month, day);
    }
    match = value.match(dayFirstRegex);
    if (match) {
      const day = parseInt(match[1], 10);
      const month = monthIndex(match[2]);
      const year = parseInt(match[3], 10);
      if (month) return buildDate(year, month, day);
    }
    return null;
  };

  const parseNumericDate = (value) => {
    for (const pattern of numericPatterns) {
      const match = value.match(pattern.regex);
      if (!match) continue;
      let year;
      let month;
      let day;
      if (pattern.order === "ymd") {
        year = parseInt(match[1], 10);
        month = parseInt(match[2], 10);
        day = parseInt(match[3], 10);
      } else {
        month = parseInt(match[1], 10);
        day = parseInt(match[2], 10);
        year = parseInt(match[3], 10);
        if (year < 100) year += 2000;
      }
      const formatted = buildDate(year, month, day);
      if (formatted) return formatted;
    }
    return null;
  };

  const preferredLine = /(date|paid|invoice|issued|billing)/i;
  for (const line of lines) {
    if (!preferredLine.test(line)) continue;
    const parsed = parseMonthDate(line) || parseNumericDate(line);
    if (parsed) return parsed;
  }

  const monthWide = parseMonthDate(text);
  if (monthWide) return monthWide;
  return parseNumericDate(text);
}

function parseVendorFromText(text) {
  const lines = text
    .split(/\r?\n/)
    .map((line) => line.replace(/\s+/g, " ").trim())
    .filter((line) => line.length > 2);
  const skipRegex =
    /(invoice|date|paid|total|amount|balance|tax|change|visa|mastercard|amex|cash|subtotal|payment|receipt|vat|email|plan)/i;
  const companyRegex =
    /\b(inc|llc|l\.l\.c\.|corp|corporation|company|co\.|ltd|limited|gmbh|sarl|sa|plc|bv|oy|ab|ag|kg|pte|llp)\b/i;
  const emailRegex = /@/;
  const urlRegex = /(https?:\/\/|www\.)/i;
  const addressRegex =
    /\b(\d{1,5}\s+\S+|street|st\.|avenue|ave\.|road|rd\.|boulevard|blvd\.|lane|ln\.|drive|dr\.|suite|ste\.|floor|fl\.|strasse|straße|str\.)\b/i;
  const postalRegex = /\b\d{5}(?:-\d{4})?\b/;

  const isSkippable = (line) =>
    skipRegex.test(line) || emailRegex.test(line) || urlRegex.test(line) || postalRegex.test(line);
  const isAddress = (line) => addressRegex.test(line);

  for (const line of lines) {
    if (companyRegex.test(line) && !isSkippable(line) && !isAddress(line)) {
      return line.slice(0, 40);
    }
  }

  for (let i = 0; i < lines.length - 1; i += 1) {
    if (!skipRegex.test(lines[i])) continue;
    for (let j = i + 1; j < lines.length; j += 1) {
      const candidate = lines[j];
      if (!/[A-Za-z]/.test(candidate)) continue;
      if (isSkippable(candidate) || isAddress(candidate)) continue;
      return candidate.slice(0, 40);
    }
  }

  for (const line of lines) {
    if (/[A-Za-z]/.test(line) && !isSkippable(line) && !isAddress(line)) {
      return line.slice(0, 40);
    }
  }
  return null;
}

function parseLocationFromText(text) {
  const lines = text
    .split(/\r?\n/)
    .map((line) => line.replace(/\s+/g, " ").trim())
    .filter((line) => line.length > 2);
  const zipPattern = /([A-Za-z][A-Za-z .'-]+)[, ]+([A-Z]{2})\s+\d{5}(?:-\d{4})?/;
  const commaPattern = /([A-Za-z][A-Za-z .'-]+),\s*([A-Z]{2})\b/;

  for (const line of lines) {
    let match = line.match(zipPattern);
    if (match) {
      return `${match[1].trim()}, ${match[2].trim()}`;
    }
  }

  for (const line of lines) {
    const match = line.match(commaPattern);
    if (match) {
      return `${match[1].trim()}, ${match[2].trim()}`;
    }
  }

  return null;
}

function buildOcrSuggestions(text) {
  const date = parseDateFromText(text);
  const total = parseTotalFromText(text);
  const vendor = parseVendorFromText(text);
  const location = parseLocationFromText(text);
  const suggestions = { date, total, vendor, location };
  const hasAny = Object.values(suggestions).some((value) => value !== null && value !== undefined);
  return hasAny ? suggestions : null;
}

function applySuggestions() {
  const suggestions = state.ocrSuggestions;
  if (!suggestions) return;

  const maybeSet = (input, value) => {
    if (!value) return;
    if (!input.value) {
      input.value = value;
      return true;
    }
    if (input.value && input.value !== value) {
      input.value = value;
      return true;
    }
    return false;
  };

  if (maybeSet(elements.receiptDate, suggestions.date)) {
    applyOcrHighlight(elements.receiptDate);
  }
  if (maybeSet(elements.receiptVendor, suggestions.vendor)) {
    applyOcrHighlight(elements.receiptVendor);
  }
  if (maybeSet(elements.receiptLocation, suggestions.location)) {
    applyOcrHighlight(elements.receiptLocation);
  }
  if (suggestions.total !== null && suggestions.total !== undefined) {
    const totalValue = Number(suggestions.total);
    if (Number.isFinite(totalValue)) {
      if (maybeSet(elements.receiptTotalInput, totalValue.toFixed(2))) {
        applyOcrHighlight(elements.receiptTotalInput);
      }
    }
  }

  if (elements.receiptCategory && !elements.receiptCategory.value) {
    const inferred = inferCategoryFromText(state.ocrText, suggestions.vendor || "");
    if (inferred) {
      elements.receiptCategory.value = inferred;
      applyOcrHighlight(elements.receiptCategory);
    }
  }
}

async function runLocalOcrForFile(file, token) {
  setOcrStatusForToken(token, "OCR: loading...");
  setOcrProgressForToken(token, { active: true, value: 0, indeterminate: false });
  await loadTesseract();
  setOcrStatusForToken(token, "OCR: reading...");

  const result = await Tesseract.recognize(file, "eng", {
    logger: (message) => {
      if (!isActiveToken(token)) return;
      if (message.status === "recognizing text") {
        const pct = Math.round(message.progress * 100);
        setOcrStatusForToken(token, `OCR: ${pct}%`);
        setOcrProgressForToken(token, { active: true, value: pct, indeterminate: false });
      }
    },
  });

  const text = result.data.text.trim();
  const suggestions = buildOcrSuggestions(text);
  return { text, suggestions };
}

async function runVeryfiOcrForFile(file) {
  const formData = new FormData();
  formData.append("image", file);
  const data = await apiRequest("veryfi_ocr", { method: "POST", body: formData });
  if (typeof data.veryfiRemaining === "number") {
    storage.veryfiRemaining = data.veryfiRemaining;
  }
  if (typeof data.veryfiLimit === "number") {
    storage.veryfiLimit = data.veryfiLimit;
  }
  updateOcrRemaining();
  updateOcrStatusLabel();
  updateOcrToggleButton();
  return { text: (data.text || "").trim(), suggestions: data.suggestions || null };
}

async function autoRunOcrForCurrentFile(token) {
  if (!state.currentFile) return;

  if (!storage.ocrDefaultEnabled) {
    setOcrStatusForToken(token, "");
    setOcrProgressForToken(token, { active: false });
    return;
  }

  const override = storage.ocrOverride || "auto";
  const canVeryfi = shouldRunVeryfi();
  const canLocal = canRunLocalOcr();

  if (override === "veryfi") {
    if (!canVeryfi) {
      setOcrStatusForToken(token, "OCR: Veryfi unavailable.");
      setOcrProgressForToken(token, { active: false });
      return;
    }
  }

  if (override === "local") {
    if (!canLocal) {
      setOcrStatusForToken(token, "OCR: local unavailable.");
      setOcrProgressForToken(token, { active: false });
      return;
    }
    if (isPdfFile(state.currentFile)) {
      if (!canUsePdfText()) {
        setOcrStatusForToken(token, "OCR: PDF text unavailable (missing PDF.js).");
        setOcrProgressForToken(token, { active: false });
        return;
      }
      try {
        const text = await extractPdfText(state.currentFile, token);
        if (!isActiveToken(token)) return;
        state.ocrText = text;
        state.ocrSuggestions = buildOcrSuggestions(text);
        if (state.ocrSuggestions) {
          applySuggestions();
          const summary = buildOcrSummary(state.ocrSuggestions);
          setOcrStatusForToken(token, summary ? `OCR: applied. ${summary}` : "OCR: applied.");
        } else {
          setOcrStatusForToken(token, "OCR: no suggestions.");
        }
        setOcrProgressForToken(token, { active: false });
      } catch (error) {
        if (!isActiveToken(token)) return;
        setOcrStatusForToken(token, `OCR: ${error.message}`);
        setOcrProgressForToken(token, { active: false });
        logClientError("PDF text extraction failed", { error: error.message });
      }
      return;
    }
  }

  if (override === "veryfi" && isPdfFile(state.currentFile)) {
    setOcrStatusForToken(token, "OCR: using Veryfi for PDF...");
  }

  if (override === "auto" && isPdfFile(state.currentFile)) {
    if (!canUsePdfText()) {
      if (canVeryfi) {
        setOcrStatusForToken(token, "OCR: PDF text unavailable, using Veryfi...");
        setOcrProgressForToken(token, { active: true, indeterminate: true });
        try {
          const { text, suggestions } = await runVeryfiOcrForFile(state.currentFile);
          if (!isActiveToken(token)) return;
          state.ocrText = text;
          state.ocrSuggestions = suggestions || null;
          if (state.ocrSuggestions) {
            applySuggestions();
            const summary = buildOcrSummary(state.ocrSuggestions);
            setOcrStatusForToken(token, summary ? `OCR: applied. ${summary}` : "OCR: applied.");
          } else {
            setOcrStatusForToken(token, "OCR: no suggestions.");
          }
          setOcrProgressForToken(token, { active: false });
          return;
        } catch (veryfiError) {
          if (!isActiveToken(token)) return;
          setOcrStatusForToken(token, `OCR: ${veryfiError.message}`);
          setOcrProgressForToken(token, { active: false });
          logClientError("Veryfi OCR failed after PDF missing", { error: veryfiError.message });
          return;
        }
      }
      setOcrStatusForToken(token, "OCR: PDF text unavailable.");
      setOcrProgressForToken(token, { active: false });
      return;
    }
    try {
      const text = await extractPdfText(state.currentFile, token);
      if (!isActiveToken(token)) return;
      state.ocrText = text;
      state.ocrSuggestions = buildOcrSuggestions(text);
      if (state.ocrSuggestions) {
        applySuggestions();
        const summary = buildOcrSummary(state.ocrSuggestions);
        setOcrStatusForToken(token, summary ? `OCR: applied. ${summary}` : "OCR: applied.");
      } else {
        setOcrStatusForToken(token, "OCR: no suggestions.");
      }
      setOcrProgressForToken(token, { active: false });
    } catch (error) {
      if (!isActiveToken(token)) return;
      if (canVeryfi) {
        setOcrStatusForToken(token, "OCR: PDF text failed, trying Veryfi...");
        setOcrProgressForToken(token, { active: true, indeterminate: true });
        try {
          const { text, suggestions } = await runVeryfiOcrForFile(state.currentFile);
          if (!isActiveToken(token)) return;
          state.ocrText = text;
          state.ocrSuggestions = suggestions || null;
          if (state.ocrSuggestions) {
            applySuggestions();
            const summary = buildOcrSummary(state.ocrSuggestions);
            setOcrStatusForToken(token, summary ? `OCR: applied. ${summary}` : "OCR: applied.");
          } else {
            setOcrStatusForToken(token, "OCR: no suggestions.");
          }
          setOcrProgressForToken(token, { active: false });
          return;
        } catch (veryfiError) {
          if (!isActiveToken(token)) return;
          setOcrStatusForToken(token, `OCR: ${veryfiError.message}`);
          setOcrProgressForToken(token, { active: false });
          logClientError("Veryfi OCR failed after PDF fallback", { error: veryfiError.message });
          return;
        }
      }
      setOcrStatusForToken(token, `OCR: ${error.message}`);
      setOcrProgressForToken(token, { active: false });
      logClientError("PDF text extraction failed", { error: error.message });
    }
    return;
  }

  if ((override === "veryfi" || override === "auto") && canVeryfi) {
    setOcrStatusForToken(token, "OCR: running (Veryfi)...");
    setOcrProgressForToken(token, { active: true, indeterminate: true });
    try {
      const { text, suggestions } = await runVeryfiOcrForFile(state.currentFile);
      if (!isActiveToken(token)) return;
      state.ocrText = text;
      state.ocrSuggestions = suggestions || null;
      if (state.ocrSuggestions) {
        applySuggestions();
        const summary = buildOcrSummary(state.ocrSuggestions);
        setOcrStatusForToken(token, summary ? `OCR: applied. ${summary}` : "OCR: applied.");
      } else {
        setOcrStatusForToken(token, "OCR: no suggestions.");
      }
      setOcrProgressForToken(token, { active: false });
    } catch (error) {
      if (!isActiveToken(token)) return;
      setOcrStatusForToken(token, `OCR: ${error.message}`);
      setOcrProgressForToken(token, { active: false });
      logClientError("Veryfi OCR failed", { error: error.message });
    }
    return;
  }

  if ((override === "local" || override === "auto") && shouldRunLocalOcr()) {
    const reason = getVeryfiBlockReason();
    const status = reason ? `OCR: using local (${reason})...` : "OCR: running locally...";
    setOcrStatusForToken(token, status);
    try {
      const { text, suggestions } = await runLocalOcrForFile(state.currentFile, token);
      if (!isActiveToken(token)) return;
      state.ocrText = text;
      state.ocrSuggestions = suggestions || null;
      if (state.ocrSuggestions) {
        applySuggestions();
        const summary = buildOcrSummary(state.ocrSuggestions);
        setOcrStatusForToken(token, summary ? `OCR: applied. ${summary}` : "OCR: applied.");
      } else {
        setOcrStatusForToken(token, "OCR: no suggestions.");
      }
      setOcrProgressForToken(token, { active: false });
    } catch (error) {
      if (!isActiveToken(token)) return;
      setOcrStatusForToken(token, `OCR: ${error.message}`);
      setOcrProgressForToken(token, { active: false });
      logClientError("Local OCR failed", { error: error.message });
    }
    return;
  }

  const reason = getVeryfiBlockReason();
  setOcrStatusForToken(token, reason ? `OCR: ${reason}` : "OCR: unavailable.");
  setOcrProgressForToken(token, { active: false });
}

function applyOcrToBulkItem(item, suggestions) {
  if (!suggestions) return;
  const highlights = {};
  if (suggestions.date) {
    item.date = suggestions.date;
    highlights.date = true;
  }
  if (suggestions.vendor) {
    item.vendor = suggestions.vendor;
    highlights.vendor = true;
  }
  if (suggestions.location) {
    item.location = suggestions.location;
    highlights.location = true;
  }
  if (suggestions.total !== null && suggestions.total !== undefined) {
    const value = Number(suggestions.total);
    if (Number.isFinite(value)) {
      item.total = value.toFixed(2);
      highlights.total = true;
    }
  }
  if (Object.keys(highlights).length > 0) {
    item.ocrHighlights = highlights;
    item.ocrHighlightUntil = Date.now() + 5000;
  }
}

function updateStats(receipts) {
  const total = receipts.reduce((sum, receipt) => {
    const value = Number(receipt.total);
    return sum + (Number.isFinite(value) ? value : 0);
  }, 0);
  elements.receiptCount.textContent = receipts.length.toString();
  elements.receiptTotal.textContent = formatCurrency(total);
}

function updateSelectionUI() {
  if (!elements.deleteSelected || !elements.selectAll || !elements.selectionStatus) return;
  const count = state.selectedIds.size;
  elements.deleteSelected.disabled = count === 0;
  elements.deleteSelected.textContent = count > 0 ? `Delete selected (${count})` : "Delete selected";
  elements.selectionStatus.textContent = count > 0 ? `${count} selected` : "";

  const visibleIds = state.visibleIds || [];
  if (visibleIds.length === 0) {
    elements.selectAll.checked = false;
    elements.selectAll.indeterminate = false;
    elements.selectAll.disabled = true;
    return;
  }

  const selectedOnPage = visibleIds.filter((id) => state.selectedIds.has(id)).length;
  elements.selectAll.disabled = false;
  elements.selectAll.checked = selectedOnPage === visibleIds.length;
  elements.selectAll.indeterminate = selectedOnPage > 0 && selectedOnPage < visibleIds.length;
}

function toggleSelection(id, selected) {
  if (!id) return;
  if (selected) {
    state.selectedIds.add(id);
  } else {
    state.selectedIds.delete(id);
  }
  updateSelectionUI();
}

function closeReceiptModal() {
  if (!elements.imageModal) return;
  elements.imageModal.classList.remove("active");
  elements.imageModal.setAttribute("aria-hidden", "true");
  if (elements.modalImage) {
    elements.modalImage.src = "";
    elements.modalImage.style.display = "block";
  }
  if (elements.modalMeta) {
    elements.modalMeta.textContent = "";
  }
  if (elements.modalStatus) {
    elements.modalStatus.textContent = "";
  }
  if (elements.modalDate) elements.modalDate.value = "";
  if (elements.modalVendor) elements.modalVendor.value = "";
  if (elements.modalLocation) elements.modalLocation.value = "";
  if (elements.modalCategory) elements.modalCategory.value = "";
  if (elements.modalPurpose) elements.modalPurpose.value = "";
  if (elements.modalTotal) elements.modalTotal.value = "";
  state.modalReceipt = null;
  if (state.modalUrl) {
    URL.revokeObjectURL(state.modalUrl);
    state.modalUrl = null;
  }
}

function openReceiptModal(receipt) {
  if (!elements.imageModal || !elements.modalImage) return;
  closeReceiptModal();
  state.modalReceipt = receipt;
  let url = "";
  if (receipt.imageUrl) {
    url = receipt.imageUrl;
  } else if (receipt.image) {
    url = URL.createObjectURL(receipt.image);
    state.modalUrl = url;
  }
  if (url) {
    elements.modalImage.src = url;
    elements.modalImage.style.display = "block";
  } else {
    elements.modalImage.style.display = "none";
  }
  const metaParts = [];
  if (receipt.vendor) metaParts.push(receipt.vendor);
  if (receipt.category) metaParts.push(receipt.category);
  if (receipt.businessPurpose) metaParts.push(receipt.businessPurpose);
  if (receipt.date) metaParts.push(receipt.date);
  if (receipt.total != null) metaParts.push(formatCurrency(Number(receipt.total)));
  if (elements.modalMeta) {
    elements.modalMeta.textContent = metaParts.join(" · ");
  }
  if (elements.modalDate) elements.modalDate.value = receipt.date || "";
  if (elements.modalVendor) elements.modalVendor.value = receipt.vendor || "";
  if (elements.modalLocation) elements.modalLocation.value = receipt.location || "";
  if (elements.modalCategory) elements.modalCategory.value = receipt.category || "";
  if (elements.modalPurpose) elements.modalPurpose.value = receipt.businessPurpose || "";
  if (elements.modalTotal) {
    elements.modalTotal.value =
      Number.isFinite(Number(receipt.total)) ? Number(receipt.total).toFixed(2) : "";
  }
  elements.imageModal.classList.add("active");
  elements.imageModal.setAttribute("aria-hidden", "false");
}

function getReceiptYear(receipt) {
  const source = receipt.date || receipt.createdAt || "";
  const year = source.slice(0, 4);
  return /^\d{4}$/.test(year) ? year : null;
}

function getYearOptions(receipts) {
  const years = new Set(receipts.map(getReceiptYear).filter(Boolean));
  years.add(CURRENT_YEAR);
  if (state.currentYear !== "all") {
    years.add(state.currentYear);
  }
  return Array.from(years).sort((a, b) => b.localeCompare(a));
}

function updateYearFilters(receipts) {
  const years = getYearOptions(receipts);
  const options = ["all", ...years];

  if (state.currentYear !== "all" && !years.includes(state.currentYear)) {
    state.currentYear = CURRENT_YEAR;
    state.currentPage = 1;
  }

  if (elements.yearFilters) {
    elements.yearFilters.innerHTML = "";
    options.forEach((year) => {
      const button = document.createElement("button");
      button.type = "button";
      button.className = "year-pill";
      button.dataset.year = year;
      button.textContent = year === "all" ? "All" : year;
      if (state.currentYear === year) {
        button.classList.add("active");
      }
      button.addEventListener("click", () => {
        state.currentYear = year;
        state.currentPage = 1;
        refreshList();
      });
      elements.yearFilters.append(button);
    });
  }

  if (elements.yearSelect) {
    elements.yearSelect.innerHTML = "";
    options.forEach((year) => {
      const option = document.createElement("option");
      option.value = year;
      option.textContent = year === "all" ? "All years" : year;
      elements.yearSelect.append(option);
    });
    elements.yearSelect.value = state.currentYear;
  }
}

function updatePagination(totalCount, totalPages) {
  if (!elements.pageInfo || !elements.prevPage || !elements.nextPage) return;
  if (totalCount === 0) {
    elements.pageInfo.textContent = "No receipts";
  } else {
    elements.pageInfo.textContent = `Page ${state.currentPage} of ${totalPages}`;
  }
  elements.prevPage.disabled = state.currentPage <= 1;
  elements.nextPage.disabled = state.currentPage >= totalPages;
}

function buildReceiptRow(receipt) {
  const row = document.createElement("tr");
  row.className = "receipt-row";
  row.tabIndex = 0;

  const checkboxCell = document.createElement("td");
  checkboxCell.className = "checkbox-col";
  const checkbox = document.createElement("input");
  checkbox.type = "checkbox";
  checkbox.checked = state.selectedIds.has(receipt.id);
  checkbox.addEventListener("click", (event) => {
    event.stopPropagation();
  });
  checkbox.addEventListener("change", () => {
    toggleSelection(receipt.id, checkbox.checked);
  });
  checkboxCell.append(checkbox);

  const dateCell = document.createElement("td");
  dateCell.textContent = formatShortDate(receipt.date);

  const vendorCell = document.createElement("td");
  vendorCell.textContent = receipt.vendor || "Unknown vendor";

  const categoryCell = document.createElement("td");
  categoryCell.textContent = receipt.category || "—";

  const totalCell = document.createElement("td");
  totalCell.className = "amount";
  totalCell.textContent = formatCurrency(Number(receipt.total) || 0);

  row.append(checkboxCell, dateCell, vendorCell, categoryCell, totalCell);

  row.addEventListener("click", () => openReceiptModal(receipt));
  row.addEventListener("keydown", (event) => {
    if (event.key === "Enter" || event.key === " ") {
      event.preventDefault();
      openReceiptModal(receipt);
    }
  });

  return row;
}

async function refreshList() {
  const receipts = (await getAllReceipts()) || [];
  const allIds = new Set(receipts.map((receipt) => receipt.id).filter(Boolean));
  state.selectedIds.forEach((id) => {
    if (!allIds.has(id)) {
      state.selectedIds.delete(id);
    }
  });
  receipts.sort((a, b) => {
    const aKey = a.date || a.createdAt || "";
    const bKey = b.date || b.createdAt || "";
    return bKey.localeCompare(aKey);
  });

  updateYearFilters(receipts);

  const yearFiltered =
    state.currentYear === "all"
      ? receipts
      : receipts.filter((receipt) => getReceiptYear(receipt) === state.currentYear);

  updateStats(yearFiltered);

  const query = elements.searchInput.value.trim().toLowerCase();
  let filtered = yearFiltered.filter((receipt) => {
    if (!query) return true;
    const haystack = `${receipt.vendor || ""} ${receipt.location || ""}`.toLowerCase();
    return haystack.includes(query);
  });

  const totalPages = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE));
  if (state.currentPage > totalPages) {
    state.currentPage = totalPages;
  }

  const start = (state.currentPage - 1) * PAGE_SIZE;
  const pageItems = filtered.slice(start, start + PAGE_SIZE);
  state.visibleIds = pageItems.map((receipt) => receipt.id).filter(Boolean);

  if (elements.receiptTableBody) {
    elements.receiptTableBody.innerHTML = "";
  }

  if (pageItems.length === 0) {
    elements.emptyState.style.display = "block";
    if (elements.receiptsTable) {
      elements.receiptsTable.style.display = "none";
    }
    updatePagination(filtered.length, totalPages);
    updateSelectionUI();
    return;
  }

  elements.emptyState.style.display = "none";
  if (elements.receiptsTable) {
    elements.receiptsTable.style.display = "block";
  }

  pageItems.forEach((receipt) => {
    if (elements.receiptTableBody) {
      elements.receiptTableBody.append(buildReceiptRow(receipt));
    }
  });

  updatePagination(filtered.length, totalPages);
  updateSelectionUI();
}

function resetForm() {
  state.currentFile = null;
  state.processToken += 1;
  elements.receiptForm.reset();
  if (elements.receiptImage) elements.receiptImage.value = "";
  elements.receiptDate.value = todayISO();
  if (elements.receiptCategory) elements.receiptCategory.value = "";
  clearOcrHighlight(elements.receiptDate);
  clearOcrHighlight(elements.receiptVendor);
  clearOcrHighlight(elements.receiptLocation);
  clearOcrHighlight(elements.receiptPurpose);
  clearOcrHighlight(elements.receiptTotalInput);
  clearPreview();
  resetOcrState();
}

async function processSingleFile(file) {
  if (!file) return;
  const token = state.processToken + 1;
  state.processToken = token;
  state.currentFile = null;
  clearPreview();
  if (isPdfFile(file)) {
    state.currentFile = file;
    setPdfPreview(file);
    resetOcrState();
    void autoRunOcrForCurrentFile(token);
    return;
  }
  setPreviewMessage("Creating B/W image...");
  try {
    const processed = await buildBwFile(file);
    if (state.processToken !== token) return;
    state.currentFile = processed;
    setPreview(processed, `${processed.name} · ${formatSize(processed.size)} (B/W)`);
  } catch (error) {
    if (state.processToken !== token) return;
    state.currentFile = null;
    clearPreview();
    setPreviewMessage("B/W processing failed. Please try another image.");
  }
  resetOcrState();
  void autoRunOcrForCurrentFile(token);
}

async function handleSingleDrop(files) {
  const file = pickFirstReceiptFile(files);
  if (!file) {
    setPreviewMessage("Please drop an image or PDF file.");
    setOcrStatus("");
    return;
  }
  if (elements.receiptImage) elements.receiptImage.value = "";
  await processSingleFile(file);
}

async function exportCsv() {
  const receipts = (await getAllReceipts()) || [];
  const scoped =
    state.currentYear === "all"
      ? receipts
      : receipts.filter((receipt) => getReceiptYear(receipt) === state.currentYear);
  if (scoped.length === 0) return;
  const header = ["Date", "Vendor", "Location", "Category", "Business Purpose", "Total"];
  let sumTotal = 0;
  const rows = scoped.map((receipt) => {
    const value = Number(receipt.total);
    if (Number.isFinite(value)) sumTotal += value;
    return [
      receipt.date || "",
      receipt.vendor || "",
      receipt.location || "",
      receipt.category || "",
      receipt.businessPurpose || "",
      formatCurrency(Number.isFinite(value) ? value : 0),
    ];
  });
  rows.push(["", "TOTAL", "", "", "", formatCurrency(sumTotal)]);
  const csv = [header, ...rows]
    .map((row) => row.map((cell) => `"${String(cell).replace(/"/g, '""')}"`).join(","))
    .join("\n");
  const blob = new Blob([csv], { type: "text/csv" });
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  const yearLabel = state.currentYear === "all" ? "all" : state.currentYear;
  link.download = `receipt-log-${yearLabel}.csv`;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}

async function init() {
  elements.receiptDate.value = todayISO();
  clearPreview();
  await initStorage();
  if (storage.mode === "local" && !window.indexedDB) {
    setPreviewMessage("IndexedDB is not supported in this browser.");
    return;
  }
  storage.ocrOverride = loadOcrOverride();
  updateOcrRemaining();
  updateOcrStatusLabel();
  updateOcrToggleButton();
  populateCategorySelect(elements.receiptCategory);
  populateCategorySelect(elements.modalCategory);
  renderCategoryGuide();
  setCategoryGuideOpen(false);
  if (elements.toggleCategories) {
    elements.toggleCategories.addEventListener("click", () => {
      const isOpen = elements.categoryGuide ? !elements.categoryGuide.hidden : false;
      setCategoryGuideOpen(!isOpen);
    });
  }
  if (elements.ocrTypeToggle) {
    elements.ocrTypeToggle.addEventListener("click", () => {
      const override = storage.ocrOverride || "auto";
      const veryfiAvailable = shouldRunVeryfi() || (isVeryfiConfigured() && !isVeryfiExhausted());
      if (override === "local") {
        if (veryfiAvailable) {
          setOcrOverride("veryfi");
        }
      } else {
        setOcrOverride("local");
      }
    });
  }

  document.addEventListener("dragover", (event) => {
    event.preventDefault();
  });

  document.addEventListener("drop", (event) => {
    event.preventDefault();
  });

  elements.receiptImage.addEventListener("change", async (event) => {
    const file = event.target.files[0];
    if (!file) {
      resetForm();
      return;
    }
    await processSingleFile(file);
  });

  setupDropTarget(elements.singleDrop, handleSingleDrop);
  setupDropTarget(elements.previewDrop, handleSingleDrop);
  setupDropTarget(elements.bulkDrop, (files) => addBulkFiles(files));

  if (elements.bulkReceiptImages) {
    elements.bulkReceiptImages.addEventListener("change", (event) => {
      void addBulkFiles(event.target.files);
      event.target.value = "";
    });
  }

  [
    elements.receiptDate,
    elements.receiptVendor,
    elements.receiptLocation,
    elements.receiptCategory,
    elements.receiptPurpose,
    elements.receiptTotalInput,
  ].forEach(
    (input) => {
      if (!input) return;
      input.addEventListener("input", () => clearOcrHighlight(input));
    }
  );

  attachZoomHandlers(elements.previewDrop, elements.previewImage, previewZoom);

  elements.receiptForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (!state.currentFile) {
      setPreviewMessage("Please add an image before saving.");
      return;
    }
    const businessPurpose = elements.receiptPurpose.value.trim();
    if (!businessPurpose) {
      setPreviewMessage("Please enter a business purpose.");
      return;
    }

    const category = elements.receiptCategory.value.trim();
    if (!category) {
      setPreviewMessage("Please select a category.");
      return;
    }

    const receipt = {
      id: generateId(),
      date: elements.receiptDate.value,
      vendor: elements.receiptVendor.value.trim(),
      location: elements.receiptLocation.value.trim(),
      category,
      businessPurpose,
      total: parseFloat(elements.receiptTotalInput.value),
      createdAt: new Date().toISOString(),
      image: state.currentFile,
      ocrText: state.ocrText,
    };

    try {
      await addReceipt(receipt);
      resetForm();
      await refreshList();
    } catch (error) {
      setPreviewMessage(`Save failed: ${error.message}`);
      logClientError("Save receipt failed", { error: error.message });
    }
  });

  elements.resetForm.addEventListener("click", resetForm);
  elements.searchInput.addEventListener("input", () => {
    state.currentPage = 1;
    refreshList();
  });
  elements.exportCsv.addEventListener("click", exportCsv);

  if (elements.yearSelect) {
    elements.yearSelect.addEventListener("change", () => {
      state.currentYear = elements.yearSelect.value;
      state.currentPage = 1;
      refreshList();
    });
  }

  if (elements.selectAll) {
    elements.selectAll.addEventListener("change", () => {
      const ids = state.visibleIds || [];
      if (elements.selectAll.checked) {
        ids.forEach((id) => state.selectedIds.add(id));
      } else {
        ids.forEach((id) => state.selectedIds.delete(id));
      }
      refreshList();
    });
  }

  if (elements.deleteSelected) {
    elements.deleteSelected.addEventListener("click", async () => {
      const ids = Array.from(state.selectedIds);
      if (ids.length === 0) return;
      const confirmed = window.confirm(`Delete ${ids.length} receipt(s)?`);
      if (!confirmed) return;
      let failed = 0;
      const failedIds = [];
      for (const id of ids) {
        try {
          await deleteReceipt(id);
        } catch (error) {
          failed += 1;
          failedIds.push(id);
        }
      }
      state.selectedIds.clear();
      failedIds.forEach((id) => state.selectedIds.add(id));
      if (failed > 0 && elements.selectionStatus) {
        elements.selectionStatus.textContent = `Failed to delete ${failed} receipt(s).`;
      }
      await refreshList();
    });
  }

  if (elements.modalClose) {
    elements.modalClose.addEventListener("click", closeReceiptModal);
  }
  if (elements.modalBackdrop) {
    elements.modalBackdrop.addEventListener("click", closeReceiptModal);
  }
  if (elements.modalSave) {
    elements.modalSave.addEventListener("click", async () => {
      if (!state.modalReceipt) return;
      if (
        !elements.modalDate ||
        !elements.modalVendor ||
        !elements.modalCategory ||
        !elements.modalPurpose ||
        !elements.modalTotal
      ) {
        return;
      }

      const date = elements.modalDate.value;
      const vendor = elements.modalVendor.value.trim();
      const location = elements.modalLocation ? elements.modalLocation.value.trim() : "";
      const category = elements.modalCategory.value.trim();
      const businessPurpose = elements.modalPurpose.value.trim();
      const totalValue = Number(elements.modalTotal.value);

      if (!date || !vendor || !category || !businessPurpose || !Number.isFinite(totalValue)) {
        if (elements.modalStatus) {
          elements.modalStatus.textContent =
            "Please fill Date, Vendor, Category, Business Purpose, and Total.";
        }
        return;
      }

      const existing = state.modalReceipt;
      const updated = {
        id: existing.id,
        date,
        vendor,
        location,
        category,
        businessPurpose,
        total: totalValue,
        createdAt: existing.createdAt || new Date().toISOString(),
        image: existing.image || null,
        ocrText: existing.ocrText || "",
      };

      try {
        await addReceipt(updated);
        closeReceiptModal();
        await refreshList();
      } catch (error) {
        if (elements.modalStatus) {
          elements.modalStatus.textContent = `Save failed: ${error.message}`;
        }
        logClientError("Modal save failed", { error: error.message });
      }
    });
  }
  if (elements.modalDelete) {
    elements.modalDelete.addEventListener("click", async () => {
      if (!state.modalReceipt) return;
      const receipt = state.modalReceipt;
      const confirmed = window.confirm("Delete this receipt?");
      if (!confirmed) return;
      closeReceiptModal();
      await deleteReceipt(receipt.id);
      await refreshList();
    });
  }
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && elements.imageModal?.classList.contains("active")) {
      closeReceiptModal();
    }
  });

  if (elements.prevPage) {
    elements.prevPage.addEventListener("click", () => {
      state.currentPage = Math.max(1, state.currentPage - 1);
      refreshList();
    });
  }
  if (elements.nextPage) {
    elements.nextPage.addEventListener("click", () => {
      state.currentPage += 1;
      refreshList();
    });
  }

  if (elements.bulkSaveAll) {
    elements.bulkSaveAll.addEventListener("click", saveBulkReceipts);
  }
  if (elements.bulkClear) {
    elements.bulkClear.addEventListener("click", clearBulkQueue);
  }

  updateBulkControls();

  await refreshList();
}

init().catch((error) => {
  setPreviewMessage(`Initialization failed: ${error.message}`);
  logClientError("Initialization failed", { error: error.message, stack: error.stack || "" });
});

window.addEventListener("error", (event) => {
  const error = event.error;
  logClientError(event.message || "Unhandled error", {
    filename: event.filename || "",
    lineno: event.lineno || 0,
    colno: event.colno || 0,
    stack: error && error.stack ? error.stack : "",
  });
});

window.addEventListener("unhandledrejection", (event) => {
  const reason = event.reason;
  logClientError("Unhandled promise rejection", {
    reason: reason && reason.message ? reason.message : String(reason || ""),
    stack: reason && reason.stack ? reason.stack : "",
  });
});
