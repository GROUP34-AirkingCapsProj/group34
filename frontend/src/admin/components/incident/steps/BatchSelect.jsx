import React from 'react';

/**
 * The batch picker shared by all four outcome steps that have one (write-off,
 * restock, retype, relocate). The list, the "choose a product first" /
 * "loading" empty states and the no-batches hint are identical in every one;
 * only the label, the empty option's wording and the note below it differ, so
 * those are props rather than four copies that could drift apart.
 *
 * The note is a node, not a render prop: every caller already computes
 * `selectedBatch` for its own purposes, so passing the finished element is
 * simpler than handing one back through a callback.
 *
 * Purely presentational: `batchId` and its setter belong to IncidentDocument, which
 * is the only place the payload is assembled.
 */
export default function BatchSelect({
  label,
  emptyLabel,
  batchId, setBatchId,
  availableBatches, batchesLoading,
  productId,
  note = null,
  // The items step renders one of these per unit, and repeating a fixed id
  // would point every label at the first row's select.
  fieldId = 'incident-flow-batch',
  disabled,
}) {
  return (
    <div className="ak-field">
      <label className="ak-label" htmlFor={fieldId}>{label}</label>
      <select
        id={fieldId}
        className="ak-select"
        value={batchId}
        disabled={disabled || !productId || batchesLoading}
        onChange={(e) => setBatchId(e.target.value)}
      >
        <option value="">
          {!productId
            ? 'Choose a product first'
            : batchesLoading
              ? 'Loading batches…'
              : emptyLabel}
        </option>
        {availableBatches.map((b) => (
          <option key={b.batch_id} value={b.batch_id}>
            #{b.batch_id}
            {b.zone_label ? ` · ${b.zone_label}` : ''}
            {b.received_at ? ` · rcvd ${b.received_at}` : ''}
            {` · ${b.quantity_remaining} left`}
          </option>
        ))}
      </select>
      {productId && !batchesLoading && availableBatches.length === 0 && (
        <span className="ak-muted" style={{ fontSize: '0.8rem' }}>
          No batches with stock remaining for this product at this location.
        </span>
      )}
      {note}
    </div>
  );
}
