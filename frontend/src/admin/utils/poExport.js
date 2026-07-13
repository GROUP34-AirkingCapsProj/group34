import * as XLSX from 'xlsx';

/**
 * Payload shape expected by both export functions:
 * {
 *   po_number, order_date, payment_terms, status,
 *   company:    { name, address, email },
 *   supplier:   { name, address },
 *   ship_to:    { name, address },
 *   prepared_by, verified_by, approved_by,   ← all resolved name strings
 *   notes, references,
 *   items: [{
 *     product_code, name, unit, qty, unit_price, subtotal,
 *     product_type, brand_name, capacity_rating, variant,
 *     pieces_per_package, package_unit,
 *   }],
 *   total_amount,
 * }
 */

// ─── Shared helpers ───────────────────────────────────────────────────────────

// Distinct destination warehouses for a PO, first-seen order, deduped by location id.
// Accepts enriched PO details (row.location = {...}) or preview line items
// (row.location_id + row.warehouse_name) with an optional { [id]: location } lookup
// for addresses. Returns [{ location_id, name, address, contact_number, email, manager_name }].
export function distinctDeliverLocations(rows, locationsById = {}) {
  const map = new Map();
  (rows || []).forEach((r) => {
    const id  = String(r.location_id ?? r.location?.location_id ?? '__none__');
    if (map.has(id)) return;
    const loc = r.location || locationsById[id] || locationsById[String(r.location_id)] || null;
    const address = loc
      ? [loc.address, loc.city, loc.province, loc.region].filter(Boolean).join(', ') || null
      : null;
    map.set(id, {
      location_id:    id,
      name:           loc?.location_name || r.location_name || r.warehouse_name || 'Unassigned warehouse',
      address,
      contact_number: loc?.contact_number || null,
      email:          loc?.email || null,
      manager_name:   loc?.manager_name || null,
    });
  });
  return [...map.values()];
}

/**
 * Build the description string for a line item.
 * Appliance  → "PRODUCT NAME BRAND CAPACITY VARIANT"
 * Consumable → "PRODUCT NAME (50pcs/Box)"
 */
function buildDescription(item) {
  const base = (item.name || '').toUpperCase();
  if (item.product_type === 'appliance') {
    return [base, item.brand_name, item.capacity_rating, item.variant]
      .filter(Boolean).join(' ').trim();
  }
  if (item.product_type === 'installation_material' && item.pieces_per_package) {
    const pkg = item.package_unit || 'Box';
    return `${base} (${item.pieces_per_package}pcs/${pkg})`;
  }
  return base;
}

// Resolved money amount for a PO line: explicit subtotal, else qty × unit price.
function itemAmount(item) {
  const qty   = Number(item.qty ?? item.quantity_ordered ?? 0);
  const price = Number(item.unit_price || 0);
  return Number(item.subtotal || qty * price || 0);
}

function fmtDate(raw) {
  if (!raw) return new Date().toLocaleDateString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric' });
  const d = new Date(raw);
  return isNaN(d) ? String(raw) : d.toLocaleDateString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric' });
}

// ─── Document (HTML print window) ────────────────────────────────────────────

export function buildPODocumentHtml(payload) {
  const company  = payload.company  || {};
  const supplier = payload.supplier || {};
  const manual   = payload.ship_to  || {};

  const companyName    = company.name    || 'Airking Air Conditioning';
  const companyAddress = company.address || '';
  const companyEmail   = company.email   || '';
  const supplierName   = (supplier.name || '').toUpperCase();
  const supplierAddr   = supplier.address || '';

  const dateStr      = fmtDate(payload.order_date);
  const paymentTerms = payload.payment_terms || 'Net 30';
  const notes        = payload.notes      || '';
  const refs         = payload.references || '';
  const prepBy       = payload.prepared_by || '';
  const verBy        = payload.verified_by || '';
  const appBy        = payload.approved_by || '';

  const items    = Array.isArray(payload.items) ? payload.items : [];
  const locs     = (Array.isArray(payload.ship_to_locations) && payload.ship_to_locations.length)
    ? payload.ship_to_locations
    : [{ location_id: '__none__', name: manual.name || supplierName, address: manual.address || supplierAddr }];
  const single   = locs.length <= 1;

  const renderItemRow = (item) => {
    const qty    = Number(item.qty ?? item.quantity_ordered ?? 0);
    const price  = Number(item.unit_price || 0);
    const amount = itemAmount(item);
    const unit   = item.unit || 'pc';
    const code   = item.product_code || '—';
    const desc   = buildDescription(item);
    const priceStr  = price  === 0 ? 'Free' : price.toLocaleString('en-PH', { minimumFractionDigits: 2 });
    const amountStr = amount === 0 ? '0.00' : amount.toLocaleString('en-PH', { minimumFractionDigits: 2 });
    return `
      <tr>
        <td style="text-align:center">${qty}</td>
        <td style="text-align:center">${unit}</td>
        <td>${code}</td>
        <td>${desc}</td>
        <td style="text-align:right">${priceStr}</td>
        <td style="text-align:right">${amountStr}</td>
      </tr>`;
  };

  const manualBlock = (manual.name || manual.address)
    ? `<div class="ship-to ship-to--manual">
         <strong>Ship To: ${manual.name || ''}</strong>
         ${manual.address ? manual.address : ''}
       </div>`
    : '';

  const pagesHtml = locs.map((loc) => {
    const pageItems = single
      ? items
      : items.filter((it) => String(it.location_id ?? '__none__') === loc.location_id);
    const pageTotal = pageItems.reduce((s, it) => s + itemAmount(it), 0);
    const deliverExtra = [
      loc.contact_number ? `Phone: ${loc.contact_number}` : '',
      loc.email          ? `Email: ${loc.email}`          : '',
      loc.manager_name   ? `Manager: ${loc.manager_name}` : '',
    ].filter(Boolean).join(' &nbsp;·&nbsp; ');

    return `
  <div class="po-page">
    <div class="company-header">
      <h1>${companyName}</h1>
      ${companyAddress ? `<p>${companyAddress}</p>` : ''}
      ${companyEmail   ? `<p>${companyEmail}</p>`   : ''}
    </div>

    <div class="header-grid">
      <div class="supplier-block">
        <strong>${supplierName}</strong>
        ${supplierAddr}
      </div>
      <div class="po-ref-block">
        <h2>Purchase Order</h2>
        <table class="po-ref-table">
          <tr><td>Date:</td><td>${dateStr}</td></tr>
          <tr><td>Reference #:</td><td>${payload.po_number || ''}</td></tr>
          <tr><td>Payment Terms:</td><td>${paymentTerms}</td></tr>
        </table>
      </div>
    </div>

    ${manualBlock}

    <div class="ship-to">
      <strong>Deliver To (Warehouse): ${loc.name || '—'}</strong>
      ${loc.address ? loc.address : ''}
      ${deliverExtra ? `<div style="font-size:11px;color:#333;margin-top:2px">${deliverExtra}</div>` : ''}
    </div>

    <div class="details-label">Details</div>
    <table class="items-table">
      <thead>
        <tr>
          <th style="text-align:center;width:65px">Quantity</th>
          <th style="text-align:center;width:55px">Unit</th>
          <th style="width:130px">Model Code</th>
          <th>Description</th>
          <th style="text-align:right;width:95px">Unit Price</th>
          <th style="text-align:right;width:95px">Amount</th>
        </tr>
      </thead>
      <tbody>${pageItems.map(renderItemRow).join('')}</tbody>
      <tfoot>
        <tr>
          <td colspan="5" style="text-align:right">Total Amount &rarr;</td>
          <td style="text-align:right">&#8369;${pageTotal.toLocaleString('en-PH', { minimumFractionDigits: 2 })}</td>
        </tr>
      </tfoot>
    </table>

    <div class="notes-section">
      <div>Notes: ${notes}</div>
      <div>References: ${refs}</div>
    </div>

    <div class="signatures">
      <div class="sig-block"><div class="sig-label">Prepared By:</div><div class="sig-name">${prepBy}</div></div>
      <div class="sig-block"><div class="sig-label">Verified By:</div><div class="sig-name">${verBy}</div></div>
      <div class="sig-block"><div class="sig-label">Approved By:</div><div class="sig-name">${appBy}</div></div>
    </div>
  </div>`;
  }).join('');

  return `<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Purchase Order - ${payload.po_number || 'PO'}</title>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'Times New Roman', Times, serif; font-size: 13px; color: #000; padding: 40px; max-width: 850px; margin: 0 auto; }
    .po-page { page-break-after: always; }
    .po-page:last-child { page-break-after: auto; }
    .company-header { text-align: center; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 20px; }
    .company-header h1 { font-size: 26px; font-weight: 900; letter-spacing: 0.04em; margin-bottom: 4px; text-transform: uppercase; }
    .company-header p { font-size: 11px; color: #333; margin: 2px 0; }
    .header-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
    .supplier-block { font-size: 13px; line-height: 1.7; }
    .supplier-block strong { font-size: 14px; font-weight: 900; display: block; margin-bottom: 2px; }
    .po-ref-block { text-align: right; }
    .po-ref-block h2 { font-size: 20px; font-weight: 900; margin-bottom: 8px; }
    .po-ref-table { margin-left: auto; border-collapse: collapse; font-size: 13px; }
    .po-ref-table td { padding: 2px 6px; }
    .po-ref-table td:first-child { font-weight: 700; text-align: right; white-space: nowrap; }
    .ship-to { font-size: 13px; line-height: 1.6; margin-bottom: 16px; border: 1.5px solid #000; padding: 8px 12px; background: #f4f4f4; }
    .ship-to--manual { background: #fff; border-style: dashed; }
    .ship-to strong { display: block; font-size: 14px; font-weight: 900; margin-bottom: 2px; text-transform: uppercase; }
    .details-label { font-size: 15px; font-weight: 900; margin-bottom: 4px; }
    .items-table { width: 100%; border-collapse: collapse; font-size: 12px; margin-bottom: 12px; }
    .items-table th { border-top: 1.5px solid #000; border-bottom: 1.5px solid #000; padding: 5px 8px; font-weight: 700; text-align: left; }
    .items-table td { padding: 5px 8px; border-bottom: 0.5px solid #ddd; }
    .items-table tfoot td { border-top: 1.5px solid #000; border-bottom: 1.5px solid #000; font-weight: 700; padding: 6px 8px; }
    .notes-section { font-size: 13px; line-height: 2.4; margin: 12px 0 32px; }
    .notes-section div { border-bottom: 1px solid #ccc; padding-bottom: 4px; margin-bottom: 4px; }
    .signatures { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 24px; margin-top: 32px; }
    .sig-block { display: flex; flex-direction: column; gap: 36px; }
    .sig-label { font-weight: 900; font-size: 13px; }
    .sig-name { font-weight: 900; font-size: 13px; border-bottom: 2px solid #000; padding-bottom: 4px; min-height: 24px; text-decoration: underline; }
    @media print { body { padding: 20px; } }
  </style>
</head>
<body>
${pagesHtml}
</body>
</html>`;
}

export function exportPOToDocument(payload) {
  const html = buildPODocumentHtml(payload);
  const win = window.open('', '_blank', 'width=920,height=720');
  if (!win) {
    alert('Please allow pop-ups to export the PO document.');
    return;
  }
  win.document.write(html);
  win.document.close();
  win.focus();
  setTimeout(() => win.print(), 600);
}

// ─── Excel ────────────────────────────────────────────────────────────────────

// Excel-safe, unique sheet name (max 31 chars, no []:*?/\ ).
function safeSheetName(name, used) {
  let base = String(name || 'Sheet').replace(/[[\]:*?/\\]/g, ' ').slice(0, 31).trim() || 'Sheet';
  let candidate = base;
  let n = 2;
  while (used.has(candidate)) {
    const suffix = ` (${n})`;
    candidate = base.slice(0, 31 - suffix.length) + suffix;
    n += 1;
  }
  used.add(candidate);
  return candidate;
}

function buildPOSheetAoa(payload, loc, sheetItems) {
  const company  = payload.company  || {};
  const supplier = payload.supplier || {};
  const manual   = payload.ship_to  || {};
  const companyName    = company.name    || 'Airking Air Conditioning';
  const companyAddress = company.address || '';
  const companyEmail   = company.email   || '';
  const supplierName   = (supplier.name || '').toUpperCase();
  const supplierAddr   = supplier.address || '';
  const dateStr        = fmtDate(payload.order_date);
  const paymentTerms   = payload.payment_terms || 'Net 30';
  const prepBy = payload.prepared_by || '';
  const verBy  = payload.verified_by || '';
  const appBy  = payload.approved_by || '';
  const notes  = payload.notes      || '';
  const refs   = payload.references || '';

  const aoa = [];
  aoa.push([companyName]);
  if (companyAddress) aoa.push([companyAddress]);
  if (companyEmail)   aoa.push([companyEmail]);
  aoa.push([]);

  aoa.push([supplierName, '', '', 'Purchase Order']);
  aoa.push([supplierAddr, '', '', 'Date:',          dateStr]);
  aoa.push(['',           '', '', 'Reference #:',   payload.po_number || '']);
  aoa.push(['',           '', '', 'Payment Terms:', paymentTerms]);
  if (manual.name || manual.address) {
    aoa.push([`Ship To: ${manual.name || ''}`]);
    if (manual.address) aoa.push([manual.address]);
  }
  aoa.push([`Deliver To (Warehouse): ${loc.name || ''}`]);
  if (loc.address) aoa.push([loc.address]);
  aoa.push([]);

  const COL_HEADERS = ['Quantity', 'Unit', 'Model Code', 'Description', 'Unit Price', 'Amount'];
  aoa.push(COL_HEADERS);
  const headerRowIdx = aoa.length; // 1-based

  let total = 0;
  sheetItems.forEach((item) => {
    const qty    = Number(item.qty ?? item.quantity_ordered ?? 0);
    const price  = Number(item.unit_price || 0);
    const amount = itemAmount(item);
    total += amount;
    aoa.push([qty, item.unit || 'pc', item.product_code || '—', buildDescription(item), price, amount]);
  });

  aoa.push([]);
  aoa.push(['', '', '', '', 'TOTAL AMOUNT', total]);
  aoa.push([]);
  aoa.push(['Prepared By:', '', 'Verified By:', '', 'Approved By:', '']);
  aoa.push([prepBy,         '', verBy,          '', appBy,          '']);
  if (notes) { aoa.push([]); aoa.push([`Notes: ${notes}`]); }
  if (refs)  { aoa.push([`References: ${refs}`]); }

  return { aoa, headerRowIdx, COL_HEADERS };
}

export function buildPOWorkbook(payload) {
  const company = payload.company || {};
  const companyName = company.name || 'Airking Air Conditioning';
  const items = Array.isArray(payload.items) ? payload.items : [];
  const locs  = (Array.isArray(payload.ship_to_locations) && payload.ship_to_locations.length)
    ? payload.ship_to_locations
    : [{ location_id: '__none__', name: (payload.ship_to || {}).name || companyName, address: (payload.ship_to || {}).address || '' }];
  const single = locs.length <= 1;

  const wb = XLSX.utils.book_new();
  const usedNames = new Set();

  locs.forEach((loc) => {
    const sheetItems = single
      ? items
      : items.filter((it) => String(it.location_id ?? '__none__') === loc.location_id);
    const { aoa, headerRowIdx, COL_HEADERS } = buildPOSheetAoa(payload, loc, sheetItems);
    const ws = XLSX.utils.aoa_to_sheet(aoa);
    ws['!cols'] = [{ wch: 12 }, { wch: 10 }, { wch: 18 }, { wch: 48 }, { wch: 14 }, { wch: 14 }];

    const companyCell = XLSX.utils.encode_cell({ r: 0, c: 0 });
    if (!ws[companyCell]) ws[companyCell] = { v: companyName, t: 's' };
    ws[companyCell].s = { font: { bold: true, sz: 16 } };

    const headerR = headerRowIdx - 1;
    COL_HEADERS.forEach((_, ci) => {
      const addr = XLSX.utils.encode_cell({ r: headerR, c: ci });
      if (ws[addr]) ws[addr].s = { font: { bold: true } };
    });

    XLSX.utils.book_append_sheet(wb, ws, safeSheetName(single ? 'Purchase Order' : loc.name, usedNames));
  });

  return wb;
}

export function exportPOToExcel(payload) {
  const wb = buildPOWorkbook(payload);
  XLSX.writeFile(wb, `${payload.po_number || 'PO'}.xlsx`);
}
