import React, { useEffect, useMemo, useState } from 'react';
import Modal from './Modal';
import { incidentReportsAPI, productsAPI } from '../services/api';
import { toast } from '../utils/toast';
import { confirmAction } from '../utils/confirm';
import {
  reasonLabel, reasonMeta,
  SUSPECTED_CAUSES, ROOT_CAUSES, RETURN_DIRECTIONS,
} from '../utils/incidentReasons';
import { formatPieces } from '../utils/packageFormat';

/**
 * Human label for a stored cause value, falling back to the raw value so an
 * option added server-side without a client mirror still reads as something
 * rather than vanishing from the reviewer's screen.
 */
const causeLabel = (options, value) =>
  options.find((o) => o.value === value)?.label || value;

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
  // "25 pcs · 2 Box + 5 pcs", or just "25 pcs" without a pack size. A reviewer
  // approving a permanent write-off must see which unit they are approving.
  const qtyLabel = formatPieces(incident.quantity, incident.pieces_per_package, incident.package_unit);
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
    // Approving no longer deletes stock — it moves it into a hold. Missing is
    // terminal; Defective and Returned go to Held Stock for a second decision.
    if (outcome === 'missing') {
      const ok = await confirmAction({
        title: 'Confirm this incident?',
        text: `This moves ${qtyLabel} of "${incident.product_name}" out of stock and records them as Missing. It posts a loss adjustment and cannot be undone from here.`,
        confirmText: 'Confirm & record the loss',
        tone: 'danger',
      });
      if (!ok) return;
    }

    if (outcome === 'defective') {
      const ok = await confirmAction({
        title: 'Confirm this incident?',
        text: `This moves ${qtyLabel} of "${incident.product_name}" into Defective. The stock leaves active inventory and appears in Held Stock, where someone decides whether it goes back to the supplier or is scrapped.`,
        confirmText: 'Confirm & hold as defective',
        tone: 'danger',
      });
      if (!ok) return;
    }

    if (outcome === 'return') {
      // Deliberately does NOT say the stock is available again — that was true
      // of the old restock-on-approval behaviour and is now the single most
      // misleading thing this dialog could claim.
      const where = incident.batch_id
        ? `batch #${incident.batch_id}`
        : "the location's unbatched stock, where it will be picked first";
      const ok = await confirmAction({
        title: 'Hold this stock for inspection?',
        text: `This holds ${qtyLabel} of "${incident.product_name}" as Returned. It does NOT become sellable — someone must inspect it in Held Stock first. If it is found sellable it goes back to ${where}.`,
        confirmText: 'Confirm & hold',
      });
      if (!ok) return;
    }

    if (outcome === 'relocate') {
      const ok = await confirmAction({
        title: 'Record this mismatch?',
        text: `Nothing in the system changes. The record is correct — someone needs to physically move the item back to ${incident.expected_zone || 'its recorded zone'}.`,
        confirmText: 'Record it',
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
            <div>{qtyLabel}</div>
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
          {/* What the reporter said caused it. Only one of these is ever set —
              the flow nulls the others by outcome — so they render as they
              come rather than being branched on the reason a second time. */}
          {incident.suspected_cause && (
            <div>
              <div className="ak-label">Suspected cause</div>
              <div>{causeLabel(SUSPECTED_CAUSES, incident.suspected_cause)}</div>
            </div>
          )}
          {incident.last_known_location && (
            <div>
              <div className="ak-label">Last seen</div>
              <div>{incident.last_known_location}</div>
            </div>
          )}
          {incident.root_cause && (
            <div>
              <div className="ak-label">Root cause</div>
              <div>{causeLabel(ROOT_CAUSES, incident.root_cause)}</div>
            </div>
          )}
          {incident.return_direction && (
            <div>
              <div className="ak-label">Direction</div>
              <div>{causeLabel(RETURN_DIRECTIONS, incident.return_direction)}</div>
            </div>
          )}
          {incident.return_reason && (
            <div>
              <div className="ak-label">Return reason</div>
              <div>{incident.return_reason}</div>
            </div>
          )}
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
            {outcome === 'missing' && (
              <p style={{ color: '#b91c1c', fontSize: '0.85rem', marginTop: 0 }}>
                Confirming moves <strong>{qtyLabel}</strong> out of active stock and records it as
                <strong> Missing</strong>, posting a loss adjustment. The stock stays countable for shrinkage
                reporting but has no way back — an item that turns up later needs a fresh report.
              </p>
            )}

            {outcome === 'defective' && (
              <p style={{ color: '#b45309', fontSize: '0.85rem', marginTop: 0 }}>
                Confirming moves <strong>{qtyLabel}</strong> into <strong>Defective</strong> and posts a damage
                adjustment. It will appear in Held Stock, where someone decides whether it goes back to the
                supplier or is scrapped. It cannot be put straight back into picking.
              </p>
            )}

            {outcome === 'return' && (
              <p style={{ fontSize: '0.85rem', marginTop: 0 }}>
                Confirming holds <strong>{qtyLabel}</strong> as <strong>Returned</strong>. It does
                <strong> not</strong> become sellable — it appears in Held Stock for inspection first. If it is
                found sellable it goes back
                {incident.batch_id
                  ? <> onto <strong>batch #{incident.batch_id}</strong></>
                  : <> as <strong>unbatched stock</strong>, which will be picked first</>}.
                It does not re-issue the correct item — run a normal issuance for that.
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
                <strong>Nothing in the system changes.</strong> The record says
                {' '}<strong>{incident.expected_zone || '—'}</strong> and that is correct — the item was
                physically put in <strong>{incident.actual_zone || '—'}</strong> by mistake. Confirming records
                the observation so someone can go and move it back.
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
