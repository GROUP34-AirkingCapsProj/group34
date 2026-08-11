import React from 'react';
import { ROOT_CAUSES } from '../../../utils/incidentReasons';
import QuantityInPieces from './QuantityInPieces';
import BatchSelect from './BatchSelect';

/**
 * Outcome step for a `defective` report. How much is unsellable, where the
 * fault came from, and — optionally — from which batch.
 *
 * The root cause is what `damaged` and `delivery_damage` used to be as separate
 * reasons. It is required, because it is the field that decides whether the
 * held stock can be claimed back from a supplier or has to be scrapped.
 *
 * There is no "last seen" field here: unlike a missing item, a defective one is
 * in front of the reporter.
 */
export default function DefectiveStep({
  packs, setPacks,
  loosePieces, setLoosePieces,
  quantity, setQuantity,
  usesPacks, piecesPerPackage, packageUnit, totalPieces,
  batchId, setBatchId,
  availableBatches, batchesLoading,
  productId,
  rootCause, setRootCause,
  disabled,
}) {
  const selectedBatch = availableBatches.find((b) => String(b.batch_id) === String(batchId));

  return (
    <>
      <fieldset className="ak-field" style={{ border: 'none', margin: 0, padding: 0 }}>
        <legend className="ak-label" style={{ marginBottom: '0.4rem' }}>Where did the fault come from? *</legend>
        {ROOT_CAUSES.map((c) => (
          <label key={c.value} style={{ display: 'flex', gap: '0.5rem', alignItems: 'center', padding: '0.2rem 0', cursor: 'pointer' }}>
            <input
              type="radio"
              name="incident-flow-root-cause"
              value={c.value}
              checked={rootCause === c.value}
              onChange={() => setRootCause(c.value)}
              disabled={disabled}
            />
            <span>{c.label}</span>
          </label>
        ))}
      </fieldset>

      <QuantityInPieces
        label="Quantity affected"
        packs={packs} setPacks={setPacks}
        loosePieces={loosePieces} setLoosePieces={setLoosePieces}
        quantity={quantity} setQuantity={setQuantity}
        usesPacks={usesPacks} piecesPerPackage={piecesPerPackage}
        packageUnit={packageUnit} totalPieces={totalPieces}
        packNote="Stock is written off in pieces."
        disabled={disabled}
      />

      <BatchSelect
        label="Batch (optional)"
        emptyLabel="Not batch-specific"
        batchId={batchId} setBatchId={setBatchId}
        availableBatches={availableBatches} batchesLoading={batchesLoading}
        productId={productId} disabled={disabled}
        note={selectedBatch && (
          <div
            className="ak-state ak-state--error"
            style={{ marginTop: '0.4rem', fontSize: '0.82rem', textAlign: 'left' }}
          >
            {`Naming batch #${selectedBatch.batch_id} holds it out of FIFO picking until this report is reviewed — nobody can pick from it in the meantime. Leave it blank if the incident is not specific to one batch.`}
          </div>
        )}
      />
    </>
  );
}
