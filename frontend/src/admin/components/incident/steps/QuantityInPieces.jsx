import React from 'react';

/**
 * The quantity control shared by the two outcome steps that carry one
 * (write-off and restock). Identical in both, so it lives here rather than in
 * two copies that could drift apart — the pack arithmetic is exactly the thing
 * that was wrong before.
 *
 * Purely presentational: every value and setter belongs to IncidentFlow, which
 * is the only place the payload is assembled.
 */
export default function QuantityInPieces({
  label,
  packs, setPacks,
  loosePieces, setLoosePieces,
  quantity, setQuantity,
  usesPacks, piecesPerPackage, packageUnit, totalPieces,
  packNote,
  // The items step renders one of these per unit, and repeating a fixed id
  // would point every label at the first row's input.
  fieldId = 'incident-flow-qty',
  disabled,
}) {
  return (
    <div className="ak-field">
      <label className="ak-label" htmlFor={fieldId}>{label} *</label>

      {/* Two inputs when the product is sold in packs, one when it is not.
          Reporting "1" for a damaged box used to write off a single PIECE,
          because stock is stored in pieces and the field never said which
          unit it wanted. */}
      {usesPacks ? (
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', flexWrap: 'wrap' }}>
          <input
            id={fieldId}
            className="ak-input"
            type="number"
            min={0}
            value={packs}
            onChange={(e) => setPacks(e.target.value)}
            style={{ maxWidth: 90 }}
            disabled={disabled}
          />
          <span className="ak-muted">{packageUnit}</span>
          <span className="ak-muted">+</span>
          <input
            className="ak-input"
            type="number"
            min={0}
            value={loosePieces}
            onChange={(e) => setLoosePieces(e.target.value)}
            style={{ maxWidth: 90 }}
            disabled={disabled}
          />
          <span className="ak-muted">pcs</span>
          <strong style={{ marginLeft: '0.4rem' }}>= {totalPieces} pcs</strong>
        </div>
      ) : (
        <input
          id={fieldId}
          className="ak-input"
          type="number"
          min={1}
          value={quantity}
          onChange={(e) => setQuantity(e.target.value)}
          style={{ maxWidth: 160 }}
          disabled={disabled}
        />
      )}

      {usesPacks && (
        <span className="ak-muted" style={{ fontSize: '0.8rem' }}>
          1 {packageUnit} = {piecesPerPackage} pcs. {packNote}
        </span>
      )}
    </div>
  );
}
