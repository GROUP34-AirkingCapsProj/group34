import React, { useState } from 'react';
import { DocField } from './sections/DocumentSection';
import {
  ITEM_OUTCOMES, itemStatusMeta, outcomeLabel, outcomeMeta, outcomeRemovesStock,
  unitRoleLabel, warrantyStateMeta,
} from '../../utils/incidentItems';
import { formatPieces } from '../../utils/packageFormat';

/**
 * The inspector's half of the document, as fields.
 *
 * These are handed to the four sections below the rule as their `children`,
 * replacing the greyed previews Task 8 left there. They are separate from the
 * sections so the same sections can take a read-only rendering in `decide` mode
 * without either version growing a mode branch inside it.
 */

/** What the inspection concluded, and whether a service centre saw it. */
export function FindingsFields({ findings, setFindings, checkedByAsc, setCheckedByAsc, disabled }) {
  return (
    <>
      <DocField
        id="incdoc-findings-text"
        label="What you found"
        textarea
        rows={4}
        maxLength={5000}
        value={findings}
        onChange={setFindings}
        placeholder="What you examined, what you observed, and why each unit ends the way it does…"
        disabled={disabled}
      />

      <div className="ak-field">
        <label className="ak-label" htmlFor="incdoc-asc" style={{ display: 'flex', alignItems: 'center', gap: '0.4rem' }}>
          <input
            id="incdoc-asc"
            type="checkbox"
            checked={checkedByAsc}
            onChange={(e) => setCheckedByAsc(e.target.checked)}
            disabled={disabled}
          />
          Checked by an authorised service centre (ASC)
        </label>
        {/* A fact, not a type: ticking it adds the service report to the
            checklist above, which is why it lives beside the findings rather
            than in the attachments. */}
        <span className="ak-muted" style={{ fontSize: '0.8rem' }}>
          Ticking this adds the service report to the evidence the client’s form asks for.
        </span>
      </div>
    </>
  );
}

/**
 * The two names on the hardcopy.
 *
 * The system is recording that signing happened, not storing a signature —
 * what is legally signed is the printed sheet these names appear on.
 */
export function SignatureFields({
  signedByRep, setSignedByRep, signedByBm, setSignedByBm, signedAt, setSignedAt, disabled,
}) {
  return (
    <>
      <p className="incdoc__hint">
        Print this form, sign it, and have the branch manager counter-sign before handing it to the
        admin. These are the names as printed.
      </p>
      <div className="incdoc__row">
        <DocField
          id="incdoc-signed-rep" label="Authorised representative"
          value={signedByRep} onChange={setSignedByRep} disabled={disabled}
        />
        <DocField
          id="incdoc-signed-bm" label="Branch manager"
          value={signedByBm} onChange={setSignedByBm} disabled={disabled}
        />
        <DocField
          id="incdoc-signed-at" label="Date signed" type="date"
          value={signedAt} onChange={setSignedAt} disabled={disabled}
        />
      </div>
    </>
  );
}

/** Every unit on the report, each with its own decision. */
export function OutcomeRows({ items, disabled, onSave }) {
  const rows = Array.isArray(items) ? items : [];

  if (rows.length === 0) {
    return <p style={{ margin: 0 }}>This report names no physical units.</p>;
  }

  return rows.map((item) => (
    <ItemOutcomeRow key={item.item_id} item={item} disabled={disabled} onSave={onSave} />
  ));
}

/**
 * One physical unit: what it is, whether it is under warranty, and what the
 * inspector decided about it.
 *
 * **The choices come from the server**, per item, as `allowed_outcomes`. The
 * gate on `claim_warranty` is a real warranty record with an unexpired end
 * date, and setOutcome() enforces it with a 422 — so re-deriving it here could
 * only ever produce a screen that offers something the endpoint refuses, or
 * hides something it would have accepted. ITEM_OUTCOMES is consulted for
 * WORDING alone.
 */
function ItemOutcomeRow({ item, disabled, onSave }) {
  const [note, setNote] = useState(item.outcome_note || '');
  const settled = item.status !== 'quarantined';
  const status = itemStatusMeta(item.status);
  const warranty = warrantyStateMeta(item.warranty_state);
  const qtyLabel = formatPieces(item.quantity, item.pieces_per_package, item.package_unit);

  // Rendered as given. The already-chosen outcome is unioned in so a decision
  // recorded while the unit was still covered does not vanish from its own
  // dropdown the day the warranty lapses.
  const served = Array.isArray(item.allowed_outcomes) ? item.allowed_outcomes : [];
  const choices = item.outcome && !served.includes(item.outcome)
    ? [...served, item.outcome]
    : served;

  return (
    <fieldset className="incdoc__unit">
      <legend className="incdoc__unit-legend">
        {unitRoleLabel(item.unit_role)}{' '}
        <span className={`ak-badge ${status.badge}`}>{status.label}</span>
      </legend>

      <div style={{ fontSize: '0.86rem', marginBottom: '0.5rem' }}>
        <strong>{item.product_name || `#${item.product_id}`}</strong> · {qtyLabel}
        {item.batch_id ? ` · batch #${item.batch_id}` : ''}
        {item.model_code ? ` · model ${item.model_code}` : ''}
        {item.serial_number ? ` · SN ${item.serial_number}` : ''}
      </div>

      {/* Beside the selector, not inside it. `expired` and `none` offer the
          same choices but mean different things — one unit was sold and its
          cover ran out, the other was never registered at all — and an
          inspector who cannot tell them apart cannot ask the right question. */}
      {warranty && (
        <div style={{ marginBottom: '0.5rem' }}>
          <span className={`ak-badge ${warranty.badge}`}>{warranty.label}</span>{' '}
          <span className="ak-muted" style={{ fontSize: '0.8rem' }}>{warranty.help}</span>
        </div>
      )}

      {settled ? (
        // Applied by a confirmation: history now, and the service refuses to
        // change it.
        <div className="ak-muted" style={{ fontSize: '0.84rem' }}>
          Settled as <strong>{outcomeLabel(item.outcome)}</strong>
          {item.outcome_note ? ` — ${item.outcome_note}` : ''}
        </div>
      ) : (
        <>
          <div className="ak-field">
            <label className="ak-label" htmlFor={`incdoc-outcome-${item.item_id}`}>
              What happens to this unit? *
            </label>
            <select
              id={`incdoc-outcome-${item.item_id}`}
              className="ak-select"
              value={item.outcome || ''}
              disabled={disabled}
              onChange={(e) => e.target.value && onSave(item.item_id, e.target.value, note)}
            >
              <option value="">Choose an outcome…</option>
              {choices.map((o) => (
                <option key={o} value={o}>{outcomeLabel(o)}</option>
              ))}
            </select>
            {item.outcome && (
              <span className="ak-muted" style={{ fontSize: '0.8rem' }}>
                {outcomeMeta(item.outcome)?.help}
              </span>
            )}
            {/* Said plainly on the row where it is chosen, because the person
                confirming later is not the person choosing here. */}
            {outcomeRemovesStock(item.outcome) && (
              <span className="ak-muted" style={{ fontSize: '0.8rem' }}>
                This unit is deducted from stock when an admin confirms the report.
              </span>
            )}
            {choices.length < ITEM_OUTCOMES.length && item.warranty_state !== 'in_warranty' && (
              <span className="ak-muted" style={{ fontSize: '0.8rem' }}>
                A warranty claim is not offered for this unit — see the warranty note above.
              </span>
            )}
          </div>

          <DocField
            id={`incdoc-outcome-note-${item.item_id}`}
            label="Why"
            maxLength={2000}
            value={note}
            onChange={setNote}
            placeholder="e.g. compressor seized, casing intact"
            disabled={disabled}
          />
          <div style={{ marginTop: '-0.4rem' }}>
            <button
              type="button"
              className="ak-btn ak-btn--sm"
              // Saved WITH the outcome the server already holds, so a note
              // written after choosing is not lost — and a note written before
              // any outcome is not sent, because there is nothing to attach it
              // to. An explicit button rather than a blur handler: a blur that
              // silently posts gives no sign it saved, or that it did not.
              onClick={() => onSave(item.item_id, item.outcome, note)}
              disabled={disabled || !item.outcome || note === (item.outcome_note || '')}
            >
              Save this note
            </button>
          </div>
        </>
      )}
    </fieldset>
  );
}
