import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { incidentReportsAPI, inventoryBatchesAPI, locationsAPI, productsAPI } from '../../services/api';
import { toast } from '../../utils/toast';
import { reasonMeta } from '../../utils/incidentReasons';
import { toBackendInstant } from '../../utils/incidentFlow';
import { toClientPayload, toItemPayload } from '../../utils/incidentItems';
import { newUnit, resolveUnits } from '../../utils/incidentUnits';
import {
  DOC_MODES, SECTION, sectionsFor, sectionState, whatIsMissing,
} from '../../utils/incidentDocument';
import { useIncidentPhoto } from '../../hooks/useIncidentPhoto';
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
 * This file owns `file` mode. Modes `inspect` and `decide` are wired but their
 * halves are still placeholders; Tasks 9 and 10 fill them in against the same
 * section list and the same `sectionState()` rules, and Task 11 prints this
 * same markup.
 *
 * Props:
 *   mode     {'file'|'inspect'|'decide'}  defaults to `file`
 *   onCancel {() => void}
 *   onFiled  {() => void}  fires ONLY after a successful file; caller refetches.
 */

/** `datetime-local` wants LOCAL YYYY-MM-DDTHH:mm — toISOString() is UTC and would be off by the offset. */
const toLocalInputValue = (date) => {
  const p = (n) => String(n).padStart(2, '0');
  return `${date.getFullYear()}-${p(date.getMonth() + 1)}-${p(date.getDate())}`
    + `T${p(date.getHours())}:${p(date.getMinutes())}`;
};

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

export default function IncidentDocument({ mode = DOC_MODES.FILE, onCancel, onFiled }) {
  const [form, setForm] = useState(blankForm);
  const [maxOccurredAt] = useState(() => toLocalInputValue(new Date()));
  const [cameraOpen, setCameraOpen] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState(null);
  const [showBlockers, setShowBlockers] = useState(false);

  const [locations, setLocations] = useState([]);
  const [products, setProducts] = useState([]);
  const [batches, setBatches] = useState(null);   // null = not fetched yet
  const [batchesLoading, setBatchesLoading] = useState(false);

  const photo = useIncidentPhoto();
  // Destructured so exhaustive-deps gets a plain identifier — it cannot follow
  // `photo.uploadIfAny` and would demand the whole per-render object.
  const { uploadIfAny: uploadPhotoIfAny } = photo;

  const patch = useCallback((partial) => setForm((f) => ({ ...f, ...partial })), []);

  const patchUnit = useCallback((uid, partial) => setForm((f) => ({
    ...f,
    units: f.units.map((u) => (u.uid === uid ? { ...u, ...partial } : u)),
  })), []);

  const addUnit = useCallback(() => setForm((f) => {
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

  const removeUnit = useCallback((uid) => setForm((f) => (
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

  return (
    <article className="incdoc">
      <header className="incdoc__head">
        <div>
          <h2 className="incdoc__title">Incident Report Form</h2>
          <p className="incdoc__subtitle">
            One document. You fill in the top half; an inspector adds the evidence
            and the outcome below the line, and an admin confirms it.
          </p>
        </div>
        {onCancel && (
          <button type="button" className="ak-btn ak-btn--sm" onClick={onCancel} disabled={submitting}>
            ← Back to list
          </button>
        )}
      </header>

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
        />
      )}

      {has(SECTION.FINDINGS) && <FindingsSection state={state(SECTION.FINDINGS)} />}

      {has(SECTION.OUTCOMES) && (
        <OutcomesSection state={state(SECTION.OUTCOMES)} units={form.units} />
      )}

      {has(SECTION.SIGNATURES) && <SignaturesSection state={state(SECTION.SIGNATURES)} />}

      {submitError && (
        <div className="ak-state ak-state--error" style={{ marginTop: '0.9rem' }}>{submitError}</div>
      )}

      {/* Said in words, not implied by a greyed-out button. A disabled Submit
          with no explanation is a dead end the reporter cannot get out of. */}
      {showBlockers && missing.length > 0 && (
        <div className="ak-state incdoc__blockers">
          This report still needs:
          <ul>{missing.map((m) => <li key={m}>{m}</li>)}</ul>
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
      </div>
    </article>
  );
}
