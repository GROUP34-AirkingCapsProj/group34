import React from 'react';
import Barcode from 'react-barcode';

/**
 * Renders the digital tags minted for received installation materials so
 * staff can scan straight off the screen when a physical label is missing,
 * damaged, or unreadable. Printing is a secondary option.
 *
 * The scannable value is always `serial_number` (the generated INT- tag).
 * `supplier_barcode`, when present, is shown as reference text only — it
 * repeats across every box of a product and must never be fed to a scanner
 * as if it were unique.
 */
export default function MaterialTagSheet({ tags, productName, onPrint, showHeader = true }) {
  if (!tags || tags.length === 0) {
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
            {tags.length} tag{tags.length === 1 ? '' : 's'}
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
        {tags.map((tag) => (
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
            {tag.supplier_barcode && (
              <div className="bsr-tag-card-supplier">
                Supplier: {tag.supplier_barcode}
              </div>
            )}
          </div>
        ))}
      </div>
    </div>
  );
}
