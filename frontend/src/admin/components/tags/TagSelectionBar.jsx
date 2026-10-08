import React from 'react';

/**
 * What is ticked, and what will happen to it.
 *
 * The bar states the shape of the selection before the click, not after.
 * Bulk printing accepts only tags we minted, so a selection holding supplier
 * codes will print fewer labels than it holds — and a clerk who learns that
 * at the printer has already walked there. The server enforces the rule; this
 * only makes it visible in time to matter.
 */
export default function TagSelectionBar({
  count,
  ineligibleCount,
  truncatedTotal,
  printerReady,
  sending,
  onSelectAll,
  onClear,
  onSend,
  onSavePdf,
}) {
  if (!count) return null;

  return (
    <div className="bpp-selection no-print" role="region" aria-label="Selected tags">
      <span className="bpp-selection-count">{count} selected</span>

      <button type="button" className="bpp-selection-link" onClick={onSelectAll}>
        Select all matching filters
      </button>
      <button type="button" className="bpp-selection-link" onClick={onClear}>
        Clear
      </button>

      <div className="bpp-selection-actions">
        {printerReady && (
          <button
            type="button"
            className="bpp-selection-print"
            disabled={sending}
            onClick={onSend}
          >
            {sending ? 'Sending…' : 'Send to printer'}
          </button>
        )}
        <button type="button" className="bpp-selection-pdf" onClick={onSavePdf}>
          Print tag
        </button>
      </div>

      {/* This route builds the sticker in the browser and never reaches the
          server, so nothing can stamp it. Said out loud — and now that the
          button no longer names the format, said in full — because a print
          state that quietly under-reports sends a clerk back to reprint
          labels they are already holding. */}
      <p className="bpp-selection-note">
        Print tag makes a PDF and is not counted as sent.
      </p>

      {ineligibleCount > 0 && (
        <p className="bpp-selection-warning" role="status">
          {ineligibleCount} are supplier or manufacturer codes and won&apos;t be included in a
          bulk print. Use “Reprint this label” on the card to print one of those.
        </p>
      )}

      {/* The cap is stated, never silent: a clerk who believes 340 are
          selected and gets 200 discovers the other 140 at the shelf. */}
      {truncatedTotal > 0 && (
        <p className="bpp-selection-warning" role="status">
          {count} of {truncatedTotal} selected — print these, then select the rest.
        </p>
      )}
    </div>
  );
}
