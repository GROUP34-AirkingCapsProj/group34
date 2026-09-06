import React, { useState } from 'react';
import BarcodeZoom from './BarcodeZoom';
import TagCard from './TagCard';
import { useLabelPrinter } from '../../hooks/useLabelPrinter';
import {
  COMFORTABLE_MODULE_MM,
  LABEL_HEIGHT_MM,
  LABEL_WIDTH_MM,
  isTightFit,
  moduleWidthMm,
} from '../../utils/barcodeLabel';

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
 *
 * A card is sized for print, which is too small for a handheld to read off a
 * monitor. Clicking one opens it at scanning size — see BarcodeZoom.
 */
export default function MaterialTagSheet({ tags, productName, showHeader = true }) {
  const [zoomedTag, setZoomedTag] = useState(null);
  const { printerReady, sending, sendToPrinter, savePdf, savePdfSheet } = useLabelPrinter();
  const printableTags = (tags || []).filter((tag) => tag?.supplier_barcode == null);

  if (printableTags.length === 0) {
    return null;
  }

  /* A code can be too long for the stock: the bars get thinner to fit, and
     past a point no handheld reads them. The stickers still print — a clipped
     symbol would be worse — but printing a whole roll of unscannable labels
     and finding out at the shelf is the failure worth preventing. */
  const tightTag = printableTags.find((tag) => isTightFit(tag.serial_number));
  const tightBarMm = tightTag ? moduleWidthMm(tightTag.serial_number) : null;

  return (
    <div className="bsr-tag-sheet">
      {/* A caller with its own per-row print control renders the cards
          without this header, so there is exactly one bulk action on a page. */}
      {showHeader && (
        <div className="bsr-tag-sheet-header no-print">
          <span className="bsr-tag-sheet-count">
            {printableTags.length} tag{printableTags.length === 1 ? '' : 's'}
            {productName ? ` for ${productName}` : ''}
          </span>
          {/* One action, one name. Where the tags come out is the system's
              problem, not the clerk's: with a label printer set up they go to
              it, without one they go to the print dialog. Naming the button
              after the mechanism ("Save as PDF") would describe the plumbing
              instead of the job. */}
          <div className="bsr-tag-sheet-actions">
            <button
              type="button"
              className="bsr-tag-sheet-print-btn"
              disabled={sending}
              onClick={() => (printerReady
                ? sendToPrinter(printableTags)
                : savePdf(printableTags, productName))}
            >
              {sending ? 'Printing…' : 'Print tags'}
            </button>

            {/* Only worth a second control once there IS a printer: the same
                labels on paper for when it is out of stock or somewhere else.
                Tiled on A4 rather than one per sheet — the label-sized PDF
                above is for feeding label stock through the print dialog,
                which is a different job. */}
            {printerReady && (
              <button
                type="button"
                className="bsr-tag-sheet-pdf-btn"
                onClick={() => savePdfSheet(printableTags, productName)}
              >
                Print as PDF
              </button>
            )}
          </div>
        </div>
      )}

      {showHeader && tightTag && (
        <p className="bsr-tag-sheet-warning no-print" role="status">
          These codes are too long for a {LABEL_WIDTH_MM} &times; {LABEL_HEIGHT_MM}&nbsp;mm label.
          The bars print {tightBarMm.toFixed(2)}&nbsp;mm wide, under the {COMFORTABLE_MODULE_MM}&nbsp;mm
          most handhelds need. Print one and scan it before running a batch.
        </p>
      )}

      <div className="bsr-tag-sheet-grid">
        {printableTags.map((tag) => (
          <TagCard
            key={tag.serial_number}
            tag={tag}
            productName={productName}
            onZoom={setZoomedTag}
          />
        ))}
      </div>

      {zoomedTag && (
        <BarcodeZoom
          value={zoomedTag.serial_number}
          label={productName}
          onClose={() => setZoomedTag(null)}
        />
      )}
    </div>
  );
}
