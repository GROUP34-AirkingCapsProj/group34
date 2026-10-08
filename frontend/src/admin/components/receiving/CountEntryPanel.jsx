import { useMemo, useState } from 'react';
import '../../styles/po_receiving.css';

/**
 * Minimal pluralizer covering the handful of package units this client
 * actually uses (Box, Roll, Drum, Carton, ...) without pulling in a
 * pluralization library for four words. Falls back to "packages" when
 * `package_unit` is absent, so the prompt never reads "How many undefineds?".
 */
function pluralizeUnit(unit) {
  if (!unit) return 'packages';
  return /(s|x|z|ch|sh)$/i.test(unit) ? `${unit}es` : `${unit}s`;
}

/**
 * Presentational count-entry control for a single PO line that arrived
 * without a barcode. Collects a hand count, shows the piece conversion and
 * remaining-on-line figure live, and warns (without blocking) on an
 * over-count. It never calls the API itself -- the parent screen owns
 * wiring `onSubmit` to inventoryAPI.receiveCount / the transfer count-scan
 * endpoints, since the same shape of confirm-and-retry-with-accept_overage
 * flow differs by caller.
 *
 * `onSubmit` is called with `{ units, actualPieces }`. `units` is the raw
 * count entered, matching `count_unit` on `line` -- never the piece-converted
 * total, since the backend does that conversion itself and would
 * double-convert package counts otherwise. `actualPieces` is the clerk's
 * verified piece count for a package-counted line (defaulting to the
 * computed expectation until they edit it), or `null` for a piece-counted
 * line, where the units entered already *are* the piece count and there is
 * nothing separate to verify.
 */
export default function CountEntryPanel({ line, onSubmit, onCancel, busy = false }) {
  const [value, setValue] = useState('');
  const [piecesValue, setPiecesValue] = useState('');
  const [piecesTouched, setPiecesTouched] = useState(false);

  const {
    product_name: productName,
    count_unit: countUnit,
    package_unit: packageUnit,
    pieces_per_package: piecesPerPackage,
    ordered_pieces: orderedPieces,
    already_received: alreadyReceived,
  } = line || {};

  const isPackage = countUnit === 'package';
  const inputId = 'count-entry-panel-quantity';

  const remaining = Math.max(0, Number(orderedPieces || 0) - Number(alreadyReceived || 0));

  const enteredUnits = useMemo(() => {
    const n = Number(value);
    return value !== '' && Number.isFinite(n) ? n : null;
  }, [value]);

  // Mirrors Product::getPiecesPerPackage() on the backend, which floors at 1
  // and never returns 0. Coercing to 0 here would show "= 0 pcs" for a
  // package line with no configured ratio while the server still posts the
  // units entered as pieces -- a silent disagreement worse than a visible 1:1.
  const ppp = Math.max(1, Number(piecesPerPackage) || 1);

  const convertedPieces = useMemo(() => {
    if (enteredUnits === null) return null;
    if (!isPackage) return enteredUnits;
    return enteredUnits * ppp;
  }, [enteredUnits, isPackage, ppp]);

  const isValid = enteredUnits !== null && enteredUnits > 0;

  const overage =
    isValid && convertedPieces !== null && convertedPieces > remaining
      ? convertedPieces - remaining
      : 0;

  const prompt = isPackage
    ? `How many ${pluralizeUnit(packageUnit)}?`
    : 'How many pieces?';

  // Expected pieces for a package-counted line is the package count times the
  // per-package ratio -- the pre-fill the clerk is verifying against.
  const expectedPieces = isPackage && convertedPieces !== null ? convertedPieces : null;

  const piecesDisplayValue = isPackage
    ? piecesTouched
      ? piecesValue
      : expectedPieces !== null
        ? String(expectedPieces)
        : ''
    : '';

  const actualPieces = useMemo(() => {
    if (!isPackage) return null;
    const n = Number(piecesDisplayValue);
    return piecesDisplayValue !== '' && Number.isFinite(n) ? n : null;
  }, [isPackage, piecesDisplayValue]);

  const piecesDiff =
    isPackage && expectedPieces !== null && actualPieces !== null
      ? actualPieces - expectedPieces
      : 0;

  const submit = () => {
    if (!isValid || busy) return;
    onSubmit({ units: enteredUnits, actualPieces: isPackage ? actualPieces : null });
  };

  const handleFormSubmit = (e) => {
    e.preventDefault();
    submit();
  };

  return (
    <form className="bsr-count-panel" onSubmit={handleFormSubmit}>
      {productName && <div className="bsr-count-product">{productName}</div>}

      <div className="bsr-count-remaining">
        {remaining} pcs remaining on this line
      </div>

      <label className="bsr-count-label" htmlFor={inputId}>
        {prompt}
      </label>
      <input
        id={inputId}
        type="number"
        min="1"
        step="1"
        inputMode="numeric"
        className="bsr-count-input"
        value={value}
        disabled={busy}
        onChange={(e) => setValue(e.target.value)}
        autoFocus
      />

      {isValid && convertedPieces !== null && (
        <div className="bsr-count-conversion">= {convertedPieces} pcs</div>
      )}

      {isPackage && (
        <>
          <label className="bsr-count-label" htmlFor="count-entry-pieces">
            Actual pieces received
          </label>
          <input
            id="count-entry-pieces"
            type="number"
            min="0"
            step="1"
            inputMode="numeric"
            className="bsr-count-input"
            value={piecesDisplayValue}
            disabled={busy}
            onChange={(e) => {
              setPiecesTouched(true);
              setPiecesValue(e.target.value);
            }}
          />

          {piecesDiff !== 0 && (
            <div className="bsr-count-pieces-diff" role="alert">
              {piecesDiff < 0
                ? `${Math.abs(piecesDiff)} pcs short of ${expectedPieces} expected`
                : `${piecesDiff} pcs more than expected`}
            </div>
          )}
        </>
      )}

      {overage > 0 && (
        <div className="bsr-count-overage-warning" role="alert">
          That is {overage} pcs more than ordered — you will be asked to confirm.
        </div>
      )}

      <div className="bsr-count-actions">
        {onCancel && (
          <button
            type="button"
            className="bsr-btn-cancel"
            onClick={onCancel}
            disabled={busy}
          >
            Cancel
          </button>
        )}
        <button
          type="submit"
          className="bsr-btn-complete"
          disabled={!isValid || busy}
        >
          {busy ? 'Submitting…' : 'Submit'}
        </button>
      </div>
    </form>
  );
}
