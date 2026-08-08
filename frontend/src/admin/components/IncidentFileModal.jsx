import React, { useCallback, useEffect, useMemo, useState } from 'react';
import Modal from './Modal';
import {
  incidentReportsAPI,
  inventoryBatchesAPI,
  issuancesAPI,
  locationsAPI,
  productsAPI,
} from '../services/api';
import { toast } from '../utils/toast';
import { REASON_GROUPS, reasonMeta } from '../utils/incidentReasons';
import CameraCapture from './CameraCapture';
import { useIncidentPhoto } from '../hooks/useIncidentPhoto';

/**
 * IncidentFileModal — the standalone "Report Incident" front door, opened from
 * the Incident Reports page header.
 *
 * Deliberately NOT the same component as components/picking/IncidentModal.
 * That one is controlled and presentational: the scanner already knows the
 * product, batch, zone and quantity and the user only picks a reason. This one
 * has to let the user *find* all of that, so it owns lookups, search and
 * reason-driven required fields. Merging the two would give one component that
 * is half form and half read-only display with every field behind a mode flag.
 *
 * Props:
 *   isOpen   {boolean}
 *   onClose  {() => void}
 *   onFiled  {() => void}  fires ONLY after a successful file; caller refetches.
 */

/** `datetime-local` wants LOCAL YYYY-MM-DDTHH:mm — toISOString() is UTC and would be off by the offset. */
const toLocalInputValue = (date) => {
  const p = (n) => String(n).padStart(2, '0');
  return `${date.getFullYear()}-${p(date.getMonth() + 1)}-${p(date.getDate())}`
    + `T${p(date.getHours())}:${p(date.getMinutes())}`;
};

/** The backend validates `before_or_equal:now` at second precision, so send "Y-m-d H:i:s". */
const toBackendDateTime = (localValue) =>
  (localValue ? `${localValue.replace('T', ' ')}:00` : null);

const asArray = (res) => (Array.isArray(res?.data) ? res.data : []);

export default function IncidentFileModal({ isOpen, onClose, onFiled }) {
  const [reason, setReason] = useState('not_found');
  const [occurredAt, setOccurredAt] = useState('');
  const [maxOccurredAt, setMaxOccurredAt] = useState('');
  const [locationId, setLocationId] = useState('');
  const [productId, setProductId] = useState('');
  const [productFilter, setProductFilter] = useState('');
  const [quantity, setQuantity] = useState(1);
  const [batchId, setBatchId] = useState('');
  const [issuanceId, setIssuanceId] = useState('');
  const [issuanceSearch, setIssuanceSearch] = useState('');
  const [issuanceResults, setIssuanceResults] = useState([]);
  const [narrative, setNarrative] = useState('');
  const photo = useIncidentPhoto();
  // Aliased so the dep arrays below get plain identifiers the exhaustive-deps
  // rule can follow — a `photo.clear` member expression makes it demand the
  // whole (per-render) photo object instead.
  const { clear: clearPhoto, uploadIfAny: uploadPhotoIfAny } = photo;
  const [cameraOpen, setCameraOpen] = useState(false);
  const [submitting, setSubmitting] = useState(false);

  const [locations, setLocations] = useState([]);
  const [products, setProducts] = useState([]);
  const [batches, setBatches] = useState(null);   // null = not fetched yet
  const [batchesLoading, setBatchesLoading] = useState(false);

  const meta = reasonMeta(reason);
  const needsStock = !!meta.affectsStock;
  const isReturn = reason === 'customer_return';

  // ── reset on open ──────────────────────────────────────────────────────────
  useEffect(() => {
    if (!isOpen) return;
    const now = toLocalInputValue(new Date());
    setReason('not_found');
    setOccurredAt(now);
    setMaxOccurredAt(now);
    setLocationId('');
    setProductId('');
    setProductFilter('');
    setQuantity(1);
    setBatchId('');
    setIssuanceId('');
    setIssuanceSearch('');
    setIssuanceResults([]);
    setNarrative('');
    clearPhoto();
    setCameraOpen(false);
    setSubmitting(false);
  }, [isOpen, clearPhoto]);

  // Lookups small enough to hold in memory; both endpoints are paginated so the
  // rows arrive under `data` with a `pagination` sibling.
  useEffect(() => {
    if (!isOpen) return;
    locationsAPI.getAll({ per_page: 500 })
      .then((res) => setLocations(asArray(res)))
      .catch(() => setLocations([]));
    productsAPI.getAll({ per_page: 1000 })
      .then((res) => setProducts(asArray(res)))
      .catch(() => setProducts([]));
  }, [isOpen]);

  // Batches are NOT loaded on open. GET /inventory-batches returns every batch
  // with stock remaining in the caller's location scope in one un-paginated
  // array (a centralized user sees the whole org), which is the largest list
  // here and is only ever needed once a product has been chosen. Fetch it
  // lazily on that first need, then filter client-side — the endpoint ignores
  // query params, so a per-product request would fetch the same payload again.
  useEffect(() => {
    if (!isOpen || !needsStock || !productId || batches !== null || batchesLoading) return;
    setBatchesLoading(true);
    inventoryBatchesAPI.getAll()
      .then((res) => setBatches(asArray(res)))
      .catch(() => setBatches([]))
      .finally(() => setBatchesLoading(false));
  }, [isOpen, needsStock, productId, batches, batchesLoading]);

  // Switching to a reason that does not move stock must not leave stale product
  // / quantity / batch state behind for the payload to pick up.
  useEffect(() => {
    if (!needsStock) {
      setProductId('');
      setProductFilter('');
      setQuantity(1);
      setBatchId('');
    }
  }, [needsStock]);

  // Likewise the issuance link only means anything on a customer return.
  useEffect(() => {
    if (!isReturn) {
      setIssuanceId('');
      setIssuanceSearch('');
      setIssuanceResults([]);
    }
  }, [isReturn]);

  // A batch belongs to one product at one location — re-pick it if either changes.
  useEffect(() => { setBatchId(''); }, [productId, locationId]);

  // ── issuance search (customer returns only) ────────────────────────────────
  useEffect(() => {
    if (!isOpen || !isReturn) return undefined;
    const term = issuanceSearch.trim();
    if (term.length < 2) { setIssuanceResults([]); return undefined; }
    let cancelled = false;
    const t = setTimeout(() => {
      issuancesAPI.getAll({ search: term, per_page: 20 })
        .then((res) => { if (!cancelled) setIssuanceResults(asArray(res)); })
        .catch(() => { if (!cancelled) setIssuanceResults([]); });
    }, 300);
    return () => { cancelled = true; clearTimeout(t); };
  }, [isOpen, isReturn, issuanceSearch]);

  const filteredProducts = useMemo(() => {
    const term = productFilter.trim().toLowerCase();
    if (!term) return products.slice(0, 200);
    return products
      .filter((p) => `${p.product_name || ''} ${p.product_code || ''} ${p.barcode || ''}`
        .toLowerCase().includes(term))
      .slice(0, 200);
  }, [products, productFilter]);

  const availableBatches = useMemo(() => {
    if (!Array.isArray(batches) || !productId) return [];
    return batches.filter((b) => {
      if (String(b.product_id) !== String(productId)) return false;
      if (locationId && String(b.location_id) !== String(locationId)) return false;
      return true;
    });
  }, [batches, productId, locationId]);

  const selectedBatch = availableBatches.find((b) => String(b.batch_id) === String(batchId));

  const canSubmit =
    !submitting
    && !!reason
    && !!locationId
    && narrative.trim().length > 0
    && (!needsStock || (!!productId && Number(quantity) >= 1));

  const handleSubmit = useCallback(async () => {
    if (!canSubmit) return;
    setSubmitting(true);
    try {
      const { photo_path, photo_source } = await uploadPhotoIfAny();
      await incidentReportsAPI.file({
        source_type: 'standalone',
        location_id: Number(locationId),
        product_id: needsStock && productId ? Number(productId) : null,
        quantity: needsStock ? Number(quantity) : null,
        batch_id: needsStock && batchId ? Number(batchId) : null,
        related_issuance_id: isReturn && issuanceId ? Number(issuanceId) : null,
        reason,
        narrative: narrative.trim(),
        occurred_at: toBackendDateTime(occurredAt),
        photo_path,
        photo_source,
      });
      toast.success('Incident filed. A reviewer will pick it up from the triage list.');
      onFiled?.();
    } catch (err) {
      toast.error(err?.message || 'Failed to file the incident.');
    } finally {
      setSubmitting(false);
    }
  }, [canSubmit, uploadPhotoIfAny, locationId, needsStock, productId, quantity, batchId,
    isReturn, issuanceId, reason, narrative, occurredAt, onFiled]);

  if (!isOpen) return null;

  return (
    <Modal isOpen={isOpen} onClose={onClose} title="Report an incident" maxWidth={640}>
      <div className="ak-field" style={{ gap: '1rem', paddingTop: '1rem' }}>

        <fieldset className="ak-field" style={{ border: 'none', margin: 0, padding: 0 }}>
          <legend className="ak-label" style={{ marginBottom: '0.4rem' }}>What happened? *</legend>
          {REASON_GROUPS.map((group) => (
            <div key={group.name} style={{ marginBottom: '0.5rem' }}>
              <div className="ak-muted" style={{ fontSize: '0.75rem', textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                {group.name}
              </div>
              {group.reasons.map((r) => (
                <label
                  key={r.value}
                  style={{ display: 'flex', gap: '0.5rem', alignItems: 'flex-start', cursor: 'pointer', padding: '0.2rem 0' }}
                >
                  <input
                    type="radio"
                    name="file-incident-reason"
                    value={r.value}
                    checked={reason === r.value}
                    onChange={() => setReason(r.value)}
                    style={{ marginTop: '0.2rem' }}
                  />
                  <span>{r.label}</span>
                </label>
              ))}
            </div>
          ))}
          {meta.help && (
            <p className="ak-muted" style={{ fontSize: '0.8rem', margin: '0.2rem 0 0' }}>{meta.help}</p>
          )}
        </fieldset>

        <div className="ak-field">
          <label className="ak-label" htmlFor="file-incident-occurred">When did it happen? *</label>
          <input
            id="file-incident-occurred"
            className="ak-input"
            type="datetime-local"
            value={occurredAt}
            max={maxOccurredAt}
            onChange={(e) => setOccurredAt(e.target.value)}
            style={{ maxWidth: 260 }}
          />
        </div>

        <div className="ak-field">
          <label className="ak-label" htmlFor="file-incident-location">Location *</label>
          <select
            id="file-incident-location"
            className="ak-select"
            value={locationId}
            onChange={(e) => setLocationId(e.target.value)}
          >
            <option value="">Select a location…</option>
            {locations.map((l) => (
              <option key={l.location_id ?? l.id} value={l.location_id ?? l.id}>
                {l.location_name || l.name}
              </option>
            ))}
          </select>
        </div>

        {isReturn && (
          <div className="ak-field">
            <label className="ak-label" htmlFor="file-incident-issuance">Related issuance (optional)</label>
            <input
              id="file-incident-issuance"
              className="ak-input"
              type="text"
              value={issuanceSearch}
              onChange={(e) => setIssuanceSearch(e.target.value)}
              placeholder="Search by DR number or reference…"
            />
            {issuanceResults.length > 0 && (
              <select
                className="ak-select"
                value={issuanceId}
                style={{ marginTop: '0.4rem' }}
                onChange={(e) => {
                  const id = e.target.value;
                  setIssuanceId(id);
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
        )}

        {needsStock && (
          <>
            <div className="ak-field">
              <label className="ak-label" htmlFor="file-incident-product">Product *</label>
              <input
                id="file-incident-product"
                className="ak-input"
                type="text"
                value={productFilter}
                onChange={(e) => setProductFilter(e.target.value)}
                placeholder="Filter by name, code or barcode…"
              />
              <select
                className="ak-select"
                value={productId}
                style={{ marginTop: '0.4rem' }}
                onChange={(e) => setProductId(e.target.value)}
              >
                <option value="">Select a product…</option>
                {filteredProducts.map((p) => (
                  <option key={p.product_id} value={p.product_id}>
                    {p.product_name}{p.product_code ? ` (${p.product_code})` : ''}
                  </option>
                ))}
              </select>
            </div>

            <div className="ak-field">
              <label className="ak-label" htmlFor="file-incident-qty">Quantity affected *</label>
              <input
                id="file-incident-qty"
                className="ak-input"
                type="number"
                min={1}
                value={quantity}
                onChange={(e) => setQuantity(e.target.value)}
                style={{ maxWidth: 160 }}
              />
            </div>

            <div className="ak-field">
              <label className="ak-label" htmlFor="file-incident-batch">Batch (optional)</label>
              <select
                id="file-incident-batch"
                className="ak-select"
                value={batchId}
                disabled={!productId || batchesLoading}
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
                  className={`ak-state ${isReturn ? '' : 'ak-state--error'}`}
                  style={{ marginTop: '0.4rem', fontSize: '0.82rem', textAlign: 'left' }}
                >
                  {isReturn
                    ? `Resolving this report puts the returned stock back ONTO batch #${selectedBatch.batch_id}. Name the batch the item originally shipped from, or leave it blank if you are not sure.`
                    : `Naming batch #${selectedBatch.batch_id} holds it out of FIFO picking until this report is reviewed — nobody can pick from it in the meantime. Leave it blank if the incident is not specific to one batch.`}
                </div>
              )}
            </div>
          </>
        )}

        <div className="ak-field">
          <label className="ak-label" htmlFor="file-incident-narrative">Describe what you saw *</label>
          <textarea
            id="file-incident-narrative"
            className="ak-input"
            rows={4}
            maxLength={2000}
            value={narrative}
            onChange={(e) => setNarrative(e.target.value)}
            placeholder="What happened, where, and anything a reviewer would need to know…"
          />
          <span className="ak-muted" style={{ fontSize: '0.75rem' }}>{narrative.length}/2000</span>
        </div>

        <fieldset className="ak-field" style={{ border: 'none', margin: 0, padding: 0 }}>
          <legend className="ak-label" style={{ marginBottom: '0.4rem' }}>Evidence photo (optional)</legend>

          {!photo.file && !cameraOpen && (
            <div style={{ display: 'flex', alignItems: 'center', gap: '0.6rem', flexWrap: 'wrap' }}>
              <button
                type="button"
                className="ak-btn ak-btn--primary"
                onClick={() => setCameraOpen(true)}
                disabled={submitting}
              >
                📷 Take photo
              </button>
              <span className="ak-muted" style={{ fontSize: '0.85rem' }}>or</span>
              <input
                id="file-incident-photo"
                className="ak-input"
                type="file"
                accept="image/jpeg,image/png,image/webp"
                onChange={(e) => photo.setFromFile(e.target.files?.[0] || null)}
                disabled={submitting}
                style={{ maxWidth: 260 }}
              />
            </div>
          )}

          <CameraCapture
            isOpen={cameraOpen}
            onCapture={(f) => { photo.setFromCamera(f); setCameraOpen(false); }}
            onCancel={() => setCameraOpen(false)}
          />

          {photo.file && (
            <div style={{ display: 'flex', alignItems: 'center', gap: '0.6rem' }}>
              {photo.previewUrl && (
                <img src={photo.previewUrl} alt="Evidence preview"
                     style={{ width: 64, height: 64, objectFit: 'cover', borderRadius: 6 }} />
              )}
              <span className={`ak-badge ${photo.source === 'captured' ? 'ak-badge--green' : 'ak-badge--gray'}`}>
                {photo.source === 'captured' ? 'Taken in-app' : 'Uploaded from device'}
              </span>
              <button
                type="button"
                className="ak-btn ak-btn--sm"
                onClick={clearPhoto}
                disabled={submitting}
              >
                Remove
              </button>
            </div>
          )}
        </fieldset>

        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '0.6rem' }}>
          <button type="button" className="ak-btn" onClick={onClose} disabled={submitting}>Cancel</button>
          <button
            type="button"
            className="ak-btn ak-btn--primary"
            onClick={handleSubmit}
            disabled={!canSubmit}
          >
            {submitting ? 'Filing…' : 'File incident'}
          </button>
        </div>
      </div>
    </Modal>
  );
}
