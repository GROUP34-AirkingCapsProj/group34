import React, { useEffect, useMemo, useState } from 'react';
import Modal from './Modal';
import { incidentReportsAPI, productsAPI } from '../services/api';
import { toast } from '../utils/toast';
import { confirmAction } from '../utils/confirm';
import { reasonLabel, reasonMeta } from '../utils/incidentReasons';

/**
 * Resolve drawer for a single incident report. Renders as a Modal (this
 * codebase has no dedicated slide-out drawer primitive — SetBuilderModal
 * follows the same pattern for its editor surface).
 *
 * Read-only for users without `incident_reports.resolve` — shows status,
 * narrative, photo, and (if already reviewed) the resolution note and who
 * reviewed it. Resolvers additionally get a Confirm / Dismiss form whose
 * shape depends on the incident's `reason`.
 *
 * Props:
 *   incident    {object|null}  the incident row; null/undefined = closed
 *   onClose     {() => void}
 *   canResolve  {boolean}      whether to render the Confirm/Dismiss form
 *   onResolved  {() => void}   called after a successful resolve (caller refetches)
 */
export default function IncidentResolveDrawer({ incident, onClose, canResolve, onResolved }) {
  const [note, setNote] = useState('');
  const [correctedProductId, setCorrectedProductId] = useState('');
  const [products, setProducts] = useState([]);
  const [productFilter, setProductFilter] = useState('');
  const [loadingProducts, setLoadingProducts] = useState(false);
  const [submitting, setSubmitting] = useState(null); // 'confirm' | 'dismiss' | null
  const [photoUrl, setPhotoUrl] = useState(null);
  const [photoFailed, setPhotoFailed] = useState(false);

  const isOpen = !!incident;
  const reason = incident?.reason;

  useEffect(() => {
    if (!isOpen) return;
    setNote('');
    setCorrectedProductId('');
    setProductFilter('');
    setSubmitting(null);
  }, [isOpen, incident?.incident_id]);

  useEffect(() => {
    // The picker only renders for an OPEN incident (see the action block below),
    // so skip the 1000-product fetch for one that has already been reviewed.
    if (!isOpen || !incident?.is_open || reasonMeta(reason).outcome !== 'retype' || !canResolve) return;
    setLoadingProducts(true);
    productsAPI.getAll({ per_page: 1000 })
      .then((r) => setProducts(Array.isArray(r?.data) ? r.data : []))
      .catch(() => setProducts([]))
      .finally(() => setLoadingProducts(false));
  }, [isOpen, incident?.is_open, reason, canResolve]);

  /**
   * Photos live on a private disk behind an authenticated route, so <img src>
   * would 401 — fetch a blob and hand <img> an object URL instead.
   *
   * `revoked` guards BOTH settle paths: without it on the catch, a slow failing
   * fetch for incident A could land after B loaded successfully and blank B's
   * image. Clearing photoUrl up front stops the previous incident's already-
   * revoked URL rendering as a broken image while the new one is in flight.
   */
  useEffect(() => {
    setPhotoUrl(null);
    setPhotoFailed(false);
    if (!incident?.photo_url) return undefined;

    let revoked = false;
    let objectUrl = null;

    incidentReportsAPI.photoBlob(incident.incident_id)
      .then((blob) => {
        if (revoked) return;
        objectUrl = URL.createObjectURL(blob);
        setPhotoUrl(objectUrl);
      })
      .catch(() => { if (!revoked) setPhotoFailed(true); });

    return () => {
      revoked = true;
      if (objectUrl) URL.revokeObjectURL(objectUrl);
    };
  }, [incident?.incident_id, incident?.photo_url]);

  const filteredProducts = useMemo(() => {
    const q = productFilter.trim().toLowerCase();
    if (!q) return products;
    return products.filter((p) =>
      String(p.product_name || '').toLowerCase().includes(q) ||
      String(p.product_code || '').toLowerCase().includes(q) ||
      String(p.barcode || '').toLowerCase().includes(q)
    );
  }, [products, productFilter]);

  if (!incident) return null;

  const outcome = reasonMeta(reason).outcome;
  const confirmDisabled =
    submitting !== null ||
    (outcome === 'retype' && !correctedProductId);
  const confirmLabel = outcome === 'none' ? 'Acknowledge' : 'Confirm';

  const doResolve = async (action) => {
    const payload = { action, resolution_note: note.trim() || null };
    // Keyed on outcome, not the reason string: the picker renders and Confirm
    // gates on `outcome === 'retype'`, so a payload keyed on the reason would
    // silently omit corrected_product_id for any future retype reason and 422.
    if (action === 'confirm' && outcome === 'retype') {
      payload.corrected_product_id = Number(correctedProductId);
    }
    setSubmitting(action);
    try {
      await incidentReportsAPI.resolve(incident.incident_id, payload);
      toast.success(action === 'confirm' ? 'Incident confirmed and resolved.' : 'Incident dismissed.');
      onResolved?.();
    } catch (err) {
      toast.error(err.message || 'Failed to resolve incident.');
    } finally {
      setSubmitting(null);
    }
  };

  const handleConfirm = async () => {
    if (confirmDisabled) return;
    if (outcome === 'writeoff') {
      const ok = await confirmAction({
        title: 'Confirm this incident?',
        text: `This writes stock off — it posts a deduction adjustment for ${incident.quantity} unit(s) of "${incident.product_name}". This cannot be undone from here.`,
        confirmText: 'Confirm & write off',
        tone: 'danger',
      });
      if (!ok) return;
    }

    if (outcome === 'restock') {
      // Not "a new batch": with no batch named, RestockResolution routes the
      // units to the location's catch-all/legacy batch, whose null received_at
      // sorts them FIRST in FIFO. Say so — it changes what gets picked next.
      const where = incident.batch_id
        ? `batch #${incident.batch_id}`
        : "the location's unbatched stock, where it will be picked first";
      const ok = await confirmAction({
        title: 'Put this stock back?',
        text: `This returns ${incident.quantity} unit(s) of "${incident.product_name}" to ${where} and makes them available again.`,
        confirmText: 'Confirm & restock',
      });
      if (!ok) return;
    }
    doResolve('confirm');
  };

  const handleDismiss = async () => {
    if (submitting !== null) return;
    doResolve('dismiss');
  };

  return (
    <Modal isOpen={isOpen} onClose={onClose} title={`Incident ${incident.incident_number}`} maxWidth={640}>
      <div className="ak-field" style={{ gap: '1rem', paddingTop: '1rem' }}>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: '0.6rem' }}>
          <div>
            <div className="ak-label">Reason</div>
            <div>{reasonLabel(reason)}</div>
          </div>
          <div>
            <div className="ak-label">Product</div>
            <div>{incident.product_name || (incident.product_id ? `#${incident.product_id}` : 'Not product-specific')}</div>
          </div>
          <div>
            <div className="ak-label">Quantity</div>
            <div>{incident.quantity}</div>
          </div>
          <div>
            <div className="ak-label">Source</div>
            <div>{incident.source_type === 'standalone' ? 'Reported directly' : `${incident.source_type} #${incident.source_id}`}</div>
          </div>
          <div>
            <div className="ak-label">Occurred</div>
            <div>{incident.occurred_at ? new Date(incident.occurred_at).toLocaleString() : '—'}</div>
          </div>
          {incident.related_issuance_id && (
            <div>
              <div className="ak-label">Original issuance</div>
              <div>{incident.related_issuance_number || `#${incident.related_issuance_id}`}</div>
            </div>
          )}
          <div>
            <div className="ak-label">Location</div>
            <div>{incident.location_name || '—'}</div>
          </div>
          <div>
            <div className="ak-label">Reported by</div>
            <div>{incident.reported_by_name || '—'}</div>
          </div>
        </div>

        {(incident.expected_zone || incident.actual_zone) && (
          <div>
            <div className="ak-label">Zone</div>
            <div className="ak-mono">{incident.expected_zone || '—'} → {incident.actual_zone || '—'}</div>
          </div>
        )}

        <div>
          <div className="ak-label">Narrative</div>
          <div style={{ whiteSpace: 'pre-wrap' }}>{incident.narrative || '—'}</div>
        </div>

        <div>
          <div className="ak-label">Photo</div>

          {/* Nothing renders when photo_source is null: incidents filed before
              the camera existed genuinely do not know how their photo arrived,
              and an "Uploaded" badge on them would be an invented claim.

              The wording is deliberately "Taken in-app", never "verified" —
              the server cannot check provenance, since a capture and a gallery
              pick arrive as identical uploads. It states what the app observed. */}
          {incident.photo_source && (
            <div style={{ marginBottom: '0.4rem' }}>
              <span className={`ak-badge ${incident.photo_source === 'captured' ? 'ak-badge--green' : 'ak-badge--gray'}`}>
                {incident.photo_source === 'captured' ? 'Taken in-app' : 'Uploaded from device'}
              </span>
            </div>
          )}

          {photoUrl ? (
            <img
              src={photoUrl}
              alt="Incident evidence"
              style={{ maxWidth: '100%', maxHeight: 320, borderRadius: 8, border: '1px solid var(--ak-line)' }}
            />
          ) : incident.photo_path ? (
            // The raw path is an internal storage key — meaningless to a
            // reviewer, and it read identically whether the fetch was still in
            // flight or had failed.
            <span className="ak-muted">
              {photoFailed ? 'Photo attached — it could not be loaded.' : 'Loading photo…'}
            </span>
          ) : (
            <span className="ak-muted">No photo attached.</span>
          )}
        </div>

        {!incident.is_open && (
          <div style={{ borderTop: '1px solid var(--ak-line)', paddingTop: '0.8rem' }}>
            <div className="ak-label">Review outcome</div>
            <div>Reviewed by {incident.reviewed_by_name || '—'}{incident.reviewed_at ? ` on ${new Date(incident.reviewed_at).toLocaleString()}` : ''}</div>
            {incident.resolution_note && <div style={{ marginTop: 4, whiteSpace: 'pre-wrap' }}>{incident.resolution_note}</div>}
            {incident.adjustment_id && <div style={{ marginTop: 4 }}>Adjustment posted: #{incident.adjustment_id}</div>}
            {incident.correction_id && <div style={{ marginTop: 4 }}>Batch correction: #{incident.correction_id}</div>}
          </div>
        )}

        {incident.is_open && canResolve && (
          <div style={{ borderTop: '1px solid var(--ak-line)', paddingTop: '0.9rem' }}>
            {outcome === 'writeoff' && (
              <p style={{ color: '#b91c1c', fontSize: '0.85rem', marginTop: 0 }}>
                Confirming this incident writes the stock off — it posts a deduction adjustment. This does not
                affect the batch's FIFO exclusion, which already happened when the incident was filed.
              </p>
            )}

            {outcome === 'restock' && (
              <p style={{ fontSize: '0.85rem', marginTop: 0 }}>
                Confirming puts <strong>{incident.quantity}</strong> unit(s) back into stock
                {incident.batch_id
                  ? <> on <strong>batch #{incident.batch_id}</strong></>
                  : <> as <strong>unbatched stock</strong>, which will be picked first</>}, and
                makes them available for picking again. It does not re-issue the correct item — run a normal
                issuance for that.
              </p>
            )}

            {outcome === 'none' && (
              <p style={{ fontSize: '0.85rem', marginTop: 0 }}>
                Acknowledging closes this report as reviewed and genuine. No stock moves and no adjustment is
                posted. Dismiss instead if the report was raised in error.
              </p>
            )}

            {outcome === 'retype' && (
              <div className="ak-field" style={{ marginBottom: '0.8rem' }}>
                <label className="ak-label">What is physically on the shelf? *</label>
                <input
                  className="ak-input"
                  placeholder="Filter products…"
                  value={productFilter}
                  onChange={(e) => setProductFilter(e.target.value)}
                  style={{ marginBottom: '0.4rem' }}
                />
                <select
                  className="ak-select"
                  value={correctedProductId}
                  onChange={(e) => setCorrectedProductId(e.target.value)}
                  disabled={loadingProducts}
                >
                  <option value="">{loadingProducts ? 'Loading products…' : 'Select the actual product…'}</option>
                  {filteredProducts.map((p) => (
                    <option key={p.product_id} value={String(p.product_id)}>
                      {p.product_name}{p.product_code ? ` (${p.product_code})` : ''}
                    </option>
                  ))}
                </select>
              </div>
            )}

            {outcome === 'relocate' && (
              <p style={{ fontSize: '0.85rem' }}>
                No quantity changes here — confirming just records the re-shelving from
                {' '}<strong>{incident.expected_zone || '—'}</strong> to <strong>{incident.actual_zone || '—'}</strong>.
              </p>
            )}

            <div className="ak-field" style={{ marginBottom: '0.8rem' }}>
              <label className="ak-label">Resolution note</label>
              <textarea
                className="ak-input"
                rows={3}
                maxLength={2000}
                value={note}
                onChange={(e) => setNote(e.target.value)}
              />
            </div>

            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '0.6rem' }}>
              <button type="button" className="ak-btn" onClick={handleDismiss} disabled={submitting !== null}>
                {submitting === 'dismiss' ? 'Dismissing…' : 'Dismiss'}
              </button>
              <button
                type="button"
                className="ak-btn ak-btn--primary"
                onClick={handleConfirm}
                disabled={confirmDisabled}
              >
                {submitting === 'confirm' ? 'Confirming…' : confirmLabel}
              </button>
            </div>
          </div>
        )}

        <div style={{ display: 'flex', justifyContent: 'flex-end' }}>
          <button type="button" className="ak-btn" onClick={onClose}>Close</button>
        </div>
      </div>
    </Modal>
  );
}
