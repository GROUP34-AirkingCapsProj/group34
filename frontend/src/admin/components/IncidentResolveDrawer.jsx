import React, { useCallback, useEffect, useState } from 'react';
import Modal from './Modal';
import { EvidenceThumbnail, requirementMeta } from './incident/EvidenceChecklist';
import IncidentReportPrint from './incident/IncidentReportPrint';
import { incidentReportsAPI } from '../services/api';
import { toast } from '../utils/toast';
import { confirmAction } from '../utils/confirm';
import {
  reasonLabel,
  SUSPECTED_CAUSES, ROOT_CAUSES, RETURN_DIRECTIONS,
} from '../utils/incidentReasons';
import { itemStatusMeta, outcomeLabel, outcomeMeta, unitRoleLabel } from '../utils/incidentItems';
import { formatPieces } from '../utils/packageFormat';

/**
 * The admin's decision screen — the digital counterpart of the signed hardcopy.
 *
 * This is now the LAST step, not the first. Filing quarantines the goods
 * without deducting them; the inspector examines each unit, records an outcome
 * and attaches the evidence; and only Confirm here moves stock, once, in one
 * transaction. So the screen's job is to show what is being approved: the
 * findings, who inspected and when, every unit with its chosen outcome, every
 * photograph, and every skipped requirement with the reason given for it.
 *
 * Three actions. **Confirm** applies every outcome. **Reject** returns it to
 * the inspector and requires a note — an inspector cannot act on "no". **
 * Dismiss** closes a report raised in error, releasing the quarantine without
 * moving anything.
 *
 * `zone_mismatch` and `other` move no stock, so they carry no inspection at
 * all and are confirmable straight from Open.
 *
 * Props:
 *   incident    {object|null}  the list row; null/undefined = closed
 *   onClose     {() => void}
 *   canResolve  {boolean}      whether to render the decision form
 *   onResolved  {() => void}   called after a successful decision (caller refetches)
 */

/** Reasons that reach the admin with no inspection, because nothing moves. */
const INSPECTION_EXEMPT_REASONS = ['zone_mismatch', 'other'];

/**
 * Human label for a stored cause value, falling back to the raw value so an
 * option added server-side without a client mirror still reads as something
 * rather than vanishing from the reviewer's screen.
 */
const causeLabel = (options, value) =>
  options.find((o) => o.value === value)?.label || value;

export default function IncidentResolveDrawer({ incident, onClose, canResolve, onResolved }) {
  const [note, setNote] = useState('');
  const [rejectionNote, setRejectionNote] = useState('');
  const [rejecting, setRejecting] = useState(false);
  const [submitting, setSubmitting] = useState(null); // 'confirm' | 'reject' | 'dismiss' | null
  const [photoUrl, setPhotoUrl] = useState(null);
  const [photoFailed, setPhotoFailed] = useState(false);

  const [detail, setDetail] = useState(null);
  const [detailError, setDetailError] = useState(null);

  const isOpen = !!incident;
  const incidentId = incident?.incident_id;
  const reason = incident?.reason;

  useEffect(() => {
    if (!isOpen) return;
    setNote('');
    setRejectionNote('');
    setRejecting(false);
    setSubmitting(null);
  }, [isOpen, incidentId]);

  /**
   * The list row carries none of the inspection: no items, no evidence, no
   * findings. Those come from the detail endpoint, and without them this screen
   * would ask an admin to approve a write-off sight unseen.
   */
  const loadDetail = useCallback(async () => {
    if (!incidentId) return;
    try {
      const res = await incidentReportsAPI.get(incidentId);
      setDetail(res?.data || null);
      setDetailError(null);
    } catch (err) {
      setDetail(null);
      setDetailError(err.message || 'The inspection could not be loaded.');
    }
  }, [incidentId]);

  useEffect(() => {
    setDetail(null);
    setDetailError(null);
    if (!isOpen) return;
    loadDetail();
  }, [isOpen, loadDetail]);

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

  if (!incident) return null;

  const exempt = INSPECTION_EXEMPT_REASONS.includes(reason);
  // By NAME: the status ids are install-specific, and "sent back to the
  // inspector" is a status rather than a flag.
  const statusName = detail?.status_name || incident.status_name;
  const inspected = statusName === 'Awaiting Inspection';
  const decidable = exempt ? statusName === 'Open' || inspected : inspected;
  const finished = statusName === 'Resolved' || statusName === 'Dismissed';

  const items = Array.isArray(detail?.items) ? detail.items : [];
  const evidence = Array.isArray(detail?.evidence) ? detail.evidence : [];
  const deducting = items.filter((i) => i.outcome && outcomeMeta(i.outcome)?.keepsStock === false);

  // "25 pcs · 2 Box + 5 pcs", or just "25 pcs" without a pack size. A reviewer
  // approving a permanent write-off must see which unit they are approving.
  const qtyLabel = formatPieces(incident.quantity, incident.pieces_per_package, incident.package_unit);

  const decide = async (action, payload = {}) => {
    setSubmitting(action);
    try {
      await incidentReportsAPI.decide(incident.incident_id, {
        action,
        resolution_note: note.trim() || null,
        ...payload,
      });
      toast.success({
        confirm: 'Confirmed. The inspected outcomes have been applied to stock.',
        reject: 'Sent back to the inspector.',
        dismiss: 'Dismissed. The quarantine has been released and nothing moved.',
      }[action]);
      onResolved?.();
    } catch (err) {
      toast.error(err.message || 'That decision could not be recorded.');
    } finally {
      setSubmitting(null);
    }
  };

  const handleConfirm = async () => {
    if (submitting !== null) return;

    // Confirming is the ONLY step in this workflow that changes a quantity, so
    // the dialog names what leaves rather than saying "are you sure".
    const text = exempt
      ? 'Nothing in stock changes — this report moves no goods. It is closed as reviewed and genuine.'
      : deducting.length > 0
        ? `This applies every inspected outcome in one go. ${deducting.length} unit${deducting.length === 1 ? '' : 's'} `
          + `(${deducting.map((i) => outcomeLabel(i.outcome).toLowerCase()).join(', ')}) `
          + 'will be deducted from stock. It cannot be undone from here.'
        : 'This applies every inspected outcome. Nothing is deducted — every unit goes back into stock.';

    const ok = await confirmAction({
      title: 'Confirm this report?',
      text,
      confirmText: 'Confirm',
      tone: deducting.length > 0 ? 'danger' : undefined,
    });
    if (!ok) return;
    decide('confirm');
  };

  const handleReject = async () => {
    if (submitting !== null) return;
    const written = rejectionNote.trim();
    // An inspector cannot act on "no". The note is what they fix.
    if (!written) return;
    decide('reject', { rejection_note: written });
  };

  const handleDismiss = async () => {
    if (submitting !== null) return;
    const ok = await confirmAction({
      title: 'Dismiss this report?',
      text: 'The report is closed as raised in error. The quarantine is released, the units go back '
        + 'into stock, and no quantity changes — nothing was ever deducted.',
      confirmText: 'Dismiss it',
    });
    if (!ok) return;
    decide('dismiss');
  };

  return (
    <Modal isOpen={isOpen} onClose={onClose} title={`Incident ${incident.incident_number}`} maxWidth={720}>
      <div className="ak-field" style={{ gap: '1rem', paddingTop: '1rem' }}>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: '0.6rem' }}>
          <div>
            <div className="ak-label">Reason</div>
            <div>{reasonLabel(reason)}</div>
          </div>
          <div>
            <div className="ak-label">Status</div>
            <div>{statusName || '—'}</div>
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
          <div className="ak-label">Photo filed with the report</div>

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

        {detailError && <div className="ak-state ak-state--error">{detailError}</div>}

        {exempt ? (
          <div className="ak-state">
            This type of report moves no stock, so there is no inspection to review — no units were
            quarantined and no evidence was asked for. Confirming records it as reviewed and genuine.
          </div>
        ) : (
          <>
            <div style={{ borderTop: '1px solid var(--ak-line)', paddingTop: '0.8rem' }}>
              <div className="ak-label">Inspection</div>
              {inspected || finished ? (
                <>
                  <div style={{ fontSize: '0.86rem' }}>
                    Inspected by {detail?.inspected_by_name || '—'}
                    {detail?.inspected_at ? ` on ${new Date(detail.inspected_at).toLocaleString()}` : ''}
                  </div>
                  {(detail?.signed_by_representative || detail?.signed_by_branch_manager) && (
                    <div className="ak-muted" style={{ fontSize: '0.82rem' }}>
                      Hardcopy signed by {detail.signed_by_representative || '—'} and{' '}
                      {detail.signed_by_branch_manager || '—'}
                      {detail.signed_at ? ` on ${detail.signed_at}` : ''}
                    </div>
                  )}
                  <div style={{ marginTop: 4, whiteSpace: 'pre-wrap' }}>
                    {detail?.inspection_findings || <span className="ak-muted">No findings were written.</span>}
                  </div>
                  {detail?.checked_by_asc && (
                    <div style={{ marginTop: 4 }}>
                      <span className="ak-badge ak-badge--blue">Checked by an ASC</span>
                    </div>
                  )}
                </>
              ) : (
                <div className="ak-state">
                  Not inspected yet. Someone has to examine the goods and submit their findings before
                  this can be confirmed — that is what the two signature lines on the form are for.
                </div>
              )}
              {detail?.rejection_note && (
                <div className="ak-muted" style={{ marginTop: 4, fontSize: '0.82rem' }}>
                  Previously sent back: {detail.rejection_note}
                </div>
              )}
            </div>

            <div>
              <div className="ak-label">Units and their outcomes</div>
              {items.length === 0 ? (
                <div className="ak-muted">No units are recorded on this report.</div>
              ) : (
                <ul style={{ listStyle: 'none', margin: 0, padding: 0 }}>
                  {items.map((item) => {
                    const status = itemStatusMeta(item.status);
                    const meta = outcomeMeta(item.outcome);
                    return (
                      <li key={item.item_id} style={{ padding: '0.4rem 0', borderBottom: '1px solid var(--ak-line)' }}>
                        <div>
                          <strong>{item.product_name || `#${item.product_id}`}</strong>
                          {' · '}{unitRoleLabel(item.unit_role)}
                          {' · '}{formatPieces(item.quantity, item.pieces_per_package, item.package_unit)}
                          {item.model_code ? ` · model ${item.model_code}` : ''}
                          {item.serial_number ? ` · SN ${item.serial_number}` : ''}
                          {' '}<span className={`ak-badge ${status.badge}`}>{status.label}</span>
                        </div>
                        <div style={{ fontSize: '0.86rem' }}>
                          {item.outcome ? (
                            <>
                              Outcome: <strong>{outcomeLabel(item.outcome)}</strong>
                              {meta?.keepsStock === false && (
                                <span className="ak-muted"> · deducted on confirmation</span>
                              )}
                              {item.outcome_note ? ` — ${item.outcome_note}` : ''}
                            </>
                          ) : (
                            <span className="ak-muted">No outcome recorded — this cannot be confirmed yet.</span>
                          )}
                        </div>
                      </li>
                    );
                  })}
                </ul>
              )}
            </div>

            <div>
              <div className="ak-label">Evidence</div>
              {evidence.length === 0 ? (
                <div className="ak-muted">Nothing was attached.</div>
              ) : (
                <ul style={{ listStyle: 'none', margin: 0, padding: 0 }}>
                  {evidence.map((e) => (
                    <li
                      key={e.evidence_id}
                      style={{ display: 'flex', alignItems: 'center', gap: '0.6rem', padding: '0.3rem 0' }}
                    >
                      {e.photo_path ? (
                        <EvidenceThumbnail incidentId={incident.incident_id} evidenceId={e.evidence_id} />
                      ) : (
                        <span className="ak-badge ak-badge--amber">Skipped</span>
                      )}
                      <span style={{ fontSize: '0.86rem' }}>
                        <strong>{requirementMeta(e.requirement).label}</strong>
                        {/* A skip is shown WITH its reason, always. An
                            incomplete report has to look incomplete rather
                            than merely thin. */}
                        {e.skipped_reason ? ` — ${e.skipped_reason}` : ''}
                      </span>
                      {e.photo_source && (
                        <span className={`ak-badge ${e.photo_source === 'captured' ? 'ak-badge--green' : 'ak-badge--gray'}`}>
                          {e.photo_source === 'captured' ? 'Taken in-app' : 'Uploaded from device'}
                        </span>
                      )}
                    </li>
                  ))}
                </ul>
              )}
            </div>
          </>
        )}

        {finished && (
          <div style={{ borderTop: '1px solid var(--ak-line)', paddingTop: '0.8rem' }}>
            <div className="ak-label">Decision</div>
            <div>
              {statusName} by {incident.reviewed_by_name || '—'}
              {incident.reviewed_at ? ` on ${new Date(incident.reviewed_at).toLocaleString()}` : ''}
            </div>
            {incident.resolution_note && <div style={{ marginTop: 4, whiteSpace: 'pre-wrap' }}>{incident.resolution_note}</div>}
            {incident.adjustment_id && <div style={{ marginTop: 4 }}>Adjustment posted: #{incident.adjustment_id}</div>}
            {incident.correction_id && <div style={{ marginTop: 4 }}>Batch correction: #{incident.correction_id}</div>}
          </div>
        )}

        {canResolve && !finished && (
          <div style={{ borderTop: '1px solid var(--ak-line)', paddingTop: '0.9rem' }}>
            <p style={{ fontSize: '0.85rem', marginTop: 0 }}>
              <strong>Confirming is what moves the stock.</strong> Nothing has been deducted so far —
              the quarantined units are still on the shelf and still counted. Confirming applies every
              outcome above in one transaction
              {deducting.length > 0
                ? <>, deducting <strong>{deducting.length}</strong> of them from inventory.</>
                : '.'}
            </p>

            <div className="ak-field" style={{ marginBottom: '0.8rem' }}>
              <label className="ak-label" htmlFor="incident-resolution-note">Note (optional)</label>
              <textarea
                id="incident-resolution-note"
                className="ak-input"
                rows={3}
                maxLength={2000}
                value={note}
                onChange={(e) => setNote(e.target.value)}
              />
            </div>

            {rejecting && (
              <div className="ak-field" style={{ marginBottom: '0.8rem' }}>
                <label className="ak-label" htmlFor="incident-rejection-note">
                  What does the inspector need to fix? *
                </label>
                <textarea
                  id="incident-rejection-note"
                  className="ak-input"
                  rows={3}
                  maxLength={2000}
                  value={rejectionNote}
                  onChange={(e) => setRejectionNote(e.target.value)}
                  placeholder="e.g. the sticker photo is unreadable — retake it"
                />
                <span className="ak-muted" style={{ fontSize: '0.8rem' }}>
                  Required. The report goes back to the inspector with this note; nobody can act on
                  a bare refusal.
                </span>
              </div>
            )}

            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '0.6rem', flexWrap: 'wrap' }}>
              <button type="button" className="ak-btn" onClick={handleDismiss} disabled={submitting !== null}>
                {submitting === 'dismiss' ? 'Dismissing…' : 'Dismiss'}
              </button>
              {rejecting ? (
                <>
                  <button
                    type="button"
                    className="ak-btn"
                    onClick={() => { setRejecting(false); setRejectionNote(''); }}
                    disabled={submitting !== null}
                  >
                    Cancel
                  </button>
                  <button
                    type="button"
                    className="ak-btn ak-btn--primary"
                    onClick={handleReject}
                    disabled={submitting !== null || !rejectionNote.trim()}
                  >
                    {submitting === 'reject' ? 'Sending back…' : 'Send back to the inspector'}
                  </button>
                </>
              ) : (
                <>
                  <button
                    type="button"
                    className="ak-btn"
                    onClick={() => setRejecting(true)}
                    disabled={submitting !== null || !inspected}
                  >
                    Reject
                  </button>
                  <button
                    type="button"
                    className="ak-btn ak-btn--primary"
                    onClick={handleConfirm}
                    disabled={submitting !== null || !decidable}
                  >
                    {submitting === 'confirm' ? 'Confirming…' : 'Confirm'}
                  </button>
                </>
              )}
            </div>

            {!decidable && (
              <p className="ak-muted" style={{ fontSize: '0.8rem', textAlign: 'right', marginBottom: 0 }}>
                Waiting on the inspection.
              </p>
            )}
          </div>
        )}

        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '0.6rem' }}>
          {/* The same document the inspector printed and signed, so the admin
              can check the paper in their hand against the record. The list row
              carries none of the client's document block, so this prints from
              the detail and stays disabled until it has loaded. */}
          <IncidentReportPrint report={detail} label="Print incident report" />
          <button type="button" className="ak-btn" onClick={onClose}>Close</button>
        </div>
      </div>
    </Modal>
  );
}
