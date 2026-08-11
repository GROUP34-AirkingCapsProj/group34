import React, { useEffect, useState } from 'react';
import Modal from '../Modal';
import { stockHoldsAPI } from '../../services/api';
import { toast } from '../../utils/toast';
import { confirmAction } from '../../utils/confirm';
import { packageBreakdown } from '../../utils/packageFormat';

/**
 * The inspection decision for one held quantity.
 *
 * The radio options come from the hold's `allowed_dispositions`, which the
 * server derives from its condition — a defective hold offers only
 * returned-to-supplier and scrapped, never restocked, because there must be no
 * one-click path putting a known-bad unit back into picking. Re-deriving that
 * here would be a second copy of the rule, free to disagree with the first.
 *
 * Props:
 *   hold      {object|null}  null closes the modal
 *   onClose   {() => void}
 *   onDisposed{() => void}   fires ONLY after a successful disposition
 */
const DISPOSITION_LABELS = {
  restocked: 'Inspected and sellable — put it back into stock',
  defective: 'Found defective — hold it as defective instead',
  returned_to_supplier: 'Send it back to the supplier',
  scrapped: 'Scrap it — it is destroyed',
};

export default function DisposeHoldModal({ hold, onClose, onDisposed }) {
  const [disposition, setDisposition] = useState('');
  const [note, setNote] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState(null);

  const holdId = hold?.hold_id ?? null;

  // Keyed on the hold id, not the object: the list refetches on an interval in
  // some views, and a new object identity for the same hold would wipe a
  // half-typed note underneath the user.
  useEffect(() => {
    setDisposition('');
    setNote('');
    setError(null);
    setSubmitting(false);
  }, [holdId]);

  if (!hold) return null;

  const breakdown = packageBreakdown(hold.quantity, hold.pieces_per_package, hold.package_unit);
  const qtyLabel = `${hold.quantity} pcs${breakdown ? ` · ${breakdown}` : ''}`;

  const handleSubmit = async () => {
    if (!disposition || submitting) return;

    // Restocking is the only disposition that puts stock back into picking,
    // so it gets the same second look as any other inventory-modifying submit.
    if (disposition === 'restocked') {
      const ok = await confirmAction({
        title: 'Put this stock back?',
        text: `This returns ${qtyLabel} of "${hold.product_name}" to active inventory, available to pick immediately.`,
        confirmText: 'Confirm & restock',
      });
      if (!ok) return;
    }

    setSubmitting(true);
    setError(null);
    try {
      await stockHoldsAPI.dispose(hold.hold_id, {
        disposition,
        note: note.trim() || null,
      });
      toast.success('Hold disposed.');
      onDisposed?.();
    } catch (err) {
      // A 422 is actionable — the hold was disposed by someone else, or the
      // disposition is not allowed for this condition. Keep the modal open
      // with the selection intact rather than closing over the message.
      setError(err?.message || 'Failed to dispose this hold.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Modal isOpen onClose={onClose} title={`Dispose ${hold.condition} stock`} maxWidth={560}>
      <div className="ak-field" style={{ gap: '1rem', paddingTop: '1rem' }}>
        <div className="ak-card" style={{ padding: '0.75rem' }}>
          <div style={{ fontSize: '0.9rem' }}>
            <strong>{hold.product_name || `#${hold.product_id}`}</strong> · {qtyLabel}
          </div>
          <div className="ak-muted" style={{ fontSize: '0.82rem', marginTop: '0.2rem' }}>
            {hold.location_name || '—'}
            {hold.batch_id ? ` · batch #${hold.batch_id}` : ''}
            {hold.incident_number ? ` · from ${hold.incident_number}` : ''}
          </div>
        </div>

        <fieldset className="ak-field" style={{ border: 'none', margin: 0, padding: 0 }}>
          <legend className="ak-label" style={{ marginBottom: '0.4rem' }}>What happens to it? *</legend>
          {hold.allowed_dispositions.map((d) => (
            <label
              key={d}
              style={{ display: 'flex', gap: '0.5rem', alignItems: 'center', padding: '0.25rem 0', cursor: 'pointer' }}
            >
              <input
                type="radio"
                name="hold-disposition"
                value={d}
                checked={disposition === d}
                onChange={() => setDisposition(d)}
                disabled={submitting}
              />
              <span>{DISPOSITION_LABELS[d] || d.replace(/_/g, ' ')}</span>
            </label>
          ))}
        </fieldset>

        <div className="ak-field">
          <label className="ak-label" htmlFor="hold-dispose-note">Note (optional)</label>
          <textarea
            id="hold-dispose-note"
            className="ak-input"
            rows={3}
            maxLength={2000}
            value={note}
            onChange={(e) => setNote(e.target.value)}
            placeholder="What did the inspection find?"
            disabled={submitting}
          />
        </div>

        {error && <div className="ak-state ak-state--error">{error}</div>}

        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '0.6rem' }}>
          <button type="button" className="ak-btn" onClick={onClose} disabled={submitting}>
            Cancel
          </button>
          <button
            type="button"
            className="ak-btn ak-btn--primary"
            onClick={handleSubmit}
            disabled={!disposition || submitting}
          >
            {submitting ? 'Saving…' : 'Confirm'}
          </button>
        </div>
      </div>
    </Modal>
  );
}
