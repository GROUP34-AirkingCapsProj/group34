import React from 'react';
import BatchSelect from './BatchSelect';

/**
 * Outcome step for a zone mismatch — nothing is missing, the batch record just
 * points at the wrong zone.
 *
 * No quantity: nothing moves. RelocateResolution rewrites the batch's
 * zone_label from actual_zone, which store() has always accepted and the old
 * modal never sent — so standalone zone-mismatch reports resolved to a silent
 * no-op. This step is what makes them do something.
 */
export default function RelocateStep({
  batchId, setBatchId,
  availableBatches, batchesLoading,
  productId,
  actualZone, setActualZone,
  disabled,
}) {
  const selectedBatch = availableBatches.find((b) => String(b.batch_id) === String(batchId));

  return (
    <>
      <BatchSelect
        label="Which batch is in the wrong zone? *"
        emptyLabel="Select the batch that is in the wrong place…"
        batchId={batchId} setBatchId={setBatchId}
        availableBatches={availableBatches} batchesLoading={batchesLoading}
        productId={productId} disabled={disabled}
      />

      <div className="ak-field">
        <label className="ak-label" htmlFor="incident-flow-actual-zone">Where is it actually? *</label>
        <input
          id="incident-flow-actual-zone"
          className="ak-input"
          type="text"
          maxLength={100}
          value={actualZone}
          onChange={(e) => setActualZone(e.target.value)}
          placeholder="e.g. A-12"
          disabled={disabled}
          style={{ maxWidth: 260 }}
        />
        {selectedBatch && (
          <span className="ak-muted" style={{ fontSize: '0.8rem' }}>
            Recorded as <strong>{selectedBatch.zone_label || 'no zone'}</strong>.
            Resolving this report rewrites it to what you enter here.
          </span>
        )}
      </div>
    </>
  );
}
