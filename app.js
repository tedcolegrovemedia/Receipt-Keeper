const DB_NAME = "receipt_logger";
const STORE_NAME = "receipts";
const DB_VERSION = 1;
const PAGE_SIZE = 10;
const OCR_MAX_DIM = 1600;

const CURRENT_YEAR = new Date().getFullYear().toString();

const state = {
  currentFile: null,
  currentFileProcessed: false,
  ocrText: "",
  ocrSuggestions: null,
  ocrLoaded: false,
  currentYear: CURRENT_YEAR,
  currentPage: 1,
  selectedIds: new Set(),
  visibleIds: [],
  processToken: 0,
  modalUrl: null,
  modalReceipt: null,
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
};

const bulkState = {
  items: [],
};

const elements = {
  receiptImage: document.getElementById("receiptImage"),
  previewImage: document.getElementById("previewImage"),
  previewPlaceholder: document.getElementById("previewPlaceholder"),
  previewMeta: document.getElementById("previewMeta"),
  previewHint: document.getElementById("previewHint"),
  singleDrop: document.getElementById("singleDrop"),
  previewDrop: document.getElementById("previewDrop"),
  ocrToggle: document.getElementById("ocrToggle"),
  runOcr: document.getElementById("runOcr"),
  applyOcr: document.getElementById("applyOcr"),
  ocrProgress: document.getElementById("ocrProgress"),
  ocrText: document.getElementById("ocrText"),
  ocrProvider: document.getElementById("ocrProvider"),
  veryfiBadge: document.getElementById("veryfiBadge"),
  receiptForm: document.getElementById("receiptForm"),
  receiptDate: document.getElementById("receiptDate"),
  receiptVendor: document.getElementById("receiptVendor"),
  receiptLocation: document.getElementById("receiptLocation"),
  receiptTotalInput: document.getElementById("receiptTotalInput"),
  saveReceipt: document.getElementById("saveReceipt"),
  resetForm: document.getElementById("resetForm"),
  receiptsTable: document.getElementById("receiptsTable"),
  receiptTableBody: document.getElementById("receiptTableBody"),
  emptyState: document.getElementById("emptyState"),
  receiptCount: document.getElementById("receiptCount"),
  receiptTotal: document.getElementById("receiptTotal"),
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

function isImageFile(file) {
  if (!file) return false;
  if (file.type && file.type.startsWith("image/")) return true;
  const ext = file.name ? file.name.split(".").pop().toLowerCase() : "";
  return ["jpg", "jpeg", "png", "webp", "heic", "heif", "gif", "tiff"].includes(ext);
}

function pickFirstImage(files) {
  const list = Array.from(files || []);
  return list.find((file) => isImageFile(file)) || null;
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
    } else {
      storage.mode = "local";
    }
  } catch (error) {
    storage.mode = "local";
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

    let ocrStatus = null;
    if (item.ocrMessage) {
      ocrStatus = document.createElement("div");
      ocrStatus.className = "bulk-ocr-status";
      ocrStatus.dataset.state = item.ocrStatus || "";
      ocrStatus.textContent = item.ocrMessage;
    }

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
    });
    locationLabel.append(locationInput);

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
      if (item.errors.length) {
        item.errors = [];
        card.classList.remove("invalid");
        errorMsg.textContent = "";
      }
    });
    totalLabel.append(totalInput);

    fields.append(dateLabel, vendorLabel, locationLabel, totalLabel);

    const errorMsg = document.createElement("div");
    errorMsg.className = "bulk-error";
    if (item.errors && item.errors.length > 0) {
      errorMsg.textContent = `Missing: ${item.errors.join(", ")}`;
    }

    if (ocrStatus) {
      card.append(header, ocrStatus, fields, errorMsg);
    } else {
      card.append(header, fields, errorMsg);
    }
    elements.bulkList.append(card);
  });
}

async function addBulkFiles(files) {
  const fileArray = Array.from(files || []);
  if (fileArray.length === 0) return;
  const imageFiles = fileArray.filter((file) => isImageFile(file));
  if (imageFiles.length === 0) return;
  setBulkStatus(`Processing ${imageFiles.length} receipt${imageFiles.length === 1 ? "" : "s"}...`);
  let processedCount = 0;
  let failedCount = 0;
  const ocrAvailable = storage.mode === "server" && storage.veryfiAvailable;
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
    if (!ocrAvailable) {
      item.ocrStatus = "skipped";
      item.ocrMessage =
        storage.mode === "server"
          ? "OCR: Veryfi not configured."
          : "OCR: unavailable in local mode.";
    } else if (typeof storage.veryfiRemaining === "number" && storage.veryfiRemaining <= 0) {
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
        const suggestions = await runVeryfiOcrForFile(processed);
        applyOcrToBulkItem(item, suggestions);
        if (suggestions && Object.keys(suggestions).length > 0) {
          item.ocrStatus = "applied";
          item.ocrMessage = "OCR: applied.";
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
    updateBulkControls("Fill in Date, Vendor, and Total for all queued receipts.");
    return;
  }

  const receipts = bulkState.items.map((item) => ({
    id: generateId(),
    date: item.date,
    vendor: item.vendor.trim(),
    location: item.location.trim(),
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

function clearPreview() {
  elements.previewImage.src = "";
  elements.previewImage.style.display = "none";
  elements.previewPlaceholder.style.display = "block";
  elements.previewMeta.textContent = "Choose a photo to preview.";
  if (elements.previewHint) elements.previewHint.classList.add("hidden");
  resetPreviewZoom();
}

function setPreview(file, metaText) {
  if (!file) {
    clearPreview();
    return;
  }
  const url = URL.createObjectURL(file);
  elements.previewImage.src = url;
  elements.previewImage.style.display = "block";
  elements.previewPlaceholder.style.display = "none";
  elements.previewImage.title = "Click to zoom: 2x, 3x";
  if (elements.previewHint) elements.previewHint.classList.remove("hidden");
  resetPreviewZoom();
  const name = file.name ? file.name : "receipt image";
  elements.previewMeta.textContent = metaText || `${name} · ${formatSize(file.size)}`;
  elements.previewImage.onload = () => {
    URL.revokeObjectURL(url);
    updatePreviewBaseSize();
  };
}

function resetOcrState() {
  state.ocrText = "";
  state.ocrSuggestions = null;
  elements.ocrText.value = "";
  elements.ocrProgress.textContent = "";
  elements.applyOcr.disabled = true;
}

function setOcrReadyState() {
  const enabled = elements.ocrToggle.checked && !!state.currentFile;
  let blocked = false;
  if (elements.ocrProvider) {
    const wantsVeryfi = elements.ocrProvider.value === "veryfi";
    if (wantsVeryfi) {
      if (!storage.veryfiAvailable) {
        elements.ocrProgress.textContent = "Veryfi not configured. Using local OCR.";
      } else if (
        typeof storage.veryfiRemaining === "number" &&
        storage.veryfiRemaining <= 0 &&
        storage.veryfiLimit &&
        storage.veryfiLimit > 0
      ) {
        blocked = true;
        elements.ocrProgress.textContent = `Veryfi monthly limit reached (${storage.veryfiLimit}). Switch to local OCR or try next month.`;
      }
    }
  }
  elements.runOcr.disabled = !enabled || blocked;
}

function setVeryfiBadge(text, state = "off") {
  if (!elements.veryfiBadge) return;
  elements.veryfiBadge.textContent = text;
  elements.veryfiBadge.dataset.state = state;
}

function updateVeryfiStatus() {
  if (!elements.veryfiBadge) return;
  if (storage.mode !== "server") {
    setVeryfiBadge("Veryfi: unavailable (local mode)", "off");
    return;
  }

  if (!storage.veryfiAvailable) {
    setVeryfiBadge("Veryfi: not configured", "warn");
    return;
  }

  if (storage.veryfiLimit && typeof storage.veryfiRemaining === "number") {
    const remaining = Math.max(0, storage.veryfiRemaining);
    setVeryfiBadge(`Veryfi: ${remaining}/${storage.veryfiLimit} left this month`, remaining > 0 ? "ok" : "error");
  } else {
    setVeryfiBadge("Veryfi: ready", "ok");
  }
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
  const patterns = [
    { regex: /\b(20\d{2}|19\d{2})[\/\-.](0?[1-9]|1[0-2])[\/\-.](0?[1-9]|[12]\d|3[01])\b/, order: "ymd" },
    { regex: /\b(0?[1-9]|1[0-2])[\/\-.](0?[1-9]|[12]\d|3[01])[\/\-.]((?:20)?\d{2})\b/, order: "mdy" },
  ];

  for (const pattern of patterns) {
    const match = text.match(pattern.regex);
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
    const date = new Date(year, month - 1, day);
    if (date.getMonth() + 1 !== month || date.getDate() !== day) continue;
    return `${year.toString().padStart(4, "0")}-${month
      .toString()
      .padStart(2, "0")}-${day.toString().padStart(2, "0")}`;
  }
  return null;
}

function parseVendorFromText(text) {
  const lines = text
    .split(/\r?\n/)
    .map((line) => line.replace(/\s+/g, " ").trim())
    .filter((line) => line.length > 2);
  const skipRegex = /(total|amount|balance|tax|change|visa|mastercard|amex|cash|subtotal)/i;
  for (const line of lines) {
    if (/[A-Za-z]/.test(line) && !skipRegex.test(line)) {
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

  const maybeSet = (input, value, label) => {
    if (!value) return;
    if (!input.value) {
      input.value = value;
      return;
    }
    if (input.value && input.value !== value) {
      input.value = value;
    }
  };

  maybeSet(elements.receiptDate, suggestions.date, "date");
  maybeSet(elements.receiptVendor, suggestions.vendor, "vendor");
  maybeSet(elements.receiptLocation, suggestions.location, "location");
  if (suggestions.total !== null && suggestions.total !== undefined) {
    maybeSet(elements.receiptTotalInput, suggestions.total.toFixed(2), "total");
  }
}

async function runOcr() {
  if (!state.currentFile) return;
  const provider = elements.ocrProvider ? elements.ocrProvider.value : "local";
  if (provider === "veryfi" && storage.veryfiAvailable) {
    await runVeryfiOcr();
  } else {
    await runLocalOcr();
  }
}

async function runLocalOcr() {
  elements.ocrProgress.textContent = "Loading OCR engine...";
  elements.runOcr.disabled = true;
  try {
    await loadTesseract();
    let source = state.currentFile;
    if (!state.currentFileProcessed) {
      elements.ocrProgress.textContent = "Preprocessing image...";
      try {
        source = await preprocessForOcr(state.currentFile);
      } catch (error) {
        elements.ocrProgress.textContent = "Preprocessing failed, using original image.";
      }
    } else {
      elements.ocrProgress.textContent = "Using B/W image...";
    }

    elements.ocrProgress.textContent = "Reading receipt...";
    const result = await Tesseract.recognize(source, "eng", {
      logger: (message) => {
        if (message.status === "recognizing text") {
          const pct = Math.round(message.progress * 100);
          elements.ocrProgress.textContent = `Recognizing text... ${pct}%`;
        }
      },
    });

    state.ocrText = result.data.text.trim();
    elements.ocrText.value = state.ocrText || "(No text detected)";
    state.ocrSuggestions = buildOcrSuggestions(state.ocrText);
    if (state.ocrSuggestions) {
      const summary = [];
      if (state.ocrSuggestions.date) summary.push(`Date: ${state.ocrSuggestions.date}`);
      if (state.ocrSuggestions.vendor) summary.push(`Vendor: ${state.ocrSuggestions.vendor}`);
      if (state.ocrSuggestions.location) summary.push(`Location: ${state.ocrSuggestions.location}`);
      if (state.ocrSuggestions.total !== null && state.ocrSuggestions.total !== undefined) {
        summary.push(`Total: ${formatCurrency(state.ocrSuggestions.total)}`);
      }
      elements.ocrProgress.textContent = `Suggestions ready. ${summary.join(" · ")}`;
      elements.applyOcr.disabled = false;
    } else {
      elements.ocrProgress.textContent = "OCR complete. No suggestions found.";
      elements.applyOcr.disabled = true;
    }
  } catch (error) {
    elements.ocrProgress.textContent = `OCR failed: ${error.message}`;
  } finally {
    elements.runOcr.disabled = false;
  }
}

async function runVeryfiOcr() {
  elements.ocrProgress.textContent = "Sending to Veryfi...";
  elements.runOcr.disabled = true;
  try {
    const formData = new FormData();
    let fileToSend = state.currentFile;
    if (!state.currentFileProcessed) {
      try {
        fileToSend = await buildBwFile(state.currentFile);
      } catch (error) {
        throw new Error("B/W processing failed. Please try another image.");
      }
    }
    formData.append("image", fileToSend);
    const data = await apiRequest("veryfi_ocr", { method: "POST", body: formData });

    state.ocrText = (data.text || "").trim();
    elements.ocrText.value = state.ocrText || "(No text detected)";
    state.ocrSuggestions = data.suggestions || null;
    if (typeof data.veryfiRemaining === "number") {
      storage.veryfiRemaining = data.veryfiRemaining;
    }
    if (typeof data.veryfiLimit === "number") {
      storage.veryfiLimit = data.veryfiLimit;
    }
    updateVeryfiStatus();

    if (state.ocrSuggestions) {
      const summary = [];
      if (state.ocrSuggestions.date) summary.push(`Date: ${state.ocrSuggestions.date}`);
      if (state.ocrSuggestions.vendor) summary.push(`Vendor: ${state.ocrSuggestions.vendor}`);
      if (state.ocrSuggestions.location) summary.push(`Location: ${state.ocrSuggestions.location}`);
      if (state.ocrSuggestions.total !== null && state.ocrSuggestions.total !== undefined) {
        summary.push(`Total: ${formatCurrency(state.ocrSuggestions.total)}`);
      }
      elements.ocrProgress.textContent = `Suggestions ready. ${summary.join(" · ")}`;
      elements.applyOcr.disabled = false;
    } else {
      elements.ocrProgress.textContent = "OCR complete. No suggestions found.";
      elements.applyOcr.disabled = true;
    }
  } catch (error) {
    elements.ocrProgress.textContent = `Veryfi OCR failed: ${error.message}`;
    updateVeryfiStatus();
  } finally {
    elements.runOcr.disabled = false;
  }
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
  updateVeryfiStatus();
  return data.suggestions || null;
}

function applyOcrToBulkItem(item, suggestions) {
  if (!suggestions) return;
  if (suggestions.date) item.date = suggestions.date;
  if (suggestions.vendor) item.vendor = suggestions.vendor;
  if (suggestions.location) item.location = suggestions.location;
  if (suggestions.total !== null && suggestions.total !== undefined) {
    const value = Number(suggestions.total);
    if (Number.isFinite(value)) {
      item.total = value.toFixed(2);
    }
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
  if (receipt.date) metaParts.push(receipt.date);
  if (receipt.total != null) metaParts.push(formatCurrency(Number(receipt.total)));
  if (elements.modalMeta) {
    elements.modalMeta.textContent = metaParts.join(" · ");
  }
  if (elements.modalDate) elements.modalDate.value = receipt.date || "";
  if (elements.modalVendor) elements.modalVendor.value = receipt.vendor || "";
  if (elements.modalLocation) elements.modalLocation.value = receipt.location || "";
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

  const totalCell = document.createElement("td");
  totalCell.className = "amount";
  totalCell.textContent = formatCurrency(Number(receipt.total) || 0);

  row.append(checkboxCell, dateCell, vendorCell, totalCell);

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
  state.currentFileProcessed = false;
  state.processToken += 1;
  elements.receiptForm.reset();
  if (elements.receiptImage) elements.receiptImage.value = "";
  elements.receiptDate.value = todayISO();
  clearPreview();
  resetOcrState();
  setOcrReadyState();
}

async function processSingleFile(file) {
  if (!file) return;
  const token = state.processToken + 1;
  state.processToken = token;
  state.currentFile = null;
  state.currentFileProcessed = false;
  clearPreview();
  elements.previewMeta.textContent = "Creating B/W image...";
  try {
    const processed = await buildBwFile(file);
    if (state.processToken !== token) return;
    state.currentFile = processed;
    state.currentFileProcessed = true;
    setPreview(processed, `${processed.name} · ${formatSize(processed.size)} (B/W)`);
  } catch (error) {
    if (state.processToken !== token) return;
    state.currentFile = null;
    state.currentFileProcessed = false;
    clearPreview();
    elements.previewMeta.textContent = "B/W processing failed. Please try another image.";
  }
  resetOcrState();
  setOcrReadyState();
}

async function handleSingleDrop(files) {
  const file = pickFirstImage(files);
  if (!file) {
    elements.previewMeta.textContent = "Please drop an image file.";
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
  const header = ["Date", "Vendor", "Location", "Total"];
  let sumTotal = 0;
  const rows = scoped.map((receipt) => {
    const value = Number(receipt.total);
    if (Number.isFinite(value)) sumTotal += value;
    return [
      receipt.date || "",
      receipt.vendor || "",
      receipt.location || "",
      formatCurrency(Number.isFinite(value) ? value : 0),
    ];
  });
  rows.push(["", "TOTAL", "", formatCurrency(sumTotal)]);
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
    elements.previewMeta.textContent = "IndexedDB is not supported in this browser.";
    return;
  }
  if (elements.ocrProvider) {
    if (storage.veryfiAvailable) {
      elements.ocrProvider.value = "veryfi";
    } else {
      elements.ocrProvider.value = "local";
    }
  }
  updateVeryfiStatus();

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

  elements.ocrToggle.addEventListener("change", () => {
    setOcrReadyState();
  });
  if (elements.ocrProvider) {
    elements.ocrProvider.addEventListener("change", () => {
      setOcrReadyState();
      updateVeryfiStatus();
    });
  }
  attachZoomHandlers(elements.previewDrop, elements.previewImage, previewZoom);

  elements.runOcr.addEventListener("click", runOcr);
  elements.applyOcr.addEventListener("click", applySuggestions);

  elements.receiptForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (!state.currentFile) {
      elements.previewMeta.textContent = "Please add an image before saving.";
      return;
    }

    const receipt = {
      id: generateId(),
      date: elements.receiptDate.value,
      vendor: elements.receiptVendor.value.trim(),
      location: elements.receiptLocation.value.trim(),
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
      elements.previewMeta.textContent = `Save failed: ${error.message}`;
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
      if (!elements.modalDate || !elements.modalVendor || !elements.modalTotal) return;

      const date = elements.modalDate.value;
      const vendor = elements.modalVendor.value.trim();
      const location = elements.modalLocation ? elements.modalLocation.value.trim() : "";
      const totalValue = Number(elements.modalTotal.value);

      if (!date || !vendor || !Number.isFinite(totalValue)) {
        if (elements.modalStatus) {
          elements.modalStatus.textContent = "Please fill Date, Vendor, and Total.";
        }
        return;
      }

      const existing = state.modalReceipt;
      const updated = {
        id: existing.id,
        date,
        vendor,
        location,
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
  elements.previewMeta.textContent = `Initialization failed: ${error.message}`;
});
