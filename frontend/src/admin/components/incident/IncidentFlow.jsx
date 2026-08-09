import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { incidentReportsAPI, locationsAPI, productsAPI } from '../../services/api';
import { toast } from '../../utils/toast';
import { reasonMeta } from '../../utils/incidentReasons';
import { stepsFor, toBackendInstant, totalPiecesFrom, STEP_KEYS } from '../../utils/incidentFlow';
import { useIncidentPhoto } from '../../hooks/useIncidentPhoto';
import WhatHappenedStep from './steps/WhatHappenedStep';
import DetailsStep from './steps/DetailsStep';

/** `datetime-local` wants LOCAL YYYY-MM-DDTHH:mm — toISOString() is UTC and would be off by the offset. */
const toLocalInputValue = (date) => {
  const p = (n) => String(n).padStart(2, '0');
  return `${date.getFullYear()}-${p(date.getMonth() + 1)}-${p(date.getDate())}`
    + `T${p(date.getHours())}:${p(date.getMinutes())}`;
};

const asArray = (res) => (Array.isArray(res?.data) ? res.data : []);

const STEP_TITLES = {
  [STEP_KEYS.WHAT]: 'What happened',
  [STEP_KEYS.WHERE]: 'Where and what',
  [STEP_KEYS.OUTCOME]: 'Details of the stock',
  [STEP_KEYS.DETAILS]: 'Describe and file',
};

/**
 * The guided incident flow — one wizard whose third step is chosen by what is
 * being reported.
 *
 * Replaces IncidentFileModal, which showed every field for every reason behind
 * conditional rendering. All state lives here and steps are controlled, so Back
 * never loses data and the payload is assembled in exactly one place.
 *
 * Props:
 *   onCancel {() => void}
 *   onFiled  {() => void}  fires ONLY after a successful file; caller refetches.
 */
export default function IncidentFlow({ onCancel, onFiled }) {
  const [stepIndex, setStepIndex] = useState(0);
  const [reason, setReason] = useState('not_found');
  const [occurredAt, setOccurredAt] = useState(() => toLocalInputValue(new Date()));
  const [maxOccurredAt] = useState(() => toLocalInputValue(new Date()));
  const [locationId, setLocationId] = useState('');
  const [productId, setProductId] = useState('');
  const [quantity, setQuantity] = useState(1);
  const [packs, setPacks] = useState(0);
  const [loosePieces, setLoosePieces] = useState(0);
  const [batchId, setBatchId] = useState('');
  const [actualZone, setActualZone] = useState('');
  const [issuanceId, setIssuanceId] = useState('');
  const [narrative, setNarrative] = useState('');
  const [scanHit, setScanHit] = useState(null);
  const [cameraOpen, setCameraOpen] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState(null);

  const [locations, setLocations] = useState([]);
  const [products, setProducts] = useState([]);

  const photo = useIncidentPhoto();
  // Destructured so exhaustive-deps gets a plain identifier — it cannot follow
  // `photo.uploadIfAny` and would demand the whole per-render object.
  const { uploadIfAny: uploadPhotoIfAny } = photo;

  const meta = reasonMeta(reason);
  const outcome = meta.outcome;
  const needsStock = !!meta.affectsStock;

  const steps = useMemo(() => stepsFor(reason), [reason]);
  // Changing the reason can shorten the flow (four steps to three). Clamping
  // rather than resetting keeps the user where they were.
  const safeIndex = Math.min(stepIndex, steps.length - 1);
  const step = steps[safeIndex];

  useEffect(() => {
    locationsAPI.getAll({ per_page: 500 })
      .then((res) => setLocations(asArray(res)))
      .catch(() => setLocations([]));
    productsAPI.getAll({ per_page: 1000 })
      .then((res) => setProducts(asArray(res)))
      .catch(() => setProducts([]));
  }, []);

  // Switching to a reason that does not move stock must not leave stale product
  // / quantity / batch state behind for the payload to pick up.
  useEffect(() => {
    if (!needsStock) {
      setProductId('');
      setQuantity(1);
      setPacks(0);
      setLoosePieces(0);
      setBatchId('');
      setActualZone('');
      setScanHit(null);
    }
  }, [needsStock]);

  // The issuance link only means anything on a restock.
  useEffect(() => {
    if (outcome !== 'restock') setIssuanceId('');
  }, [outcome]);

  const selectedProduct = products.find((p) => String(p.product_id) === String(productId));
  // The products list is capped at 1000, so a SCANNED product may not be in it.
  // The lookup response carries the same two fields, so prefer it when it
  // describes the product currently selected — otherwise a scan of an item
  // outside the cap silently loses its pack size and under-deducts.
  const scannedProduct = scanHit?.product
    && String(scanHit.product.product_id) === String(productId)
    ? scanHit.product
    : null;

  const piecesPerPackage = Number(
    scannedProduct?.pieces_per_package ?? selectedProduct?.pieces_per_package
  ) || 0;

  const totalPieces = totalPiecesFrom({ packs, loosePieces, quantity, piecesPerPackage });

  const canAdvance = (() => {
    if (step === STEP_KEYS.WHAT) return !!reason && !!occurredAt;
    if (step === STEP_KEYS.WHERE) return !!locationId && (!needsStock || !!productId);
    if (step === STEP_KEYS.OUTCOME) {
      if (outcome === 'writeoff' || outcome === 'restock') return totalPieces >= 1;
      // RetypeResolution refuses to resolve an incident with no batch, so a
      // wrong-type report without one could never be actioned.
      if (outcome === 'retype') return !!batchId;
      if (outcome === 'relocate') return !!batchId && !!actualZone.trim();
      return true;
    }
    if (step === STEP_KEYS.DETAILS) return narrative.trim().length > 0 && !submitting;
    return false;
  })();

  const handleSubmit = useCallback(async () => {
    setSubmitting(true);
    setSubmitError(null);
    try {
      const { photo_path, photo_source } = await uploadPhotoIfAny();
      await incidentReportsAPI.file({
        source_type: 'standalone',
        location_id: Number(locationId),
        product_id: needsStock && productId ? Number(productId) : null,
        quantity: needsStock ? totalPieces : null,
        batch_id: needsStock && batchId ? Number(batchId) : null,
        // expected_zone is where the record SAYS the batch is; actual_zone is
        // where it really is. Only a relocate asserts either.
        expected_zone: outcome === 'relocate' ? (scanHit?.batches
          ?.find((b) => String(b.batch_id) === String(batchId))?.zone_label ?? null) : null,
        actual_zone: outcome === 'relocate' && actualZone.trim() ? actualZone.trim() : null,
        related_issuance_id: outcome === 'restock' && issuanceId ? Number(issuanceId) : null,
        reason,
        narrative: narrative.trim(),
        occurred_at: toBackendInstant(occurredAt),
        photo_path,
        photo_source,
      });
      toast.success('Incident filed. A reviewer will pick it up from the triage list.');
      onFiled?.();
    } catch (err) {
      // Shown in place on this step, with the entered data intact — a failed
      // submit must never throw the user back to step 1.
      setSubmitError(err?.message || 'Failed to file the incident.');
    } finally {
      setSubmitting(false);
    }
  }, [uploadPhotoIfAny, locationId, needsStock, productId, totalPieces, batchId, outcome,
    actualZone, scanHit, issuanceId, reason, narrative, occurredAt, onFiled]);

  const isLast = safeIndex === steps.length - 1;

  return (
    <div className="ak-card" style={{ padding: '1rem' }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '0.75rem' }}>
        <div>
          <span className="ak-muted" style={{ fontSize: '0.78rem' }}>
            Step {safeIndex + 1} of {steps.length}
          </span>
          <h2 className="ak-label" style={{ margin: '0.15rem 0 0' }}>{STEP_TITLES[step]}</h2>
        </div>
        <button type="button" className="ak-btn ak-btn--sm" onClick={onCancel} disabled={submitting}>
          ← Back to list
        </button>
      </div>

      <div className="ak-field" style={{ gap: '1rem' }}>
        {step === STEP_KEYS.WHAT && (
          <WhatHappenedStep
            reason={reason} setReason={setReason}
            occurredAt={occurredAt} setOccurredAt={setOccurredAt}
            maxOccurredAt={maxOccurredAt} disabled={submitting}
          />
        )}
        {/* STEP_KEYS.WHERE  — Task 4 */}
        {/* STEP_KEYS.OUTCOME — Tasks 5 and 6 */}
        {step === STEP_KEYS.DETAILS && (
          <DetailsStep
            reason={reason}
            occurredAt={occurredAt}
            locationName={locations.find((l) => String(l.location_id ?? l.id) === String(locationId))?.location_name}
            productName={scannedProduct?.product_name || selectedProduct?.product_name}
            totalPieces={totalPieces}
            showQuantity={outcome === 'writeoff' || outcome === 'restock'}
            narrative={narrative} setNarrative={setNarrative}
            photo={photo} cameraOpen={cameraOpen} setCameraOpen={setCameraOpen}
            disabled={submitting}
          />
        )}

        {submitError && <div className="ak-state ak-state--error">{submitError}</div>}

        <div style={{ display: 'flex', justifyContent: 'space-between', gap: '0.6rem' }}>
          <button
            type="button"
            className="ak-btn"
            onClick={() => setStepIndex((i) => Math.max(0, i - 1))}
            disabled={safeIndex === 0 || submitting}
          >
            Back
          </button>
          <button
            type="button"
            className="ak-btn ak-btn--primary"
            onClick={() => (isLast ? handleSubmit() : setStepIndex(safeIndex + 1))}
            disabled={!canAdvance}
          >
            {isLast ? (submitting ? 'Filing…' : 'File incident') : 'Next'}
          </button>
        </div>
      </div>
    </div>
  );
}
