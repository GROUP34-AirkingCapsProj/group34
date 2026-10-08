import React from 'react';
import Barcode from 'react-barcode';

/* ── Serial / tag predicates ──────────────────────────────────────────────
   Shared by every screen that lists unit-level records, so the affordances a
   row offers never disagree between the per-SKU modal and the PO group modal. */

/* A row is scannable whenever it carries a barcode of ours to show — whether
   we generated it or it is the supplier's own code copied onto the tag. An
   appliance's serial is the manufacturer's, with no tag of ours behind it, and
   a retired tag points at stock that is gone, so both get a dash instead. */
export const isScannableTag = (s) => Boolean(s?.is_material_tag) && s?.serial_type !== 'issued';

/* A tag is printable only while it is both scannable and ours to reprint:
   `supplier_barcode === null` is the honest test for "we generated this", not
   an `INT-` prefix match against a format we control and could change. A
   supplier's own code is scannable but not printable — reprinting someone
   else's barcode puts two labels of differing provenance on one box. */
export const isPrintableTag = (s) => isScannableTag(s) && s?.supplier_barcode == null;

const fmtDateTime = (v) => (v
  ? new Date(v).toLocaleString('en-PH', {
    year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
  })
  : '—');

/* What one material tag physically stands for. MaterialTagService mints one
   tag per package when the product is counted by package and a single tag for
   the whole lot when it is counted by piece, so the granularity is knowable
   even though the tag stores no quantity of its own. The piece count is left
   off deliberately: the last package of a lot can be a partial one, and a
   number nobody can reconcile is worse than the unit it was counted in. */
const tagCovers = (s) => {
  if (!s?.count_unit) return null;
  return s.count_unit === 'piece' ? 'Whole lot' : '1 package';
};

const isFilled = (v) => v !== null && v !== undefined && v !== '';

/**
 * The unit-level record table shared by Stock-Level Monitoring's per-SKU modal
 * and the Inventory Batch (PO group) modal.
 *
 * Columns are chosen from the data, not hard-coded: a column is rendered only
 * when at least one row has something to put in it. That is what keeps the
 * table honest across product types — an appliance carries per-unit stock-in /
 * stock-out counts, a material tag carries a batch and a granularity instead,
 * and neither view ends up with a run of columns that are blank on every row.
 */
export default function SerialRecordsTable({
  records = [],
  showProduct = false,
  showPo = false,
  onZoom,
  onPrint,
}) {
  const COLUMNS = [
    {
      key: 'index',
      header: '#',
      always: true,
      cell: (s, i) => <td className="invd-cell-num" key="index">{i + 1}</td>,
    },
    {
      key: 'serial',
      header: 'Serial Number',
      always: true,
      cell: (s) => (
        <td key="serial">
          <span className="invd-mono invd-bold">{s.serial_number}</span>
          {/* Reference only — a supplier code repeats across every box, so it
              is never the scannable value. */}
          {s.is_material_tag && s.supplier_barcode && (
            <div className="invd-small invd-muted">Supplier: {s.supplier_barcode}</div>
          )}
        </td>
      ),
    },
    {
      key: 'barcode',
      header: 'Digital Barcode',
      has: (s) => isScannableTag(s),
      cell: (s) => (
        <td className="invd-cell-barcode" key="barcode">
          {isScannableTag(s) ? (
            <button
              type="button"
              className="bsr-tag-card-zoom invd-barcode-zoom"
              onClick={() => onZoom?.(s)}
              aria-label={`Enlarge tag ${s.serial_number} for scanning`}
            >
              <Barcode
                value={s.serial_number}
                format="CODE128"
                height={26}
                width={1.1}
                fontSize={9}
                margin={0}
                displayValue={false}
              />
            </button>
          ) : <span className="invd-muted">—</span>}
        </td>
      ),
    },
    {
      key: 'print',
      header: 'Printable',
      has: (s) => Boolean(onPrint) && (isPrintableTag(s) || (s.is_material_tag && s.serial_type === 'issued')),
      cell: (s) => (
        <td className="no-print" key="print">
          {isPrintableTag(s) ? (
            <button
              type="button"
              className="invd-tag-print-row-btn"
              onClick={() => onPrint([s])}
            >
              Print tag
            </button>
          ) : s.is_material_tag && s.serial_type === 'issued'
            ? <span className="invd-muted invd-small">Retired</span>
            : <span className="invd-muted">—</span>}
        </td>
      ),
    },
    {
      key: 'product',
      header: 'Product',
      has: (s) => showProduct && isFilled(s.product_name),
      cell: (s) => <td key="product">{s.product_name ?? '—'}</td>,
    },
    {
      key: 'status',
      header: 'Status',
      always: true,
      cell: (s) => (
        <td key="status">
          <span className={`invd-status-pill${
            s.serial_status === 'In Stock' ? ' invd-pill-green'
              : s.serial_status === 'Issued' ? ' invd-pill-orange'
                : ' invd-pill-gray'
          }`}>
            {s.serial_status ?? 'Active'}
          </span>
        </td>
      ),
    },
    {
      key: 'covers',
      header: 'Tag Covers',
      has: (s) => isFilled(tagCovers(s)),
      cell: (s) => <td key="covers">{tagCovers(s) ?? '—'}</td>,
    },
    {
      key: 'batch',
      header: 'Batch',
      has: (s) => isFilled(s.batch_id),
      cell: (s) => (
        <td className="invd-mono invd-small" key="batch">
          {isFilled(s.batch_id) ? `#${s.batch_id}` : '—'}
        </td>
      ),
    },
    {
      key: 'batch_qty',
      header: 'Batch Qty (left / received)',
      has: (s) => isFilled(s.batch_quantity_received),
      cell: (s) => (
        <td className="invd-mono" key="batch_qty">
          {isFilled(s.batch_quantity_received)
            ? `${s.batch_quantity_remaining ?? 0} / ${s.batch_quantity_received}`
            : '—'}
        </td>
      ),
    },
    {
      key: 'received',
      header: 'Received',
      has: (s) => isFilled(s.row_quantity_received),
      cell: (s) => <td className="invd-mono" key="received">{s.row_quantity_received ?? '—'}</td>,
    },
    {
      key: 'stock_in',
      header: 'Stock In',
      has: (s) => isFilled(s.row_stock_in),
      cell: (s) => <td className="invd-mono" key="stock_in">{s.row_stock_in ?? '—'}</td>,
    },
    {
      key: 'stock_out',
      header: 'Stock Out',
      has: (s) => isFilled(s.row_stock_out),
      cell: (s) => <td className="invd-mono" key="stock_out">{s.row_stock_out ?? '—'}</td>,
    },
    {
      key: 'transfer',
      header: 'Transfer',
      has: (s) => isFilled(s.row_transfer),
      cell: (s) => <td className="invd-mono" key="transfer">{s.row_transfer ?? '—'}</td>,
    },
    {
      key: 'scanned_by',
      header: 'Scanned By',
      has: (s) => isFilled(s.scanned_by_name),
      cell: (s) => <td key="scanned_by">{s.scanned_by_name ?? '—'}</td>,
    },
    {
      key: 'received_at',
      header: 'Received At',
      has: (s) => isFilled(s.received_at),
      cell: (s) => <td key="received_at">{fmtDateTime(s.received_at)}</td>,
    },
    {
      key: 'scanned_at',
      header: 'Date Scanned',
      has: (s) => isFilled(s.scanned_at),
      cell: (s) => <td key="scanned_at">{fmtDateTime(s.scanned_at)}</td>,
    },
    {
      key: 'stock_out_at',
      header: 'Stocked Out At',
      has: (s) => isFilled(s.stock_out_at),
      cell: (s) => <td key="stock_out_at">{fmtDateTime(s.stock_out_at)}</td>,
    },
    {
      key: 'transferred_at',
      header: 'Transferred At',
      has: (s) => isFilled(s.transferred_at),
      cell: (s) => <td key="transferred_at">{fmtDateTime(s.transferred_at)}</td>,
    },
    {
      key: 'receiving_number',
      header: 'Receiving #',
      has: (s) => isFilled(s.receiving_number),
      cell: (s) => (
        <td className="invd-mono invd-small" key="receiving_number">{s.receiving_number ?? '—'}</td>
      ),
    },
    {
      key: 'po_number',
      header: 'PO Number',
      has: (s) => showPo && isFilled(s.po_number),
      cell: (s) => (
        <td key="po_number">
          {s.po_number ? <span className="invd-mono invd-small">{s.po_number}</span> : '—'}
        </td>
      ),
    },
    {
      key: 'supplier',
      header: 'Supplier',
      has: (s) => showPo && isFilled(s.supplier_name),
      cell: (s) => <td key="supplier">{s.supplier_name ?? '—'}</td>,
    },
  ];

  const columns = COLUMNS.filter((c) => c.always || records.some((s) => c.has(s)));

  return (
    <div className="invd-table-wrap">
      <table className="invd-table invd-serial-table">
        <thead>
          <tr>{columns.map((c) => <th key={c.key}>{c.header}</th>)}</tr>
        </thead>
        <tbody>
          {records.map((s, index) => (
            /* serial_id alone is not unique across this list: the backend
               unions item_serial rows with rows keyed by issuance_detail_id
               (appliances) or receiving_detail_id (untagged materials), and
               those sequences overlap. Two rows sharing a key is the one shape
               that could make React reuse a row and show a serial where it does
               not belong, so the key carries the serial number too. */
            <tr key={`${s.serial_type ?? 'row'}-${s.serial_id ?? index}-${s.serial_number}`}>
              {columns.map((c) => c.cell(s, index))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
