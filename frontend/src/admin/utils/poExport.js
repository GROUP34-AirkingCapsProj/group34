import * as XLSX from 'xlsx';
import { jsPDF } from 'jspdf';
import autoTable from 'jspdf-autotable';

/**
 * Normalized PO payload shape used by both export functions:
 * {
 *   po_number, status,
 *   supplier: { name, address, origin, region, contact_person, contact_number, email, tin },
 *   ship_to:  { name, address, contact_number, email, manager_name },
 *   prepared_by, approved_by, order_date, expected_delivery_date,
 *   items: [{ name, qty, unit_price, subtotal }],
 *   total_amount,
 * }
 */

const BRAND_RED = [220, 38, 38];

// ─── Excel ───────────────────────────────────────────────────────────────────

export function exportPOToExcel(payload) {
  const wb = XLSX.utils.book_new();
  const rows = [];

  const push = (label, value) => rows.push([label, value ?? '—']);
  const gap  = ()             => rows.push(['', '']);

  rows.push(['PURCHASE ORDER', payload.po_number]);
  rows.push(['Status', payload.status || '—']);
  gap();

  rows.push(['── SUPPLIER / VENDOR ──', '']);
  push('Name',           payload.supplier?.name);
  push('Address',        payload.supplier?.address);
  if (payload.supplier?.origin || payload.supplier?.region)
    push('Origin / Region', [payload.supplier.origin, payload.supplier.region].filter(Boolean).join(', '));
  push('Contact Person', payload.supplier?.contact_person);
  push('Phone',          payload.supplier?.contact_number);
  push('Email',          payload.supplier?.email);
  push('TIN',            payload.supplier?.tin);
  gap();

  rows.push(['── SHIP TO / DELIVER TO ──', '']);
  push('Branch / Location', payload.ship_to?.name);
  push('Address',           payload.ship_to?.address);
  push('Phone',             payload.ship_to?.contact_number);
  push('Email',             payload.ship_to?.email);
  push('Manager',           payload.ship_to?.manager_name);
  gap();

  rows.push(['── ORDER DETAILS ──', '']);
  push('Prepared By',        payload.prepared_by);
  push('Order Date',         payload.order_date);
  push('Expected Delivery',  payload.expected_delivery_date);
  push('Approved By',        payload.approved_by || '—');
  gap();

  rows.push(['── LINE ITEMS ──', '', '', '', '']);
  rows.push(['#', 'Product', 'Qty', 'Unit Price', 'Subtotal']);

  payload.items.forEach((item, i) => {
    rows.push([
      i + 1,
      item.name,
      item.qty,
      Number(item.unit_price || 0),
      Number(item.subtotal || 0),
    ]);
  });

  gap();
  rows.push(['', '', '', 'TOTAL', Number(payload.total_amount || 0)]);

  const ws = XLSX.utils.aoa_to_sheet(rows);

  // Column widths
  ws['!cols'] = [{ wch: 22 }, { wch: 40 }, { wch: 8 }, { wch: 14 }, { wch: 14 }];

  XLSX.utils.book_append_sheet(wb, ws, 'Purchase Order');
  XLSX.writeFile(wb, `${payload.po_number || 'PO'}.xlsx`);
}

// ─── PDF ─────────────────────────────────────────────────────────────────────

const safe = (v) => String(v ?? '-').replace(/—/g, '-').replace(/₱/g, 'PHP ');

export function exportPOToDocument(payload) {
  const doc   = new jsPDF('p', 'mm', 'a4');
  const W     = doc.internal.pageSize.getWidth();
  const mL    = 15;
  const mR    = 15;
  const cW    = W - mL - mR;
  let   y     = 0;

  // ── Red header bar ──
  doc.setFillColor(...BRAND_RED);
  doc.rect(0, 0, W, 28, 'F');

  doc.setFont('helvetica', 'bold');
  doc.setFontSize(16);
  doc.setTextColor(255, 255, 255);
  doc.text('PURCHASE ORDER', mL, 12);

  doc.setFontSize(10);
  doc.setFont('helvetica', 'normal');
  doc.text(payload.po_number || '', mL, 20);

  // Status pill (right side of header)
  const statusLabel = (payload.status || 'APPROVED').toUpperCase();
  doc.setFontSize(8);
  doc.setFont('helvetica', 'bold');
  const statusW = doc.getTextWidth(statusLabel) + 8;
  doc.setFillColor(255, 255, 255);
  doc.roundedRect(W - mR - statusW, 8, statusW, 8, 2, 2, 'F');
  doc.setTextColor(...BRAND_RED);
  doc.text(statusLabel, W - mR - statusW + 4, 13.5);

  y = 36;

  // ── Address blocks ──
  const half = cW / 2 - 3;

  const drawAddressBlock = (title, lines, x, startY) => {
    // Title
    doc.setFillColor(248, 250, 252);
    doc.rect(x, startY, half, 7, 'F');
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(7);
    doc.setTextColor(156, 163, 175);
    doc.text(title.toUpperCase(), x + 3, startY + 4.5);

    let iy = startY + 10;
    lines.forEach(({ label, value }) => {
      if (!value) return;
      doc.setFont('helvetica', 'bold');
      doc.setFontSize(7);
      doc.setTextColor(156, 163, 175);
      doc.text(label.toUpperCase(), x + 3, iy);
      iy += 4;
      doc.setFont('helvetica', 'normal');
      doc.setFontSize(8.5);
      doc.setTextColor(17, 24, 39);
      const wrapped = doc.splitTextToSize(safe(value), half - 6);
      doc.text(wrapped, x + 3, iy);
      iy += wrapped.length * 4.5 + 1;
    });
    return iy;
  };

  const supplierLines = [
    { label: 'Name',           value: payload.supplier?.name },
    { label: 'Address',        value: payload.supplier?.address },
    { label: 'Origin / Region',value: [payload.supplier?.origin, payload.supplier?.region].filter(Boolean).join(', ') || null },
    { label: 'Contact Person', value: payload.supplier?.contact_person },
    { label: 'Phone',          value: payload.supplier?.contact_number },
    { label: 'Email',          value: payload.supplier?.email },
    { label: 'TIN',            value: payload.supplier?.tin },
  ];

  const shipToLines = [
    { label: 'Branch / Location', value: payload.ship_to?.name },
    { label: 'Address',           value: payload.ship_to?.address },
    { label: 'Phone',             value: payload.ship_to?.contact_number },
    { label: 'Email',             value: payload.ship_to?.email },
    { label: 'Manager',           value: payload.ship_to?.manager_name },
  ];

  // Border around both blocks
  doc.setDrawColor(229, 231, 235);
  doc.setLineWidth(0.3);
  doc.rect(mL, y, cW, 1, 'S'); // top border placeholder — we'll draw after content

  const leftBottom  = drawAddressBlock('Supplier / Vendor', supplierLines, mL, y);
  const rightBottom = drawAddressBlock('Ship To / Deliver To', shipToLines, mL + half + 6, y);
  const blockBottom = Math.max(leftBottom, rightBottom) + 4;

  // Borders
  doc.setDrawColor(229, 231, 235);
  doc.rect(mL, y, cW, blockBottom - y, 'S');
  doc.line(mL + half + 3, y, mL + half + 3, blockBottom);

  y = blockBottom + 6;

  // ── Meta grid ──
  const metaItems = [
    { label: 'PREPARED BY',       value: payload.prepared_by },
    { label: 'ORDER DATE',        value: payload.order_date },
    { label: 'EXPECTED DELIVERY', value: payload.expected_delivery_date },
    { label: 'APPROVED BY',       value: payload.approved_by || '—' },
  ];
  const colW = cW / metaItems.length;
  metaItems.forEach((m, i) => {
    const mx = mL + i * colW;
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(7);
    doc.setTextColor(156, 163, 175);
    doc.text(m.label, mx, y);
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(9);
    doc.setTextColor(17, 24, 39);
    doc.text(safe(m.value || '-'), mx, y + 5);
  });

  y += 14;

  // ── Line items table ──
  autoTable(doc, {
    startY:   y,
    margin:   { left: mL, right: mR },
    head: [['#', 'Product', 'Qty', 'Unit Price', 'Subtotal']],
    body: payload.items.map((item, i) => [
      i + 1,
      item.name || '-',
      item.qty,
      `PHP ${Number(item.unit_price || 0).toLocaleString('en-PH')}`,
      `PHP ${Number(item.subtotal   || 0).toLocaleString('en-PH')}`,
    ]),
    headStyles: {
      fillColor:  BRAND_RED,
      textColor:  [255, 255, 255],
      fontStyle:  'bold',
      fontSize:   8,
    },
    bodyStyles:     { fontSize: 8, textColor: [17, 24, 39] },
    alternateRowStyles: { fillColor: [249, 250, 251] },
    columnStyles: {
      0: { cellWidth: 10,  halign: 'center' },
      2: { cellWidth: 14,  halign: 'center' },
      3: { cellWidth: 28,  halign: 'right' },
      4: { cellWidth: 28,  halign: 'right' },
    },
  });

  y = doc.lastAutoTable.finalY + 4;

  // ── Total ──
  doc.setFillColor(254, 242, 242);
  doc.rect(mL, y, cW, 10, 'F');
  doc.setFont('helvetica', 'bold');
  doc.setFontSize(9);
  doc.setTextColor(156, 163, 175);
  doc.text('TOTAL ESTIMATED VALUE', mL + 3, y + 6.5);
  doc.setFontSize(11);
  doc.setTextColor(...BRAND_RED);
  const totalStr = safe(`PHP ${Number(payload.total_amount || 0).toLocaleString('en-PH')}`);
  doc.text(totalStr, mL + cW - 3, y + 6.5, { align: 'right' });

  y += 18;

  // ── Footer ──
  doc.setFont('helvetica', 'normal');
  doc.setFontSize(7);
  doc.setTextColor(156, 163, 175);
  doc.text(`Generated: ${new Date().toLocaleString('en-PH')}   ·   ${payload.po_number}`, mL, y);

  doc.save(`${payload.po_number || 'PO'}.pdf`);
}
