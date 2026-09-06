import React from 'react';
import Barcode from 'react-barcode';

/**
 * One barcode card: the code, whose identity it is, and what has happened to
 * it.
 *
 * Rendered by two hosts with different needs — the per-product tag sheet,
 * which shows only tags we minted and offers no selection, and the printing
 * page, which shows all three tiers with checkboxes and a reprint action.
 * Every interactive part is therefore opt-in: pass no `onToggle` and there is
 * no checkbox. The card never decides on its own what may be printed; that
 * rule lives on the server in TagPrintPolicy.
 *
 * The card is sized for print, which is too small for a handheld to read off
 * a monitor. `onZoom` opens it at scanning size — see BarcodeZoom.
 */

const TIER_LABELS = {
  minted: 'Our tag',
  supplier: 'Supplier code',
  manufacturer: 'Manufacturer serial',
};

/* "Sent", never "printed". The server stamps when a job leaves for the
   printer; whether a sticker reached a carton is not something it can know,
   and a jam would make the stronger word a lie. */
function printState(tag) {
  const count = Number(tag?.print_count || 0);

  if (!count || !tag?.last_printed_at) return 'Not yet sent';

  const when = new Date(tag.last_printed_at).toLocaleDateString(undefined, {
    day: 'numeric', month: 'short',
  });

  return `Sent ${count}× · last ${when}`;
}

export default function TagCard({
  tag,
  productName,
  selected,
  onToggle,
  onZoom,
  onReprint,
}) {
  if (!tag) return null;

  const name = productName || tag.product_name;
  const tier = tag.tier || 'minted';

  return (
    <div className={`bsr-tag-card${selected ? ' bsr-tag-card--selected' : ''}`}>
      {onToggle && (
        <label className="bsr-tag-card-select no-print">
          <input
            type="checkbox"
            checked={Boolean(selected)}
            onChange={() => onToggle(tag.serial_id)}
            aria-label={`Select ${tag.serial_number}`}
          />
        </label>
      )}

      {name && <div className="bsr-tag-card-product">{name}</div>}

      {tag.tier && (
        <span className={`bsr-tag-card-tier bsr-tag-card-tier--${tier} no-print`}>
          {TIER_LABELS[tier] || TIER_LABELS.minted}
        </span>
      )}

      <button
        type="button"
        className="bsr-tag-card-zoom"
        onClick={() => onZoom?.(tag)}
        aria-label={`Enlarge tag ${tag.serial_number} for scanning`}
      >
        <Barcode
          value={tag.serial_number}
          format="CODE128"
          height={48}
          fontSize={12}
          displayValue
        />
        <span className="bsr-tag-card-enlarge no-print">Tap to enlarge</span>
      </button>

      {tag.tier && (
        <div className="bsr-tag-card-state no-print">{printState(tag)}</div>
      )}

      {onReprint && (
        <button
          type="button"
          className="bsr-tag-card-reprint no-print"
          onClick={() => onReprint(tag)}
        >
          Reprint this label
        </button>
      )}
    </div>
  );
}
