import React, { useEffect, useState } from 'react';
import Modal from '../Modal';
import { incidentReportsAPI } from '../../services/api';
import { toast } from '../../utils/toast';
import { PICKING_REASONS } from '../../utils/incidentReasons';

/**
 * IncidentModal — shared "file an incident" surface for the issuance picker and
 * the transfer dispatch panel. CONTROLLED: the parent owns the context and
 * refetches the pick plan after a successful file (so the excluded batch drops
 * out and the next step appears). No longer purely presentational — filing
 * makes two calls, uploading the photo before submitting its path.
 *
 * ── Props ────────────────────────────────────────────────────────────────────
 *   context {object|null}  null/undefined ⇒ closed. Shape:
 *            { source_type: 'issuance'|'transfer',
 *              source_id:   number,
 *              location_id: number,
 *              product_id:  number,
 *              product_name?: string,          // display only
 *              batch_id:    number|null,       // the batch to exclude
 *              serial_id:   number|null,
 *              expected_zone: string|null,
 *              actual_zone:   string|null,
 *              quantity:    number }           // default for the qty field
 *
 *   onFiled() → void    called AFTER a successful file; parent refetches the plan.
 *                       (Named like IncidentResolveDrawer's onResolved — it fires
 *                       only on success, not on submit-attempt.)
 *   onClose() → void    close without filing.
 *
 * Submits through incidentReportsAPI.file() with exactly the keys the store
 * endpoint validates. A chosen photo is uploaded first via uploadPhoto() and
 * submitted as photo_path.
 */

export default function IncidentModal({ context, onFiled, onClose }) {
  const isOpen = !!context;
  const [reason, setReason] = useState('not_found');
  const [quantity, setQuantity] = useState(1);
  const [narrative, setNarrative] = useState('');
  const [photoFile, setPhotoFile] = useState(null);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!isOpen) return;
    setReason('not_found');
    setQuantity(Math.max(1, Number(context?.quantity) || 1));
    setNarrative('');
    setPhotoFile(null);
    setSubmitting(false);
  }, [isOpen, context?.product_id, context?.batch_id, context?.serial_id, context?.quantity]);

  if (!context) return null;

  const canSubmit =
    !submitting && narrative.trim().length > 0 && Number(quantity) >= 1;

  const handleSubmit = async () => {
    if (!canSubmit) return;
    setSubmitting(true);
    try {
      let photoPath = null;
      if (photoFile) {
        const up = await incidentReportsAPI.uploadPhoto(photoFile);
        photoPath = up?.data?.path ?? null;
        // Fail loudly rather than filing without the evidence the user attached
        // and still reporting success.
        if (!photoPath) throw new Error('The photo upload did not return a path. Nothing was filed.');
      }

      await incidentReportsAPI.file({
        source_type: context.source_type,
        source_id: Number(context.source_id),
        location_id: Number(context.location_id),
        product_id: Number(context.product_id),
        batch_id: context.batch_id != null ? Number(context.batch_id) : null,
        serial_id: context.serial_id != null ? Number(context.serial_id) : null,
        expected_zone: context.expected_zone ?? null,
        actual_zone: context.actual_zone ?? null,
        reason,
        quantity: Number(quantity),
        narrative: narrative.trim(),
        photo_path: photoPath,
      });
      toast.success('Incident filed. Picking may continue with the next batch.');
      onFiled?.();
    } catch (err) {
      toast.error(err?.message || 'Failed to file the incident.');
    } finally {
      setSubmitting(false);
    }
  };

  const selected = PICKING_REASONS.find((r) => r.value === reason);

  return (
    <Modal isOpen={isOpen} onClose={onClose} title="File incident report" maxWidth={560}>
      <div className="ak-field" style={{ gap: '1rem', paddingTop: '1rem' }}>
        <div>
          <div className="ak-label">Item</div>
          <div>{context.product_name || `Product #${context.product_id}`}</div>
          {(context.expected_zone || context.actual_zone) && (
            <div className="ak-mono ak-muted" style={{ fontSize: '0.85rem', marginTop: 2 }}>
              {context.expected_zone || '—'} → {context.actual_zone || '—'}
            </div>
          )}
        </div>

        <fieldset className="ak-field" style={{ border: 'none', margin: 0, padding: 0 }}>
          <legend className="ak-label" style={{ marginBottom: '0.4rem' }}>What's wrong?</legend>
          {PICKING_REASONS.map((r) => (
            <label
              key={r.value}
              style={{ display: 'flex', gap: '0.5rem', alignItems: 'flex-start', cursor: 'pointer', padding: '0.25rem 0' }}
            >
              <input
                type="radio"
                name="incident-reason"
                value={r.value}
                checked={reason === r.value}
                onChange={() => setReason(r.value)}
                style={{ marginTop: '0.2rem' }}
              />
              <span>{r.pickerLabel}</span>
            </label>
          ))}
          {selected?.help && (
            <p className="ak-muted" style={{ fontSize: '0.8rem', margin: '0.3rem 0 0' }}>
              {selected.help}
            </p>
          )}
        </fieldset>

        <div className="ak-field">
          <label className="ak-label" htmlFor="incident-qty">Quantity affected</label>
          <input
            id="incident-qty"
            className="ak-input"
            type="number"
            min={1}
            value={quantity}
            onChange={(e) => setQuantity(e.target.value)}
            style={{ maxWidth: 160 }}
          />
        </div>

        <div className="ak-field">
          <label className="ak-label" htmlFor="incident-narrative">What happened? *</label>
          <textarea
            id="incident-narrative"
            className="ak-input"
            rows={4}
            maxLength={2000}
            value={narrative}
            onChange={(e) => setNarrative(e.target.value)}
            placeholder="Describe what you saw…"
          />
        </div>

        <div className="ak-field">
          <label className="ak-label" htmlFor="incident-photo">Photo (optional)</label>
          <input
            id="incident-photo"
            className="ak-input"
            type="file"
            accept="image/*"
            onChange={(e) => setPhotoFile(e.target.files?.[0] || null)}
          />
          {photoFile && <span className="ak-muted" style={{ fontSize: '0.8rem' }}>{photoFile.name}</span>}
        </div>

        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '0.6rem' }}>
          <button type="button" className="ak-btn" onClick={onClose} disabled={submitting}>
            Cancel
          </button>
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
