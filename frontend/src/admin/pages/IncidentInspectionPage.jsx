import React, { useCallback, useEffect, useMemo, useState } from 'react';
import AdminLayout from '../components/AdminLayout';
import Loader from '../components/Loader';
import EvidenceChecklist, { outstandingRequirements, requirementMeta }
  from '../components/incident/EvidenceChecklist';
import IncidentReportPrint from '../components/incident/IncidentReportPrint';
import { incidentReportsAPI } from '../services/api';
import { useAuth } from '../context/AuthContext';
import { toast } from '../utils/toast';
import { reasonLabel } from '../utils/incidentReasons';
import { ITEM_OUTCOMES, itemStatusMeta, outcomeLabel, unitRoleLabel } from '../utils/incidentItems';
import { formatPieces } from '../utils/packageFormat';

/**
 * Inspections — the inspector's screen, and the step that now comes BEFORE the
 * admin decides anything.
 *
 * Filing quarantines the goods without deducting them: they are physically in
 * the building, still counted, simply unpickable. Nothing here moves stock
 * either. The inspector examines each unit, records an outcome for it, attaches
 * the client's checklist of photographs, prints the form and signs it with the
 * branch manager — and only then does an admin confirm, which is the single
 * moment a quantity changes.
 *
 * Statuses are read by NAME. The ids are install-specific, and a report the
 * admin REJECTED back sits at Awaiting Inspection rather than Open, so a queue
 * built on an `is_open` flag would silently lose every rejection.
 */

/** The two reasons that move no stock, and therefore need no inspection. */
const INSPECTION_EXEMPT_REASONS = ['zone_mismatch', 'other'];

const INSPECTABLE_STATUSES = ['Open', 'Awaiting Inspection'];

const IncidentInspectionPage = () => {
  const { can } = useAuth();
  const canInspect = typeof can === 'function' && can('incident_reports', 'inspect');

  const [queue, setQueue] = useState([]);
  const [loadingQueue, setLoadingQueue] = useState(true);
  const [queueError, setQueueError] = useState(null);

  const [selectedId, setSelectedId] = useState(null);
  const [report, setReport] = useState(null);
  const [loadingReport, setLoadingReport] = useState(false);
  const [reportError, setReportError] = useState(null);

  const loadQueue = useCallback(async () => {
    setLoadingQueue(true);
    try {
      const res = await incidentReportsAPI.list();
      const rows = Array.isArray(res?.data) ? res.data : [];
      setQueue(rows.filter((r) =>
        INSPECTABLE_STATUSES.includes(r.status_name)
        && !INSPECTION_EXEMPT_REASONS.includes(r.reason)));
      setQueueError(null);
    } catch (err) {
      setQueueError(err.message || 'Failed to load reports awaiting inspection.');
      setQueue([]);
    } finally {
      setLoadingQueue(false);
    }
  }, []);

  useEffect(() => { loadQueue(); }, [loadQueue]);

  const loadReport = useCallback(async (id) => {
    if (!id) { setReport(null); return; }
    setLoadingReport(true);
    try {
      const res = await incidentReportsAPI.get(id);
      setReport(res?.data || null);
      setReportError(null);
    } catch (err) {
      setReportError(err.message || 'Failed to load that report.');
      setReport(null);
    } finally {
      setLoadingReport(false);
    }
  }, []);

  useEffect(() => { loadReport(selectedId); }, [selectedId, loadReport]);

  return (
    <AdminLayout>
      <div className="ak-page">
        <header className="ak-header">
          <div className="ak-header-row">
            <div>
              <p className="ak-eyebrow">Inventory · Exceptions</p>
              <h1 className="ak-title">Inspections</h1>
              <p className="ak-sub">
                Examine what was reported, record what should happen to each unit, and attach the
                evidence. Nothing here moves stock — the quantities stay exactly as they are until an
                admin confirms your findings.
              </p>
            </div>
            <div className="ak-header-aside">
              <span className="ak-badge ak-badge--amber">{queue.length} to inspect</span>
            </div>
          </div>
        </header>

        {!canInspect && (
          <div className="ak-state">
            You can read these reports but not inspect them. Recording outcomes and attaching
            evidence needs the <strong>inspect</strong> permission.
          </div>
        )}

        <div style={{ display: 'grid', gridTemplateColumns: 'minmax(240px, 320px) 1fr', gap: '1rem', alignItems: 'start' }}>
          <div className="ak-card" style={{ padding: '0.6rem' }}>
            {loadingQueue ? (
              <Loader label="Loading reports" />
            ) : queueError ? (
              <div className="ak-state ak-state--error">{queueError}</div>
            ) : queue.length === 0 ? (
              <div className="ak-state">Nothing is waiting to be inspected.</div>
            ) : (
              <ul style={{ listStyle: 'none', margin: 0, padding: 0 }}>
                {queue.map((r) => (
                  <li key={r.incident_id}>
                    <button
                      type="button"
                      className={`ak-btn ${String(r.incident_id) === String(selectedId) ? 'ak-btn--primary' : ''}`}
                      style={{ width: '100%', justifyContent: 'flex-start', textAlign: 'left', marginBottom: '0.35rem' }}
                      onClick={() => setSelectedId(r.incident_id)}
                    >
                      <span>
                        <span className="ak-mono">{r.incident_number}</span>
                        <br />
                        <span style={{ fontSize: '0.8rem' }}>
                          {reasonLabel(r.reason)} · {r.location_name || '—'}
                        </span>
                        {r.status_name === 'Awaiting Inspection' && (
                          <>
                            <br />
                            {/* A resubmission, not a fresh report: the admin sent
                                this one back and the note says what to fix. */}
                            <span className="ak-badge ak-badge--blue">Sent back</span>
                          </>
                        )}
                      </span>
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </div>

          <div>
            {loadingReport ? (
              <Loader label="Loading report" />
            ) : reportError ? (
              <div className="ak-state ak-state--error">{reportError}</div>
            ) : !report ? (
              <div className="ak-state">Choose a report on the left to inspect it.</div>
            ) : (
              <InspectionForm
                key={report.incident_id}
                report={report}
                canInspect={canInspect}
                onChanged={setReport}
                onSubmitted={() => { loadQueue(); loadReport(report.incident_id); }}
              />
            )}
          </div>
        </div>
      </div>
    </AdminLayout>
  );
};

/** The report header, its units, the checklist, and the submission. */
function InspectionForm({ report, canInspect, onChanged, onSubmitted }) {
  const incidentId = report.incident_id;

  const [findings, setFindings] = useState(report.inspection_findings || '');
  const [checkedByAsc, setCheckedByAsc] = useState(!!report.checked_by_asc);
  const [signedByRep, setSignedByRep] = useState(report.signed_by_representative || '');
  const [signedByBm, setSignedByBm] = useState(report.signed_by_branch_manager || '');
  const [signedAt, setSignedAt] = useState(report.signed_at || '');
  const [submitting, setSubmitting] = useState(false);

  const items = useMemo(() => (Array.isArray(report.items) ? report.items : []), [report.items]);
  const evidence = useMemo(() => (Array.isArray(report.evidence) ? report.evidence : []), [report.evidence]);

  const outstanding = outstandingRequirements(report.reason, checkedByAsc, evidence);
  const undecided = items.filter((i) => i.status === 'quarantined' && !i.outcome);
  const blocked = outstanding.length > 0 || undecided.length > 0;

  /**
   * Patch the report in place rather than refetching.
   *
   * A refetch after every attachment would discard whatever the inspector has
   * typed into findings and the signature fields, which are not saved until
   * submit.
   */
  const patchReport = useCallback((patch) => {
    onChanged({ ...report, ...patch });
  }, [onChanged, report]);

  const handleEvidenceAdded = useCallback((row) => {
    if (!row) return;
    patchReport({ evidence: [...evidence, row] });
  }, [patchReport, evidence]);

  const handleEvidenceRemoved = useCallback((evidenceId) => {
    patchReport({ evidence: evidence.filter((e) => e.evidence_id !== evidenceId) });
  }, [patchReport, evidence]);

  const handleOutcome = useCallback(async (itemId, outcome, note) => {
    try {
      const res = await incidentReportsAPI.setItemOutcome(incidentId, itemId, {
        outcome, note: note || null,
      });
      const updated = res?.data;
      patchReport({ items: items.map((i) => (i.item_id === itemId ? { ...i, ...updated } : i)) });
    } catch (err) {
      toast.error(err.message || 'Could not record that outcome.');
    }
  }, [incidentId, items, patchReport]);

  const handleSubmit = useCallback(async () => {
    setSubmitting(true);
    try {
      await incidentReportsAPI.submitInspection(incidentId, {
        inspection_findings: findings.trim() || null,
        checked_by_asc: checkedByAsc,
        signed_by_representative: signedByRep.trim() || null,
        signed_by_branch_manager: signedByBm.trim() || null,
        signed_at: signedAt || null,
      });
      toast.success('Inspection submitted. An admin confirms it before any stock moves.');
      onSubmitted?.();
    } catch (err) {
      toast.error(err.message || 'Could not submit the inspection.');
    } finally {
      setSubmitting(false);
    }
  }, [incidentId, findings, checkedByAsc, signedByRep, signedByBm, signedAt, onSubmitted]);

  return (
    <div className="ak-card" style={{ padding: '1rem' }}>
      <div className="ak-header-row" style={{ marginBottom: '0.8rem' }}>
        <div>
          <h2 className="ak-title" style={{ fontSize: '1.1rem' }}>{report.incident_number}</h2>
          <p className="ak-sub" style={{ margin: 0 }}>
            {reasonLabel(report.reason)} · {report.location_name || '—'} · reported by{' '}
            {report.reported_by_name || '—'}
          </p>
        </div>
        <span className="ak-badge ak-badge--gray">{report.status_name}</span>
      </div>

      {report.rejection_note && (
        <div className="ak-state ak-state--error" style={{ marginBottom: '0.8rem' }}>
          <strong>Sent back by the admin:</strong> {report.rejection_note}
        </div>
      )}

      <div style={{ marginBottom: '0.8rem' }}>
        <div className="ak-label">Narrative</div>
        <div style={{ whiteSpace: 'pre-wrap' }}>{report.narrative || '—'}</div>
      </div>

      {(report.customer_name || report.dealer_name || report.site_address) && (
        <div style={{ marginBottom: '0.8rem' }}>
          <div className="ak-label">Dealer and customer</div>
          <div style={{ fontSize: '0.86rem' }}>
            {report.dealer_name && <div>Dealer: {report.dealer_name}{report.dealer_phone ? ` · ${report.dealer_phone}` : ''}</div>}
            {report.branch_address && <div>Branch: {report.branch_address}</div>}
            {report.customer_name && <div>Customer: {report.customer_name}{report.customer_contact ? ` · ${report.customer_contact}` : ''}</div>}
            {report.site_address && <div>Site: {report.site_address}</div>}
            {report.purchased_at && <div>Purchased: {report.purchased_at}</div>}
            {report.received_at_dor && <div>DOR: {report.received_at_dor}</div>}
          </div>
        </div>
      )}

      <h3 className="ak-label" style={{ marginTop: '1rem' }}>Units on this report</h3>
      {items.length === 0 ? (
        <div className="ak-state">This report names no physical units.</div>
      ) : (
        items.map((item) => (
          <ItemOutcomeRow
            key={item.item_id}
            item={item}
            disabled={!canInspect}
            onSave={handleOutcome}
          />
        ))
      )}

      <h3 className="ak-label" style={{ marginTop: '1rem' }}>Evidence</h3>
      <EvidenceChecklist
        incidentId={incidentId}
        reason={report.reason}
        checkedByAsc={checkedByAsc}
        evidence={evidence}
        onAdded={handleEvidenceAdded}
        onRemoved={handleEvidenceRemoved}
        disabled={!canInspect}
      />

      <div className="ak-field" style={{ marginTop: '1rem' }}>
        <label className="ak-label" htmlFor="inspection-findings">What you found</label>
        <textarea
          id="inspection-findings"
          className="ak-input"
          rows={4}
          maxLength={5000}
          value={findings}
          onChange={(e) => setFindings(e.target.value)}
          disabled={!canInspect}
          placeholder="What you examined, what you observed, and why each unit ends the way it does…"
        />
      </div>

      <div className="ak-field">
        <label className="ak-label" htmlFor="inspection-asc" style={{ display: 'flex', alignItems: 'center', gap: '0.4rem' }}>
          <input
            id="inspection-asc"
            type="checkbox"
            checked={checkedByAsc}
            onChange={(e) => setCheckedByAsc(e.target.checked)}
            disabled={!canInspect}
          />
          Checked by an authorised service centre (ASC)
        </label>
        <span className="ak-muted" style={{ fontSize: '0.8rem' }}>
          Ticking this adds the service report to the evidence the client’s form asks for.
        </span>
      </div>

      <fieldset className="ak-card" style={{ border: '1px solid var(--ak-line, #ddd)', padding: '0.75rem', margin: '0.6rem 0' }}>
        <legend className="ak-label" style={{ padding: '0 0.35rem' }}>Signatures on the hardcopy</legend>
        <div className="ak-muted" style={{ fontSize: '0.82rem', marginBottom: '0.5rem' }}>
          Print the form, sign it, and have the branch manager counter-sign before handing it to the
          admin. These are the names as printed — the system is recording that signing happened, not
          storing a digital signature.
        </div>
        <div style={{ display: 'flex', gap: '0.6rem', flexWrap: 'wrap' }}>
          <div className="ak-field" style={{ flex: '1 1 200px' }}>
            <label className="ak-label" htmlFor="inspection-signed-rep">Authorised representative</label>
            <input
              id="inspection-signed-rep"
              className="ak-input"
              type="text"
              maxLength={150}
              value={signedByRep}
              onChange={(e) => setSignedByRep(e.target.value)}
              disabled={!canInspect}
            />
          </div>
          <div className="ak-field" style={{ flex: '1 1 200px' }}>
            <label className="ak-label" htmlFor="inspection-signed-bm">Branch manager</label>
            <input
              id="inspection-signed-bm"
              className="ak-input"
              type="text"
              maxLength={150}
              value={signedByBm}
              onChange={(e) => setSignedByBm(e.target.value)}
              disabled={!canInspect}
            />
          </div>
          <div className="ak-field" style={{ flex: '0 0 170px' }}>
            <label className="ak-label" htmlFor="inspection-signed-at">Date signed</label>
            <input
              id="inspection-signed-at"
              className="ak-input"
              type="date"
              value={signedAt}
              onChange={(e) => setSignedAt(e.target.value)}
              disabled={!canInspect}
            />
          </div>
        </div>
      </fieldset>

      {blocked && canInspect && (
        <div className="ak-state" style={{ marginBottom: '0.6rem' }}>
          Still outstanding before this can be submitted:
          <ul style={{ margin: '0.3rem 0 0 1rem' }}>
            {undecided.length > 0 && (
              <li>{undecided.length} unit{undecided.length === 1 ? '' : 's'} without an outcome</li>
            )}
            {outstanding.map((req) => (
              <li key={req}>
                {requirementMeta(req).label} — attach a photo, or say why you can’t
              </li>
            ))}
          </ul>
        </div>
      )}

      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '0.6rem' }}>
        {/* Printable BEFORE submission, and before the admin confirms: this is
            the paper that gets signed and physically carried over, so the
            unsaved findings and signature names on screen are printed with it
            rather than the empty saved ones. */}
        <IncidentReportPrint
          report={report}
          overrides={{
            inspection_findings: findings.trim() || null,
            checked_by_asc: checkedByAsc,
            signed_by_representative: signedByRep.trim() || null,
            signed_by_branch_manager: signedByBm.trim() || null,
            signed_at: signedAt || null,
          }}
        />
        <button
          type="button"
          className="ak-btn ak-btn--primary"
          onClick={handleSubmit}
          disabled={!canInspect || blocked || submitting}
        >
          {submitting ? 'Submitting…' : 'Submit inspection'}
        </button>
      </div>
      <p className="ak-muted" style={{ fontSize: '0.8rem', textAlign: 'right', marginBottom: 0 }}>
        Submitting moves no stock. An admin confirms afterwards, and that is when the quantities
        change.
      </p>
    </div>
  );
}

/** One physical unit: what it is, and what the inspector decided about it. */
function ItemOutcomeRow({ item, disabled, onSave }) {
  const [note, setNote] = useState(item.outcome_note || '');
  const settled = item.status !== 'quarantined';
  const statusMeta = itemStatusMeta(item.status);
  const qtyLabel = formatPieces(item.quantity, item.pieces_per_package, item.package_unit);

  return (
    <fieldset
      className="ak-card"
      style={{ border: '1px solid var(--ak-line, #ddd)', padding: '0.75rem', margin: '0 0 0.6rem' }}
    >
      <legend className="ak-label" style={{ padding: '0 0.35rem' }}>
        {unitRoleLabel(item.unit_role)} <span className={`ak-badge ${statusMeta.badge}`}>{statusMeta.label}</span>
      </legend>

      <div style={{ fontSize: '0.86rem', marginBottom: '0.5rem' }}>
        <strong>{item.product_name || `#${item.product_id}`}</strong> · {qtyLabel}
        {item.batch_id ? ` · batch #${item.batch_id}` : ''}
        {item.model_code ? ` · model ${item.model_code}` : ''}
        {item.serial_number ? ` · SN ${item.serial_number}` : ''}
      </div>

      {settled ? (
        // Already applied by a confirmation: the decision is history now, and
        // the service refuses to change it.
        <div className="ak-muted" style={{ fontSize: '0.84rem' }}>
          Settled as <strong>{outcomeLabel(item.outcome)}</strong>
          {item.outcome_note ? ` — ${item.outcome_note}` : ''}
        </div>
      ) : (
        <>
          <div className="ak-field">
            <label className="ak-label" htmlFor={`item-outcome-${item.item_id}`}>What happens to this unit? *</label>
            <select
              id={`item-outcome-${item.item_id}`}
              className="ak-select"
              value={item.outcome || ''}
              disabled={disabled}
              onChange={(e) => e.target.value && onSave(item.item_id, e.target.value, note)}
            >
              <option value="">Choose an outcome…</option>
              {ITEM_OUTCOMES.map((o) => (
                <option key={o.value} value={o.value}>{o.label}</option>
              ))}
            </select>
            {item.outcome && (
              <span className="ak-muted" style={{ fontSize: '0.8rem' }}>
                {ITEM_OUTCOMES.find((o) => o.value === item.outcome)?.help}
              </span>
            )}
          </div>

          <div className="ak-field">
            <label className="ak-label" htmlFor={`item-note-${item.item_id}`}>Why</label>
            <input
              id={`item-note-${item.item_id}`}
              className="ak-input"
              type="text"
              maxLength={2000}
              value={note}
              onChange={(e) => setNote(e.target.value)}
              // Saved with the outcome the server already holds, so a note
              // typed after choosing is not lost — and a note typed BEFORE any
              // outcome is not sent, because there is nothing to attach it to.
              onBlur={() => { if (item.outcome && note !== (item.outcome_note || '')) onSave(item.item_id, item.outcome, note); }}
              disabled={disabled}
              placeholder="e.g. compressor seized, casing intact"
            />
          </div>
        </>
      )}
    </fieldset>
  );
}

export default IncidentInspectionPage;
