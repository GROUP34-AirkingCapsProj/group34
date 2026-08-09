import React from 'react';
import QuantityInPieces from './QuantityInPieces';

/**
 * Outcome step for the four write-off reasons (not found, theft, damaged,
 * damaged in delivery). How much is gone, and — optionally — from which batch.
 *
 * Quantity is entered in packs plus loose pieces when the product has a pack
 * size, because reporting "1" for a damaged box used to write off a single
 * PIECE. The total in pieces is what gets sent.
 */
export default function WriteOffStep({
  packs, setPacks,
  loosePieces, setLoosePieces,
  quantity, setQuantity,
  usesPacks, piecesPerPackage, packageUnit, totalPieces,
  batchId, setBatchId,
  availableBatches, batchesLoading,
  productId,
  disabled,
}) {
  const selectedBatch = availableBatches.find((b) => String(b.batch_id) === String(batchId));

  return (
    <>
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

      <div className="ak-field">
        <label className="ak-label" htmlFor="incident-flow-batch">Batch (optional)</label>
        <select
          id="incident-flow-batch"
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
                : 'Not batch-specific'}
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
        {selectedBatch && (
          <div
            className="ak-state ak-state--error"
            style={{ marginTop: '0.4rem', fontSize: '0.82rem', textAlign: 'left' }}
          >
            {`Naming batch #${selectedBatch.batch_id} holds it out of FIFO picking until this report is reviewed — nobody can pick from it in the meantime. Leave it blank if the incident is not specific to one batch.`}
          </div>
        )}
      </div>
    </>
  );
}
