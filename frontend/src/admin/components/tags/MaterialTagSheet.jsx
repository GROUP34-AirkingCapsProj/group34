import React from 'react';
import Barcode from 'react-barcode';

/**
 * Renders the digital tags minted for received installation materials so
 * staff can scan straight off the screen when a physical label is missing,
 * damaged, or unreadable. Printing is a secondary option.
 *
 * The scannable value is always `serial_number` (the generated INT- tag).
 *
 * A material that arrived WITH a supplier barcode has no generated tag —
 * `serial_number` and `supplier_barcode` both hold the supplier's own code.
 * This sheet is a print sheet: printing a second, our-own-format label for a
 * box the supplier already labelled would put two identifiers of differing
 * provenance on one box. Those tags are filtered out here rather than left
 * to each caller to remember, so a scanned material can never reach this
 * grid regardless of what list a caller hands in. Gated on
 * `supplier_barcode === null` — a generated tag has none — not an `INT-`
 * prefix match against a format this codebase controls and could change.
 */
export default function MaterialTagSheet({ tags, productName, onPrint, showHeader = true }) {
  const printableTags = (tags || []).filter((tag) => tag?.supplier_barcode == null);

  if (printableTags.length === 0) {
    return null;
  }

  const handlePrint = () => {
    if (typeof onPrint === 'function') {
      onPrint();
    } else {
      window.print();
    }
  };

  return (
    <div className="bsr-tag-sheet">
      {/* A caller that drives printing itself — one tag at a time from a
          table row, say — renders the cards without this header so there is
          exactly one "Print tags" control on the page. */}
      {showHeader && (
        <div className="bsr-tag-sheet-header no-print">
          <span className="bsr-tag-sheet-count">
            {printableTags.length} tag{printableTags.length === 1 ? '' : 's'}
            {productName ? ` for ${productName}` : ''}
          </span>
          <button
            type="button"
            className="bsr-tag-sheet-print-btn"
            onClick={handlePrint}
          >
            Print tags
          </button>
        </div>
      )}

      <div className="bsr-tag-sheet-grid">
        {printableTags.map((tag) => (
          <div className="bsr-tag-card" key={tag.serial_number}>
            {productName && (
              <div className="bsr-tag-card-product">{productName}</div>
            )}
            <Barcode
              value={tag.serial_number}
              format="CODE128"
              height={48}
              fontSize={12}
              displayValue
            />
          </div>
        ))}
      </div>
    </div>
  );
}
