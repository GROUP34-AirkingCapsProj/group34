const path = require('path');
const fs = require('fs');
const { stringify } = require('csv-stringify/sync');
const { createWorker } = require('tesseract.js');

/**
 * OCR the provided inventory-sheet images into a structured CSV.
 *
 * Output CSV columns:
 *  - category_name
 *  - brand_name
 *  - product_type (appliance|consumable)
 *  - model_code
 *  - capacity_or_variant
 *  - unit_hint (Unit|Box|Roll|Pack|etc)
 *  - total_on_hand
 *
 * Notes:
 * - This script intentionally ignores per-branch columns and uses TOTAL ON-HAND only.
 * - The source images have strong lines; OCR may require a couple runs to perfect.
 * - Any row that cannot be confidently parsed is written to a "review" file.
 */

function detectImagePaths() {
  // Prefer Cursor project's assets folder (where images are saved in this environment)
  const cursorAssets =
    process.env.CURSOR_ASSETS_DIR ||
    path.resolve(__dirname, '../../../Users/john philip/.cursor/projects/c-Airking-Inventory-main/assets');

  const candidates = [];
  if (fs.existsSync(cursorAssets)) {
    const files = fs
      .readdirSync(cursorAssets)
      .filter((f) => /\.png$/i.test(f) && /image-/.test(f))
      .map((f) => path.join(cursorAssets, f));
    candidates.push(...files);
  }

  // Fallback: repo-local assets folder (if user copies them in)
  const repoAssets = path.resolve(__dirname, '../../assets');
  if (fs.existsSync(repoAssets)) {
    const files = fs
      .readdirSync(repoAssets)
      .filter((f) => /\.png$/i.test(f) && /image-/.test(f))
      .map((f) => path.join(repoAssets, f));
    candidates.push(...files);
  }

  // Deduplicate while preserving order
  const seen = new Set();
  const out = [];
  for (const p of candidates) {
    if (seen.has(p)) continue;
    seen.add(p);
    out.push(p);
  }
  return out;
}

const IMAGES = detectImagePaths();

const OUT_DIR = path.resolve(__dirname, '../storage/app/imports');
const OUT_CSV = path.join(OUT_DIR, 'legit_products_ocr.csv');
const OUT_REVIEW = path.join(OUT_DIR, 'legit_products_ocr_review.txt');

function ensureOutDir() {
  fs.mkdirSync(OUT_DIR, { recursive: true });
}

function cleanLine(s) {
  return String(s || '')
    .replace(/\u00a0/g, ' ')
    .replace(/[|]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

function isLikelyModelCode(s) {
  // Model codes like: CSD-100A, TAC-09CWVUE2, MSCE-10CRFN8, FP-51AR020HEIV-N4, etc
  return /^[A-Z0-9]{2,6}[-/][A-Z0-9-]{3,20}$/.test(s) || /^[A-Z]{2,6}[0-9]{2,4}[A-Z0-9-]{1,10}$/.test(s);
}

function parseTotalOnHand(line) {
  // Total is the last number on the row (after branch columns)
  const nums = line.match(/(\d{1,5})(?!.*\d)/);
  return nums ? Number(nums[1]) : null;
}

function detectUnitHint(categoryName) {
  const c = categoryName.toLowerCase();
  if (c.includes('installation') || c.includes('consumable') || c.includes('materials')) return 'Box';
  return 'Unit';
}

function detectProductType(categoryName) {
  const c = categoryName.toLowerCase();
  if (c.includes('installation') || c.includes('consumable') || c.includes('materials') || c.includes('brackets') || c.includes('electrical') || c.includes('bolts') || c.includes('refrigerants')) {
    return 'consumable';
  }
  return 'appliance';
}

function parseSheetTextToRows(text) {
  const lines = String(text || '')
    .split(/\r?\n/)
    .map(cleanLine)
    .filter(Boolean);

  let currentCategory = 'Aircon Units';
  let currentBrand = '';
  const rows = [];
  const review = [];

  for (const raw of lines) {
    const line = raw;

    // Category/section markers (best-effort)
    if (/AIRCON UNITS/i.test(line)) currentCategory = 'Aircon Units';
    if (/INSTALLATION MATERIALS/i.test(line)) currentCategory = 'Installation Materials';
    if (/CONSUMABLES/i.test(line) && !/CONSUMABLE SUPPLY/i.test(line)) currentCategory = 'Consumables';
    if (/BOLTS|NUTS|SMALL ITEMS/i.test(line)) currentCategory = 'Bolts, Nuts and Small Items';
    if (/REFRIGERANTS/i.test(line)) currentCategory = 'Refrigerants';
    if (/ELECTRICALS/i.test(line)) currentCategory = 'Electricals';
    if (/BRACKETS/i.test(line)) currentCategory = 'Brackets';
    if (/PE FOAM/i.test(line)) currentCategory = 'PE Foam';
    if (/RUBBER INSULATION/i.test(line)) currentCategory = 'Rubber Insulation';
    if (/COPPER TUBE/i.test(line)) currentCategory = 'Copper Tube';

    // Brand headers (all caps-ish, no numbers)
    if (/^(CHIQ|TCL|MIDEA|HAIER|SAMSUNG|CARRIER|KOPPEL|LG)\b/i.test(line)) {
      currentBrand = line.split(/\s+/)[0].toUpperCase();
      continue;
    }

    // Rows: we need a model code and a total number.
    // OCR output tends to flatten the table; we try to find the model code in the line.
    const tokens = line.split(' ');
    const modelToken = tokens.find((t) => isLikelyModelCode(t.replace(/[^A-Z0-9/-]/gi, '')));
    const total = parseTotalOnHand(line);

    if (!modelToken || total == null) {
      continue;
    }

    // Best-effort capacity/variant extraction (HP or sizes)
    const cap =
      (line.match(/\b(\d(?:\.\d)?HP)\b/i)?.[1] || '').toUpperCase() ||
      (line.match(/\b(\d\/\d(?:\s*CT)?)\b/i)?.[1] || '') ||
      (line.match(/\b(\d+\/\d+)\b/)?.[1] || '') ||
      '';

    const model_code = modelToken.replace(/[^A-Z0-9/-]/gi, '');
    const product_type = detectProductType(currentCategory);
    const unit_hint = detectUnitHint(currentCategory);

    // If we still have no brand from header, try to infer from line (rare)
    const brand_name = currentBrand || '';

    // If brand is missing, keep but mark review.
    if (!brand_name) {
      review.push(`[NO BRAND] ${currentCategory} | ${line}`);
    }

    rows.push({
      category_name: currentCategory,
      brand_name,
      product_type,
      model_code,
      capacity_or_variant: cap,
      unit_hint,
      total_on_hand: total,
    });
  }

  return { rows, review, linesCount: lines.length };
}

async function main() {
  ensureOutDir();

  const worker = await createWorker('eng');
  await worker.setParameters({
    tessedit_char_whitelist: 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-./%() ',
  });

  const allRows = [];
  const reviewLines = [];

  for (const img of IMAGES) {
    if (!fs.existsSync(img)) {
      reviewLines.push(`[MISSING IMAGE] ${img}`);
      continue;
    }
    // eslint-disable-next-line no-console
    console.log(`OCR: ${path.basename(img)}`);
    const { data } = await worker.recognize(img);
    const parsed = parseSheetTextToRows(data.text);
    allRows.push(...parsed.rows);
    reviewLines.push(...parsed.review);
  }

  await worker.terminate();

  // Deduplicate by (model_code + capacity_or_variant)
  const seen = new Set();
  const deduped = [];
  for (const r of allRows) {
    const key = `${r.model_code}||${r.capacity_or_variant}||${r.category_name}`;
    if (seen.has(key)) continue;
    seen.add(key);
    deduped.push(r);
  }

  const csv = stringify(deduped, {
    header: true,
    columns: [
      'category_name',
      'brand_name',
      'product_type',
      'model_code',
      'capacity_or_variant',
      'unit_hint',
      'total_on_hand',
    ],
  });

  fs.writeFileSync(OUT_CSV, csv, 'utf8');
  fs.writeFileSync(OUT_REVIEW, reviewLines.join('\n') + '\n', 'utf8');

  // eslint-disable-next-line no-console
  console.log(`Wrote: ${OUT_CSV}`);
  // eslint-disable-next-line no-console
  console.log(`Review: ${OUT_REVIEW}`);
  // eslint-disable-next-line no-console
  console.log(`Rows: ${deduped.length}`);
}

main().catch((e) => {
  // eslint-disable-next-line no-console
  console.error(e);
  process.exit(1);
});

