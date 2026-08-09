import React, { useEffect, useState } from 'react';
import { issuancesAPI } from '../../../services/api';
import QuantityInPieces from './QuantityInPieces';
import BatchSelect from './BatchSelect';

const asArray = (res) => (Array.isArray(res?.data) ? res.data : []);

/**
 * Outcome step for a customer return. How much came back, which batch it goes
 * onto, and — optionally — the issuance it originally went out on.
 *
 * The issuance is optional because a return can arrive with no paperwork; when
 * it is named, the location follows from it, which is almost always right.
 */
export default function RestockStep({
  packs, setPacks,
  loosePieces, setLoosePieces,
  quantity, setQuantity,
  usesPacks, piecesPerPackage, packageUnit, totalPieces,
  batchId, setBatchId,
  availableBatches, batchesLoading,
  productId,
  issuanceId, setIssuanceId,
  setLocationId,
  disabled,
}) {
  // Only `issuanceId` is submitted, so only it is lifted to the flow. The
  // search term and its results are the mechanics of finding one and stay here.
  const [issuanceSearch, setIssuanceSearch] = useState('');
  const [issuanceResults, setIssuanceResults] = useState([]);

  // Debounced so a typed DR number is one request, not one per keystroke. The
  // cancelled flag stops a slow earlier response overwriting a newer one.
  useEffect(() => {
    const term = issuanceSearch.trim();
    if (term.length < 2) { setIssuanceResults([]); return undefined; }
    let cancelled = false;
    const t = setTimeout(() => {
      issuancesAPI.getAll({ search: term, per_page: 20 })
        .then((res) => { if (!cancelled) setIssuanceResults(asArray(res)); })
        .catch(() => { if (!cancelled) setIssuanceResults([]); });
    }, 300);
    return () => { cancelled = true; clearTimeout(t); };
  }, [issuanceSearch]);

  const selectedBatch = availableBatches.find((b) => String(b.batch_id) === String(batchId));

  return (
    <>
      <QuantityInPieces
        label="Quantity returned"
        packs={packs} setPacks={setPacks}
        loosePieces={loosePieces} setLoosePieces={setLoosePieces}
        quantity={quantity} setQuantity={setQuantity}
        usesPacks={usesPacks} piecesPerPackage={piecesPerPackage}
        packageUnit={packageUnit} totalPieces={totalPieces}
        packNote="Stock is put back in pieces."
        disabled={disabled}
      />

      <div className="ak-field">
        <label className="ak-label" htmlFor="incident-flow-issuance">Related issuance (optional)</label>
        <input
          id="incident-flow-issuance"
          className="ak-input"
          type="text"
          value={issuanceSearch}
          onChange={(e) => setIssuanceSearch(e.target.value)}
          placeholder="Search by DR number or reference…"
          disabled={disabled}
        />
        {issuanceResults.length > 0 && (
          <select
            className="ak-select"
            value={issuanceId}
            style={{ marginTop: '0.4rem' }}
            disabled={disabled}
            onChange={(e) => {
              const id = e.target.value;
              setIssuanceId(id);
              // The stock came back to where it shipped from, so the issuance's
              // location is a better answer than whatever was picked earlier.
              const hit = issuanceResults.find((x) => String(x.issuance_id) === String(id));
              if (hit?.location_id) setLocationId(String(hit.location_id));
            }}
          >
            <option value="">Not linked to an issuance</option>
            {issuanceResults.map((x) => (
              <option key={x.issuance_id} value={x.issuance_id}>
                {x.dr_number || `Issuance #${x.issuance_id}`}
              </option>
            ))}
          </select>
        )}
      </div>

      <BatchSelect
        label="Batch (optional)"
        emptyLabel="Not batch-specific"
        batchId={batchId} setBatchId={setBatchId}
        availableBatches={availableBatches} batchesLoading={batchesLoading}
        productId={productId} disabled={disabled}
        note={selectedBatch && (
          <div
            className="ak-state"
            style={{ marginTop: '0.4rem', fontSize: '0.82rem', textAlign: 'left' }}
          >
            {`Resolving this report puts the returned stock back ONTO batch #${selectedBatch.batch_id}. Name the batch the item originally shipped from, or leave it blank if you are not sure.`}
          </div>
        )}
      />
    </>
  );
}
