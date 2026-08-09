import React from 'react';
import BatchSelect from './BatchSelect';

/**
 * Outcome step for a wrong-type report — the batch was received as the wrong
 * product.
 *
 * The batch is REQUIRED: RetypeResolution refuses to resolve without one,
 * because with no batch there is nothing to re-type and closing the report
 * would leave the real stock wrong while the record reads as fixed.
 *
 * What the item ACTUALLY is stays the reviewer's call in the resolve drawer —
 * incident_reports has no column to hold a filer's suggestion. Hence the prompt
 * to name it in the description on the next step.
 */
export default function RetypeStep({
  batchId, setBatchId,
  availableBatches, batchesLoading,
  productId,
  disabled,
}) {
  return (
    <BatchSelect
      label="Which batch is mislabelled? *"
      emptyLabel="Select the batch that is mislabelled…"
      batchId={batchId} setBatchId={setBatchId}
      availableBatches={availableBatches} batchesLoading={batchesLoading}
      productId={productId} disabled={disabled}
      note={(
        <div className="ak-state" style={{ marginTop: '0.4rem', fontSize: '0.82rem', textAlign: 'left' }}>
          On the next step, describe what the item on the shelf actually is — brand,
          model, anything on the label. A reviewer picks the corrected product from
          that description when they resolve this report.
        </div>
      )}
    />
  );
}
