import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { incidentReportsAPI, inventoryBatchesAPI, locationsAPI, productsAPI } from '../../services/api';
import { toast } from '../../utils/toast';
import { reasonMeta } from '../../utils/incidentReasons';
import { toBackendInstant } from '../../utils/incidentFlow';
import { toClientPayload, toItemPayload } from '../../utils/incidentItems';
import { newUnit, resolveUnits } from '../../utils/incidentUnits';
import {
  DOC_MODES, SECTION, formFromReport, sectionsFor, sectionState,
  toLocalInputValue, whatInspectionIsMissing, whatIsMissing,
} from '../../utils/incidentDocument';
import { useIncidentPhoto } from '../../hooks/useIncidentPhoto';
import EvidenceChecklist, { requirementMeta } from './EvidenceChecklist';
import IncidentReportPrint from './IncidentReportPrint';
import { FindingsFields, OutcomeRows, SignatureFields } from './InspectionFields';
import TypeSection from './sections/TypeSection';
import ReturnSection from './sections/ReturnSection';
import DealerSection from './sections/DealerSection';
import UnitsSection from './sections/UnitsSection';
import StockSection from './sections/StockSection';
import UnitDefectSection from './sections/UnitDefectSection';
import ProblemSummarySection from './sections/ProblemSummarySection';
import AttachmentsSection from './sections/AttachmentsSection';
import FindingsSection from './sections/FindingsSection';
import OutcomesSection from './sections/OutcomesSection';
import SignaturesSection from './sections/SignaturesSection';
import '../../styles/incident_document.css';

/**
 * The INCIDENT REPORT FORM — one document, three modes.
 *
 * The report is a single file that travels. The reporter fills their half and
 * submits; the SAME document reaches the inspector, who adds evidence, findings
 * and outcomes to it; the SAME document reaches the admin, who sees everything
 * anyone has written in one place. Not three forms that share an id — one form
 * that accumulates.
 *
 *   file     reporter's half editable · inspector's half greyed  · Submit report
 *   inspect  reporter's half locked   · inspector's half editable · Print · Submit
 *   decide   everything locked                                    · Print · Confirm · Reject
 *
 * **Earlier sections lock; they never hide.** An inspector must see what the
 * reporter claimed — that is half of what they are checking — and an admin must
 * see both. Hiding a completed section to tidy the screen is how the person
 * with the authority to approve ends up approving something they never read.
 *
 * This file owns `file` and `inspect`. Task 10 adds `decide` against the same
 * section list and the same `sectionState()` rules, and Task 11 prints this
 * same markup.
 *
 * Props:
 *   mode        {'file'|'inspect'|'decide'}  defaults to `file`
 *   report      {object|null}  GET /api/incident-reports/{id}. Required by
 *                              `inspect` and `decide`: they render a document
 *                              that already exists.
 *   canInspect  {boolean}      whether the inspector's half accepts writing
 *   onCancel    {() => void}
 *   onFiled     {() => void}   fires ONLY after a successful file; caller refetches.
 *   onChanged   {(report) => void}  the report, patched in place after an
 *                              evidence or outcome write. NOT a refetch: a
 *                              refetch would discard whatever the inspector has
 *                              typed into findings and the signature fields,
 *                              which are not saved until submit.
 *   onSubmitted {() => void}   fires after a successful inspection submit.
 */

const asArray = (res) => (Array.isArray(res?.data) ? res.data : []);

const blankForm = () => ({
  reason: 'defective',
  occurredAt: toLocalInputValue(new Date()),
  locationId: '',
  // The return facts. Direction is derived from the counterparty, never asked.
  returnCounterparty: '',
  returnReasonCode: '',
  returnReason: '',
  issuanceId: '',
  // The dealer block.
  dealerName: '', dealerPhone: '', dealerMobile: '', dealerEmail: '', branchAddress: '',
  // The physical units. Always at least one row, so the sheet is never a blank
  // "add something" prompt.
  units: [newUnit('single')],
  receivedAtDor: '',
  // The lean block a zone mismatch gets instead of units.
  productId: '', batchId: '', actualZone: '',
  // Cause.
  rootCause: '', suspectedCause: '', lastKnownLocation: '',
  // Problem summary.
  customerName: '', customerContact: '', siteAddress: '', purchasedAt: '',
  narrative: '',
});

export default function IncidentDocument({
  mode = DOC_MODES.FILE, report = null, canInspect = true,
  onCancel, onFiled, onChanged, onSubmitted,
}) {
  const [draft, setDraft] = useState(blankForm);
  const [maxOccurredAt] = useState(() => toLocalInputValue(new Date()));
  const [cameraOpen, setCameraOpen] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState(null);
  const [showBlockers, setShowBlockers] = useState(false);

  // The inspector's half. Held locally and saved as one document on submit,
  // unlike the per-item outcomes and the evidence, which the server records the
  // moment they are chosen. Seeded lazily from the report: the parent patches
  // the report in place after every evidence write, and re-seeding on each of
  // those would wipe half-typed findings.
  const [findings, setFindings] = useState(() => report?.inspection_findings || '');
  const [checkedByAsc, setCheckedByAsc] = useState(() => !!report?.checked_by_asc);
  const [signedByRep, setSignedByRep] = useState(() => report?.signed_by_representative || '');
  const [signedByBm, setSignedByBm] = useState(() => report?.signed_by_branch_manager || '');
  const [signedAt, setSignedAt] = useState(() => report?.signed_at || '');

  const [locations, setLocations] = useState([]);
  const [products, setProducts] = useState([]);
  const [batches, setBatches] = useState(null);   // null = not fetched yet
  const [batchesLoading, setBatchesLoading] = useState(false);

  const photo = useIncidentPhoto();
  // Destructured so exhaustive-deps gets a plain identifier — it cannot follow
  // `photo.uploadIfAny` and would demand the whole per-render object.
  const { uploadIfAny: uploadPhotoIfAny } = photo;

  /**
   * The reporter's half.
   *
   * Read back out of the saved report once there is one, so the inspector and
   * the admin see the answers in the very fields they were typed into — locked,
   * in the same order, on the same sheet. Only `file` mode ever writes here,
   * which is why the draft and the hydrated form can share one variable without
   * either being able to overwrite the other.
   */
  const form = useMemo(() => (report ? formFromReport(report) : draft), [report, draft]);

  const patch = useCallback((partial) => setDraft((f) => ({ ...f, ...partial })), []);

  const patchUnit = useCallback((uid, partial) => setDraft((f) => ({
    ...f,
    units: f.units.map((u) => (u.uid === uid ? { ...u, ...partial } : u)),
  })), []);

  const addUnit = useCallback(() => setDraft((f) => {
    // The overwhelmingly common reason to add a second unit is a split system,
    // so the pair defaults to indoor + outdoor and saves two dropdown
    // interactions. Both remain changeable, and a third unit gets no guess.
    const pairing = f.units.length === 1;
    return {
      ...f,
      units: [
        ...f.units.map((u, i) => (
          i === 0 && pairing && u.unit_role === 'single' ? { ...u, unit_role: 'indoor' } : u
        )),
        newUnit(pairing ? 'outdoor' : 'single'),
      ],
    };
  }), []);

  const removeUnit = useCallback((uid) => setDraft((f) => (
    // Never below one row: an empty units list is indistinguishable from a
    // report about nothing, and the flat payload fields are read off row 1.
    f.units.length <= 1 ? f : { ...f, units: f.units.filter((u) => u.uid !== uid) }
  )), []);

  useEffect(() => {
    locationsAPI.getAll({ per_page: 500 })
      .then((res) => setLocations(asArray(res)))
      .catch(() => setLocations([]));
    productsAPI.getAll({ per_page: 1000 })
      .then((res) => setProducts(asArray(res)))
      .catch(() => setProducts([]));
  }, []);

  // Batches are NOT loaded on mount. GET /inventory-batches returns every batch
  // with stock remaining in the caller's location scope in one un-paginated
  // array (a centralized user sees the whole org), which is the largest list
  // here and is only needed once a product has been chosen. Fetch it lazily on
  // that first need, then filter client-side — the endpoint ignores query
  // params, so a per-product request would fetch the same payload again.
  const anyProductChosen = !!form.productId || form.units.some((u) => !!u.product_id);
  useEffect(() => {
    if (!anyProductChosen || batches !== null || batchesLoading) return;
    setBatchesLoading(true);
    inventoryBatchesAPI.getAll()
      .then((res) => setBatches(asArray(res)))
      .catch(() => setBatches([]))
      .finally(() => setBatchesLoading(false));
  }, [anyProductChosen, batches, batchesLoading]);

  const meta = reasonMeta(form.reason);
  const keys = useMemo(
    () => sectionsFor(form.reason, form.returnReasonCode),
    [form.reason, form.returnReasonCode],
  );
  const has = useCallback((k) => keys.includes(k), [keys]);

  // Pack arithmetic needs the catalogue, so it is resolved once here and both
  // the readiness check and the payload read the same numbers.
  const units = useMemo(() => resolveUnits(form.units, products), [form.units, products]);
  const missing = useMemo(() => whatIsMissing(form, units), [form, units]);
  const canSubmit = missing.length === 0 && !submitting;

  const state = useCallback((k) => sectionState(k, mode), [mode]);

  // ── the inspector's half ──────────────────────────────────────────────────

  const incidentId = report?.incident_id;
  const items = useMemo(() => (Array.isArray(report?.items) ? report.items : []), [report]);
  const evidence = useMemo(() => (Array.isArray(report?.evidence) ? report.evidence : []), [report]);

  /**
   * Patch the report in place rather than refetching. A refetch after every
   * attachment would discard the findings and signature names on screen, which
   * are not saved until the inspection is submitted.
   */
  const patchReport = useCallback((partial) => {
    if (!report) return;
    onChanged?.({ ...report, ...partial });
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
      // The server enforces the warranty gate with a 422 and says which state
      // the unit is in. Surfaced verbatim: "expired" and "no record" are
      // different problems with different next steps.
      toast.error(err?.message || 'Could not record that outcome.');
    }
  }, [incidentId, items, patchReport]);

  const inspectionMissing = useMemo(() => (report ? whatInspectionIsMissing({
    reason: report.reason,
    returnReasonCode: report.return_reason_code,
    checkedByAsc,
    evidence,
    items,
    requirementLabel: (req) => requirementMeta(req).label,
  }) : []), [report, checkedByAsc, evidence, items]);

  const handleSubmitInspection = useCallback(async () => {
    if (inspectionMissing.length > 0) { setShowBlockers(true); return; }
    setSubmitting(true);
    setSubmitError(null);
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
      setSubmitError(err?.message || 'Could not submit the inspection.');
    } finally {
      setSubmitting(false);
    }
  }, [inspectionMissing, incidentId, findings, checkedByAsc, signedByRep, signedByBm, signedAt, onSubmitted]);

  const handleSubmit = useCallback(async () => {
    if (missing.length > 0) { setShowBlockers(true); return; }
    setSubmitting(true);
    setSubmitError(null);
    try {
      const { photo_path: photoPath, photo_source: photoSource } = await uploadPhotoIfAny();

      const wantsUnits = keys.includes(SECTION.UNITS);
      const wantsReturn = keys.includes(SECTION.RETURN);
      const wantsDealer = keys.includes(SECTION.DEALER);
      const wantsStock = keys.includes(SECTION.STOCK);
      const isMissing = form.reason === 'missing';
      const isDefective = form.reason === 'defective';

      const items = wantsUnits
        ? units.map((u) => toItemPayload({
          productId: u.product_id,
          batchId: u.batch_id,
          serialId: u.serial_id,
          quantity: u.pieces,
          unitRole: u.unit_role,
          modelCode: u.model_code,
          serialNumber: u.serial_number,
          // Only ever the pair for the reason that asks for it, so a value
          // typed before the reason was changed cannot ride along.
          expectedProductId: form.returnReasonCode === 'wrong_product' ? u.expected_product_id : null,
          expectedQuantity: form.returnReasonCode === 'wrong_quantity' ? u.expected_quantity : null,
        }))
        : undefined;

      // The flat fields ARE unit 1. IncidentService prefers `items` when it is
      // present and folds the flat shape into one row when it is not, which is
      // what keeps picking/IncidentModal — the mid-pick path, which has no
      // units list at all — working. They must keep being sent.
      const primary = wantsUnits ? units[0] : null;

      await incidentReportsAPI.file({
        source_type: 'standalone',
        location_id: Number(form.locationId),
        product_id: wantsUnits
          ? (primary?.product_id ? Number(primary.product_id) : null)
          : (wantsStock && form.productId ? Number(form.productId) : null),
        batch_id: wantsUnits
          ? (primary?.batch_id ? Number(primary.batch_id) : null)
          : (wantsStock && form.batchId ? Number(form.batchId) : null),
        serial_id: wantsUnits && primary?.serial_id ? Number(primary.serial_id) : null,
        // A zone mismatch names no quantity — the record is right and the goods
        // are merely in the wrong aisle — but the server still requires one for
        // every reason except `other`.
        quantity: wantsUnits ? primary?.pieces : (meta.affectsStock ? 1 : null),
        ...(items ? { items } : {}),

        // Every block is nulled wholesale unless this type shows it. Doing it
        // HERE, rather than relying on resetting state when the type changes,
        // is what guarantees a half-filled block the reporter moved away from
        // cannot reach the API — a dealer typed into a `defective` must not
        // print on a theft report.
        ...(wantsDealer
          ? toClientPayload({
            dealerName: form.dealerName,
            dealerPhone: form.dealerPhone,
            dealerMobile: form.dealerMobile,
            dealerEmail: form.dealerEmail,
            branchAddress: form.branchAddress,
            customerName: form.customerName,
            customerContact: form.customerContact,
            siteAddress: form.siteAddress,
            purchasedAt: form.purchasedAt,
            receivedAtDor: form.receivedAtDor,
          })
          : toClientPayload({})),

        // expected_zone is where the record SAYS the batch is; actual_zone is
        // where it really is. Only a zone mismatch asserts either.
        expected_zone: form.reason === 'zone_mismatch'
          ? ((batches || []).find((b) => String(b.batch_id) === String(form.batchId))?.zone_label ?? null)
          : null,
        actual_zone: form.reason === 'zone_mismatch' && form.actualZone.trim()
          ? form.actualZone.trim()
          : null,
        related_issuance_id: wantsReturn && form.issuanceId ? Number(form.issuanceId) : null,

        last_known_location: isMissing ? (form.lastKnownLocation.trim() || null) : null,
        suspected_cause: isMissing ? (form.suspectedCause || null) : null,
        root_cause: isDefective ? (form.rootCause || null) : null,
        // return_direction is RETIRED — it conflated who the counterparty was
        // with which way the goods travel. The two facts below replace it, and
        // direction is derived from the counterparty on the server.
        return_counterparty: wantsReturn ? (form.returnCounterparty || null) : null,
        return_reason_code: wantsReturn ? (form.returnReasonCode || null) : null,
        return_reason: wantsReturn ? (form.returnReason.trim() || null) : null,

        reason: form.reason,
        narrative: form.narrative.trim(),
        occurred_at: toBackendInstant(form.occurredAt),
        photo_path: photoPath,
        photo_source: photoSource,
      });

      toast.success('Incident filed. A reviewer will pick it up from the triage list.');
      onFiled?.();
    } catch (err) {
      // Shown in place with everything typed still intact — a failed submit
      // must never throw the reporter back to an empty form.
      setSubmitError(err?.message || 'Failed to file the incident.');
    } finally {
      setSubmitting(false);
    }
  }, [missing, uploadPhotoIfAny, keys, units, form, meta, batches, onFiled]);

  const busy = submitting || mode !== DOC_MODES.FILE;
  const inspecting = mode === DOC_MODES.INSPECT;
  // Whichever half this person is filling in. One list, one place it renders.
  const blockers = inspecting ? inspectionMissing : missing;
  // The inspector's half is writable only when this person holds `inspect`.
  // Everything stays on screen either way: someone with read access must still
  // be able to see what was attached and decided.
  const inspectorLocked = !inspecting || !canInspect || submitting;

  return (
    <article className="incdoc">
      <header className="incdoc__head">
        <div>
          <h2 className="incdoc__title">Incident Report Form</h2>
          {report ? (
            <p className="incdoc__subtitle">
              <span className="ak-mono">{report.incident_number}</span>
              {' · '}{report.status_name || '—'}
              {' · reported by '}{report.reported_by_name || '—'}
            </p>
          ) : (
            <p className="incdoc__subtitle">
              One document. You fill in the top half; an inspector adds the evidence
              and the outcome below the line, and an admin confirms it.
            </p>
          )}
        </div>
        {onCancel && (
          <button type="button" className="ak-btn ak-btn--sm" onClick={onCancel} disabled={submitting}>
            ← Back to list
          </button>
        )}
      </header>

      {/* A resubmission, not a fresh report: the admin sent this one back, and
          the note is the thing that has to be fixed before it goes again. */}
      {report?.rejection_note && (
        <div className="ak-state ak-state--error" style={{ marginTop: '0.8rem' }}>
          <strong>Sent back by the admin:</strong> {report.rejection_note}
        </div>
      )}

      {inspecting && !canInspect && (
        <div className="ak-state" style={{ marginTop: '0.8rem' }}>
          You can read this report but not inspect it. Recording outcomes and attaching evidence
          needs the <strong>inspect</strong> permission.
        </div>
      )}

      <TypeSection
        state={state(SECTION.TYPE)}
        form={form} patch={patch}
        locations={locations}
        maxOccurredAt={maxOccurredAt}
        disabled={busy}
      />

      {has(SECTION.RETURN) && (
        <ReturnSection state={state(SECTION.RETURN)} form={form} patch={patch} disabled={busy} />
      )}

      {has(SECTION.DEALER) && (
        <DealerSection state={state(SECTION.DEALER)} form={form} patch={patch} disabled={busy} />
      )}

      {has(SECTION.UNITS) && (
        <UnitsSection
          state={state(SECTION.UNITS)}
          form={form} patch={patch}
          patchUnit={patchUnit} addUnit={addUnit} removeUnit={removeUnit}
          products={products} batches={batches} batchesLoading={batchesLoading}
          disabled={busy}
        />
      )}

      {has(SECTION.STOCK) && (
        <StockSection
          state={state(SECTION.STOCK)}
          form={form} patch={patch}
          products={products} batches={batches} batchesLoading={batchesLoading}
          disabled={busy}
        />
      )}

      {has(SECTION.UNIT_DEFECT) && (
        <UnitDefectSection state={state(SECTION.UNIT_DEFECT)} form={form} patch={patch} disabled={busy} />
      )}

      {has(SECTION.PROBLEM) && (
        <ProblemSummarySection
          state={state(SECTION.PROBLEM)}
          form={form} patch={patch}
          showCustomer={has(SECTION.DEALER)}
          photo={photo} cameraOpen={cameraOpen} setCameraOpen={setCameraOpen}
          disabled={busy}
          report={report}
        />
      )}

      {/* Where the document changes hands. Above it is the reporter's; below it
          is the inspector's; the admin writes in neither and only decides. */}
      {has(SECTION.FINDINGS) && (
        <>
          <hr className="incdoc__rule" />
          <p className="incdoc__rule-note">Everything below is the inspector&apos;s</p>
        </>
      )}

      {has(SECTION.ATTACHMENTS) && (
        <AttachmentsSection
          state={state(SECTION.ATTACHMENTS)}
          reason={form.reason}
          returnReasonCode={form.returnReasonCode}
        >
          {inspecting && report && (
            <EvidenceChecklist
              incidentId={incidentId}
              reason={report.reason}
              checkedByAsc={checkedByAsc}
              returnReasonCode={report.return_reason_code}
              evidence={evidence}
              onAdded={handleEvidenceAdded}
              onRemoved={handleEvidenceRemoved}
              disabled={inspectorLocked}
            />
          )}
        </AttachmentsSection>
      )}

      {has(SECTION.FINDINGS) && (
        <FindingsSection state={state(SECTION.FINDINGS)}>
          {inspecting && (
            <FindingsFields
              findings={findings} setFindings={setFindings}
              checkedByAsc={checkedByAsc} setCheckedByAsc={setCheckedByAsc}
              disabled={inspectorLocked}
            />
          )}
        </FindingsSection>
      )}

      {has(SECTION.OUTCOMES) && (
        <OutcomesSection state={state(SECTION.OUTCOMES)} units={form.units}>
          {inspecting && report && (
            <OutcomeRows items={items} disabled={inspectorLocked} onSave={handleOutcome} />
          )}
        </OutcomesSection>
      )}

      {has(SECTION.SIGNATURES) && (
        <SignaturesSection state={state(SECTION.SIGNATURES)}>
          {inspecting && (
            <SignatureFields
              signedByRep={signedByRep} setSignedByRep={setSignedByRep}
              signedByBm={signedByBm} setSignedByBm={setSignedByBm}
              signedAt={signedAt} setSignedAt={setSignedAt}
              disabled={inspectorLocked}
            />
          )}
        </SignaturesSection>
      )}

      {submitError && (
        <div className="ak-state ak-state--error" style={{ marginTop: '0.9rem' }}>{submitError}</div>
      )}

      {/* Said in words, not implied by a greyed-out button. A disabled Submit
          with no explanation is a dead end nobody can get out of — the reporter
          cannot see which answer is missing, and the inspector cannot see which
          requirement is outstanding. */}
      {showBlockers && blockers.length > 0 && (
        <div className="ak-state incdoc__blockers">
          {inspecting ? 'Still outstanding before this can be submitted:' : 'This report still needs:'}
          <ul>{blockers.map((m) => <li key={m}>{m}</li>)}</ul>
        </div>
      )}

      <div className="incdoc__actions">
        {mode === DOC_MODES.FILE && (
          <button
            type="button"
            className="ak-btn ak-btn--primary"
            onClick={handleSubmit}
            /* Deliberately NOT disabled while something is missing: clicking it
               is what reveals the list above. Only an in-flight submit blocks. */
            disabled={submitting}
            aria-disabled={!canSubmit}
          >
            {submitting ? 'Filing…' : 'Submit report'}
          </button>
        )}

        {inspecting && (
          <>
            {/* Printable BEFORE submission: this is the paper that gets signed
                and physically carried to the admin, so the unsaved findings and
                names on screen print with it rather than the empty saved ones. */}
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
              onClick={handleSubmitInspection}
              disabled={!canInspect || submitting}
              aria-disabled={blockers.length > 0}
            >
              {submitting ? 'Submitting…' : 'Submit inspection'}
            </button>
          </>
        )}
      </div>

      {inspecting && (
        <p className="ak-muted" style={{ fontSize: '0.8rem', textAlign: 'right', margin: '0.3rem 0 0' }}>
          Submitting moves no stock. An admin confirms afterwards, and that is when the quantities
          change.
        </p>
      )}
    </article>
  );
}
