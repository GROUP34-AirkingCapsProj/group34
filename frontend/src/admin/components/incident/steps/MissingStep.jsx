import React from 'react';
import { SUSPECTED_CAUSES } from '../../../utils/incidentReasons';
import QuantityInPieces from './QuantityInPieces';
import BatchSelect from './BatchSelect';

/**
 * Outcome step for a `missing` report. How much is gone, where it was last
 * seen, why the reporter thinks it went, and — optionally — from which batch.
 *
 * The suspected cause is what `theft` used to be as a reason of its own. It is
 * required: "the stock is gone" with no opinion about why is the one answer
 * that helps nobody investigating it later.
 *
 * Quantity is entered in packs plus loose pieces when the product has a pack
 * size, because reporting "1" for a missing box used to write off a single
 * PIECE. The total in pieces is what gets sent.
 */
export default function MissingStep({
  packs, setPacks,
  loosePieces, setLoosePieces,
  quantity, setQuantity,
  usesPacks, piecesPerPackage, packageUnit, totalPieces,
  batchId, setBatchId,
  availableBatches, batchesLoading,
  productId,
  lastKnownLocation, setLastKnownLocation,
  suspectedCause, setSuspectedCause,
  disabled,
}) {
  const selectedBatch = availableBatches.find((b) => String(b.batch_id) === String(batchId));

  return (
    <>
      <div className="ak-field">
        <label className="ak-label" htmlFor="incident-flow-last-known">Where did you last see it?</label>
        <input
          id="incident-flow-last-known"
          className="ak-input"
          type="text"
          maxLength={150}
          value={lastKnownLocation}
          onChange={(e) => setLastKnownLocation(e.target.value)}
          placeholder="e.g. Zone A-12, or the receiving bay"
          disabled={disabled}
        />
      </div>

      <fieldset className="ak-field" style={{ border: 'none', margin: 0, padding: 0 }}>
        <legend className="ak-label" style={{ marginBottom: '0.4rem' }}>Why do you think it&apos;s gone? *</legend>
        {SUSPECTED_CAUSES.map((c) => (
          <label key={c.value} style={{ display: 'flex', gap: '0.5rem', alignItems: 'center', padding: '0.2rem 0', cursor: 'pointer' }}>
            <input
              type="radio"
              name="incident-flow-suspected-cause"
              value={c.value}
              checked={suspectedCause === c.value}
              onChange={() => setSuspectedCause(c.value)}
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
