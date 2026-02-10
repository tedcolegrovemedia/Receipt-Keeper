function normalizeText(value) {
  return String(value || "").toLowerCase();
}

function normalizeLine(value) {
  return String(value || "")
    .toLowerCase()
    .replace(/\s+/g, " ")
    .trim();
}

function normalizeVendorKey(value) {
  return normalizeLine(value).replace(/[^a-z0-9]+/g, " ").trim();
}

const COMMON_VENDOR_TOKENS = new Set([
  "inc",
  "llc",
  "co",
  "corp",
  "corporation",
  "company",
  "ltd",
  "limited",
  "gmbh",
  "sarl",
  "sa",
  "plc",
  "bv",
  "oy",
  "ab",
  "ag",
  "kg",
  "pte",
  "llp",
  "group",
  "holdings",
]);

function extractVendorSignals(text, vendor) {
  if (!text || !vendor) return null;
  const lines = text
    .split(/\r?\n/)
    .map((line) => line.replace(/\s+/g, " ").trim())
    .filter((line) => line.length > 2);
  const addressRegex =
    /\b(\d{1,5}\s+\S+|street|st\.|avenue|ave\.|road|rd\.|boulevard|blvd\.|lane|ln\.|drive|dr\.|suite|ste\.|floor|fl\.|stra(?:ss|\u00df)e|str\.)\b/i;
  const cityStateRegex = /\b[A-Za-z .'-]+,\s*[A-Z]{2}\b/;
  const postalRegex = /\b\d{5}(?:-\d{4})?\b/;

  const domains = new Set();
  const emailRegex = /[A-Z0-9._%+-]+@([A-Z0-9.-]+\.[A-Z]{2,})/gi;
  const urlRegex = /(https?:\/\/)?(www\.)?([A-Z0-9.-]+\.[A-Z]{2,})/gi;

  let match;
  while ((match = emailRegex.exec(text)) !== null) {
    domains.add(match[1].toLowerCase());
  }
  while ((match = urlRegex.exec(text)) !== null) {
    if (match[3]) domains.add(match[3].toLowerCase());
  }

  const addressLines = [];
  lines.forEach((line) => {
    if (addressLines.length >= 4) return;
    if (line.length > 120) return;
    if (addressRegex.test(line) || cityStateRegex.test(line) || postalRegex.test(line)) {
      addressLines.push(normalizeLine(line));
    }
  });

  const topLines = lines
    .slice(0, 8)
    .map((line) => normalizeLine(line))
    .filter((line) => line.length > 0 && line.length <= 120);

  const tokens = normalizeVendorKey(vendor)
    .split(/\s+/)
    .map((token) => token.trim())
    .filter((token) => token.length > 2 && !COMMON_VENDOR_TOKENS.has(token));

  return {
    vendor,
    domains: Array.from(domains),
    addresses: addressLines,
    lines: Array.from(new Set([normalizeLine(vendor), ...topLines])),
    tokens,
  };
}

function matchVendorFromMemory(text, memory) {
  if (!text || !Array.isArray(memory) || memory.length === 0) return null;
  const normalizedText = normalizeText(text);
  const lines = text
    .split(/\r?\n/)
    .map((line) => normalizeLine(line))
    .filter(Boolean);

  let best = null;
  memory.forEach((entry) => {
    if (!entry || !entry.vendor) return;
    let score = 0;
    const domains = Array.isArray(entry.domains) ? entry.domains : [];
    const addresses = (Array.isArray(entry.addresses) ? entry.addresses : []).filter(
      (addr) => addr && addr.length <= 120
    );
    const snippets = (Array.isArray(entry.lines) ? entry.lines : []).filter(
      (snippet) => snippet && snippet.length <= 120
    );
    const tokens = (Array.isArray(entry.tokens) ? entry.tokens : []).filter(
      (token) => token && token.length > 2 && !COMMON_VENDOR_TOKENS.has(token)
    );
    const vendorName = normalizeLine(entry.vendor);
    const vendorKey = entry.key ? normalizeLine(entry.key) : normalizeVendorKey(entry.vendor);

    if (vendorName && normalizedText.includes(vendorName)) score += 4;
    if (vendorKey && normalizedText.includes(vendorKey)) score += 3;
    if (domains.some((domain) => normalizedText.includes(domain))) score += 5;
    if (addresses.some((addr) => normalizedText.includes(addr))) score += 3;
    if (snippets.some((snippet) => normalizedText.includes(snippet))) score += 2;
    if (tokens.length > 0) {
      const matched = tokens.filter((token) => normalizedText.includes(token));
      const strong = matched.filter((token) => token.length >= 4);
      if (strong.length > 0) score += 2;
      if (matched.length === tokens.length && tokens.length > 0) score += 1;
    }
    if (typeof entry.count === "number" && entry.count > 2) score += 1;
    if (score <= 0) return;

    if (!best || score > best.score) {
      best = { vendor: entry.vendor, score };
    }
  });

  if (best && best.score >= 3) {
    return best.vendor;
  }
  return null;
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
    /(summary|invoice|receipt|statement|order|status|delivery|subtotal|total|amount|balance|tax|change|payment|paid|date|due|billing|billed|shipping|ship|qty|quantity|item|items|description|plan|subscription|service|card|visa|mastercard|amex|cash|paypal|transaction|fee|reference|number|id|vat|email)/i;
  const hardSkipRegex =
    /(order number|order date|order status|delivery on|delivery on or before|tracking number|shipment)/i;
  const summaryLineRegex = /^summary\b/i;
  const companyRegex =
    /\b(inc|llc|l\.l\.c\.|corp|corporation|company|co\.|ltd|limited|gmbh|sarl|sa|plc|bv|oy|ab|ag|kg|pte|llp)\b/i;
  const emailRegex = /@/;
  const urlRegex = /(https?:\/\/|www\.)/i;
  const addressRegex =
    /\b(\d{1,5}\s+\S+|street|st\.|avenue|ave\.|road|rd\.|boulevard|blvd\.|lane|ln\.|drive|dr\.|suite|ste\.|floor|fl\.|stra(?:ss|\u00df)e|str\.)\b/i;
  const postalRegex = /\b\d{5}(?:-\d{4})?\b/;

  const cityStateRegex = /\b[A-Za-z .'-]+,\s*[A-Z]{2}\b/;
  const isAddress = (line) =>
    addressRegex.test(line) || cityStateRegex.test(line) || postalRegex.test(line);
  const stripPrefixes = (line) =>
    line
      .replace(
        /^(invoice|invoice number|date paid|date|paid|payment|bill to|sold by|from|vendor|merchant|seller|sold to|billed to)\b[:\s-]*/i,
        ""
      )
      .trim();
  const stripAddressTail = (line) => {
    const match = line.match(addressRegex);
    if (match && match.index !== undefined && match.index > 0) {
      return line.slice(0, match.index).trim();
    }
    return line;
  };
  const normalizeCandidate = (line) => {
    let candidate = stripPrefixes(line);
    candidate = stripAddressTail(candidate);
    candidate = candidate.replace(/\s{2,}/g, " ").trim();
    return candidate;
  };
  const isAllCaps = (value) => /^[A-Z0-9 &.,'-]+$/.test(value);
  const isTitleCase = (value) => {
    const words = value.split(/\s+/).filter(Boolean);
    if (words.length < 2) return false;
    let score = 0;
    words.forEach((word) => {
      if (/^[A-Z]/.test(word)) score += 1;
    });
    return score / words.length >= 0.6;
  };
  const hasLetters = (value) => /[A-Za-z]/.test(value);
  const scoreLine = (line, index, addressLineIndexes) => {
    if (hardSkipRegex.test(line)) return null;
    if (summaryLineRegex.test(line) && !companyRegex.test(line)) return null;
    const cleaned = normalizeCandidate(line);
    if (!cleaned || cleaned.length < 2 || cleaned.length > 80) return null;
    if (!hasLetters(cleaned)) return null;
    if (emailRegex.test(cleaned) || urlRegex.test(cleaned)) return null;
    const labelMatches = cleaned.match(/\b[A-Za-z][A-Za-z &.]{2,}\s*:\s*\S+/g) || [];
    if (labelMatches.length >= 2 && !companyRegex.test(cleaned)) return null;
    const hasCompany = companyRegex.test(cleaned);
    const skipMatch = skipRegex.test(cleaned);
    let score = 0;
    if (line !== cleaned) score += 3;
    if (hasCompany) score += 3;
    if (isTitleCase(cleaned)) score += 2;
    if (isAllCaps(cleaned) && cleaned.length <= 40) score += 2;
    const wordCount = cleaned.split(/\s+/).length;
    if (wordCount >= 1 && wordCount <= 6) score += 1;
    if (wordCount === 1 && !hasCompany) score -= 1;
    if (index <= 2) score += 2;
    else if (index <= 5) score += 1;
    if (addressLineIndexes.has(index + 1) || addressLineIndexes.has(index + 2)) score += 2;
    if (skipMatch) score -= 2;
    if (isAddress(cleaned) || postalRegex.test(cleaned)) score -= 3;
    return { value: cleaned.slice(0, 40), score };
  };

  const addressLineIndexes = new Set();
  lines.forEach((line, index) => {
    if (isAddress(line)) {
      addressLineIndexes.add(index);
    }
  });

  let best = null;
  lines.forEach((line, index) => {
    const scored = scoreLine(line, index, addressLineIndexes);
    if (!scored) return;
    if (!best || scored.score > best.score) {
      best = scored;
    }
  });
  if (best && best.score > 0) {
    return best.value;
  }

  for (let i = 0; i < lines.length - 1; i += 1) {
    if (!skipRegex.test(lines[i])) continue;
    for (let j = i + 1; j < lines.length; j += 1) {
      const candidate = lines[j];
      if (!hasLetters(candidate)) continue;
      if (emailRegex.test(candidate) || urlRegex.test(candidate)) continue;
      if (isAddress(candidate)) continue;
      const cleaned = normalizeCandidate(candidate);
      if (cleaned) return cleaned.slice(0, 40);
    }
  }

  for (const line of lines) {
    if (!hasLetters(line)) continue;
    if (emailRegex.test(line) || urlRegex.test(line)) continue;
    if (isAddress(line)) continue;
    if (skipRegex.test(line)) continue;
    return normalizeCandidate(line).slice(0, 40);
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
  const memory = Array.isArray(window.vendorMemory) ? window.vendorMemory : [];
  const learnedVendor = matchVendorFromMemory(text, memory);
  const vendor = learnedVendor || parseVendorFromText(text);
  const location = parseLocationFromText(text);
  const suggestions = { date, total, vendor, location };
  const hasAny = Object.values(suggestions).some((value) => value !== null && value !== undefined);
  return hasAny ? suggestions : null;
}
