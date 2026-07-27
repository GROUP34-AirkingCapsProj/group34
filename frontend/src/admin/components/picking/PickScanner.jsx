import React, { useEffect, useMemo, useRef, useState } from 'react';
import CameraScanner from '../CameraScanner';

/**
 * PickScanner — shared, CONTROLLED scanner surface for the issuance picker and
 * the transfer dispatch panel. Both render the identical FIFO pick plan and the
 * identical server scan verdict, so they share this one component.
 *
 * This component is PRESENTATIONAL. It NEVER fetches. The parent owns the plan
 * data and is responsible for refetching after every ACCEPTED scan and after an
 * incident is filed (so the excluded batch drops out and the next step appears).
 *
 * ── Props ────────────────────────────────────────────────────────────────────
 *   plan   Array<{
 *            product_id:   number,
 *            product_name: string,
 *            needed:       number,             // qty still to pick for this line
 *            suggestions:  Array<{
 *              serial_number: string|null,     // non-null ⇒ serialized step
 *              serial_id:     number|null,
 *              batch_id:      number|null,
 *              zone_label:    string|null,     // where to walk to
 *              received_at:   string|null,     // 'Y-m-d H:i:s'
 *              quantity:      number,          // 1 for serialized, take-count for consumables
 *              // transfer pick-list rows additionally carry:
 *              // pieces_per_package?: number, package_unit?: string
 *            }>
 *          }>
 *          The exact shape emitted by FifoPickingService::suggest() and, for
 *          transfers, TransferService::pickList(). Issuance pick-plan omits the
 *          packaging fields; both are handled defensively.
 *
 *   onScan(barcode, confirmedZone) → Promise<serverResponse>
 *          Called on every Enter. `confirmedZone` is the zone string the picker
 *          confirmed for a CONSUMABLE step; it is null for serialized steps (the
 *          serial identifies the batch on its own). Must resolve to the server's
 *          scan response. The scan endpoint has TWO rejection shapes the parent
 *          must surface here:
 *            1. The FIFO/pick verdict — an HTTP 200 (accepted) OR an HTTP 422
 *               (rejected) whose body carries `scan_result`, the
 *               ScanResult::toArray() shape:
 *                 { status, accepted, overridable, message, product_id,
 *                   product_name, serial_id, serial_number, batch_id, zone_label,
 *                   expected_batch_id, expected_zone_label, expected_received_at }
 *            2. A LEGACY HTTP 200 rejection: `{ success:false, action:'rejected',
 *               reason }` with NO `scan_result` (barcode-not-found,
 *               serial-already-scanned, insufficient-stock). It has `reason` and
 *               no `message`/`status`; PickScanner renders `reason` as the banner
 *               text and treats it as a HARD block (no incident button, since
 *               `overridable` is absent/falsy).
 *          PickScanner reads the verdict from `response.scan_result`, falling
 *          back to the response body itself (so shape 2 is read as-is).
 *          IMPORTANT: the shared `apiRequest` helper DISCARDS the response body on
 *          a non-2xx status (it keeps only message/status/errors), so a parent
 *          that wires onScan straight through the raw helper would LOSE
 *          `scan_result` on a 422. The parent is responsible for catching the 422
 *          and resolving the full body so PickScanner can read `scan_result`.
 *          The parent should refetch the plan after an ACCEPTED verdict.
 *
 *   onIncident(context) → void
 *          Called when the picker clicks "File Incident Report" on an overridable
 *          (FIFO) verdict. PickScanner supplies the item-level context it knows:
 *            { product_id, product_name, batch_id, serial_id, expected_zone,
 *              actual_zone, quantity }
 *          `batch_id` is the EXPECTED (older) batch the picker could not find —
 *          filing against it is what excludes it from the next plan. The parent
 *          merges the document-level keys (source_type, source_id, location_id)
 *          before opening IncidentModal.
 *
 *   busy   boolean — disables the input/buttons while a scan is in flight.
 */

const STATUS_TONE = {
  ACCEPTED: 'green',
  FIFO_VIOLATION: 'amber',
  WRONG_PRODUCT: 'red',
  UNKNOWN_BARCODE: 'red',
  OVER_PICK: 'red',
};

const BANNER_STYLE = {
  green: { bg: '#ecfdf5', border: '#10b981', color: '#065f46' },
  amber: { bg: '#fffbeb', border: '#f59e0b', color: '#92400e' },
  red: { bg: '#fef2f2', border: '#ef4444', color: '#991b1b' },
};

/** A plan row is serialized when any of its suggestions carries a serial. */
function isSerializedStep(step) {
  if (!step || !Array.isArray(step.suggestions)) return false;
  return step.suggestions.some((s) => s && (s.serial_id != null || s.serial_number != null));
}

/**
 * Pull the verdict out of whatever the parent's onScan resolved to.
 * Order: the FIFO `scan_result` (verdict shape 1), then `data.scan_result`
 * (some parents nest under `data`), then the response body itself — which is how
 * the legacy `{success:false, action:'rejected', reason}` shape (2) is read.
 */
function extractVerdict(response) {
  if (!response) return null;
  return response.scan_result || response.data?.scan_result || response;
}

export default function PickScanner({ plan = [], onScan, onIncident, busy = false, camera = false }) {
  const [barcode, setBarcode] = useState('');
  const [verdict, setVerdict] = useState(null);
  const [flash, setFlash] = useState(false); // brief green pulse on ACCEPTED
  const [confirmedZone, setConfirmedZone] = useState('');
  const inputRef = useRef(null);
  const mountedRef = useRef(true);
  const flashTimer = useRef(null);

  // Guard against setState after unmount (the async scan + flash timeout can
  // resolve after the component is gone).
  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
      if (flashTimer.current) clearTimeout(flashTimer.current);
    };
  }, []);

  // The current step is the first line still needing units with something to pick.
  const steps = useMemo(
    () => (Array.isArray(plan) ? plan.filter((s) => Number(s?.needed) > 0) : []),
    [plan]
  );
  const currentStep = steps[0] || null;
  const remainingSteps = steps.slice(1);

  const serialized = isSerializedStep(currentStep);
  const suggestion = currentStep?.suggestions?.[0] || null;
  const suggestedZone = suggestion?.zone_label || '';
  // Quantity is fixed by the confirmed order. Consumables apply the current
  // FIFO take in one scan; serialized products remain one scan per serial.
  const scanQuantity = serialized ? 1 : Math.max(1, Number(suggestion?.quantity) || 1);

  // Default the zone control to the suggested zone whenever the step changes.
  useEffect(() => {
    setConfirmedZone(suggestedZone);
  }, [currentStep?.product_id, suggestedZone]);

  // Keep the input focused at all times — a picker with a scanner gun never
  // touches the mouse. Re-focus after the plan changes or a scan resolves.
  useEffect(() => {
    if (!busy) inputRef.current?.focus();
  }, [busy, plan, verdict]);

  const zoneList = useMemo(() => {
    const zones = (currentStep?.suggestions || [])
      .map((s) => s?.zone_label)
      .filter((z) => z != null && z !== '');
    return Array.from(new Set(zones));
  }, [currentStep]);

  // The confirmed zone the current step submits: null for a serialized step (the
  // serial identifies the batch on its own), otherwise the picker-confirmed zone.
  const currentConfirmedZone = serialized ? null : (confirmedZone || null);

  // The single scan-submit path. The manual field (Enter) and the optional camera
  // both call this, so a camera decode is FIFO-checked and its verdict handled
  // exactly like a manual scan.
  const submitScan = async (code, cz) => {
    const clean = String(code || '').trim();
    if (!clean || busy || !onScan) return;
    // Clear the input after EVERY attempt, regardless of outcome.
    setBarcode('');
    try {
      const response = await onScan(clean, cz, scanQuantity);
      if (!mountedRef.current) return;
      const v = extractVerdict(response);
      setVerdict(v);
      if (v?.status === 'ACCEPTED') {
        setFlash(true);
        if (flashTimer.current) clearTimeout(flashTimer.current);
        flashTimer.current = setTimeout(() => {
          if (mountedRef.current) setFlash(false);
        }, 600);
      }
    } catch (err) {
      if (!mountedRef.current) return;
      setVerdict({
        status: 'ERROR',
        accepted: false,
        overridable: false,
        message: err?.message || 'Scan failed. Try again.',
      });
    } finally {
      if (mountedRef.current) inputRef.current?.focus();
    }
  };

  const submit = (e) => {
    e?.preventDefault?.();
    submitScan(barcode, currentConfirmedZone);
  };

  const openIncident = () => {
    if (!verdict || !onIncident) return;
    onIncident({
      product_id: verdict.product_id ?? currentStep?.product_id ?? null,
      product_name: verdict.product_name ?? currentStep?.product_name ?? null,
      // The batch to exclude is the expected (older) one the picker couldn't find.
      batch_id: verdict.expected_batch_id ?? verdict.batch_id ?? suggestion?.batch_id ?? null,
      serial_id: verdict.serial_id ?? null,
      expected_zone: verdict.expected_zone_label ?? suggestedZone ?? null,
      actual_zone: verdict.zone_label ?? null,
      quantity: currentStep?.needed ?? 1,
    });
  };

  const tone = verdict ? (STATUS_TONE[verdict.status] || 'red') : null;
  const banner = tone ? BANNER_STYLE[tone] : null;

  if (!currentStep) {
    return (
      <div className="ak-card" style={{ textAlign: 'center', padding: '2.5rem 1rem' }}>
        <div style={{ fontSize: '1.4rem', fontWeight: 700, color: '#065f46' }}>All items picked</div>
        <p className="ak-muted" style={{ marginTop: '0.5rem' }}>
          Nothing left to scan on this document.
        </p>
      </div>
    );
  }

  return (
    <div className="ak-field" style={{ gap: '1rem' }}>
      {/* CURRENT STEP — the zone is the instruction, everything else is context. */}
      <div
        className="ak-card"
        aria-live="polite"
        style={{
          textAlign: 'center',
          padding: '1.5rem 1rem',
          transition: 'background 0.2s',
          background: flash ? '#ecfdf5' : undefined,
        }}
      >
        <div className="ak-muted" style={{ fontSize: '0.8rem', textTransform: 'uppercase', letterSpacing: '0.06em' }}>
          Go to zone
        </div>
        <div style={{ fontSize: '3.2rem', fontWeight: 800, lineHeight: 1.05, margin: '0.3rem 0' }} className="ak-mono">
          {suggestedZone || '—'}
        </div>
        <div style={{ fontSize: '1.25rem', fontWeight: 700 }}>
          {currentStep.product_name || `Product #${currentStep.product_id}`}
        </div>
        <div style={{ fontSize: '1rem', marginTop: '0.25rem' }}>
          Still needed: <strong>{currentStep.needed}</strong>
          {serialized ? (
            <span className="ak-badge ak-badge--violet" style={{ marginLeft: '0.5rem' }}>Serialized</span>
          ) : (
            suggestion?.package_unit && suggestion?.pieces_per_package ? (
              <span className="ak-muted" style={{ marginLeft: '0.5rem' }}>
                ({suggestion.pieces_per_package}/{suggestion.package_unit})
              </span>
            ) : null
          )}
        </div>
        {!serialized && suggestion && (
          <div
            style={{
              margin: '0.75rem auto 0',
              padding: '0.55rem 0.8rem',
              maxWidth: 420,
              borderRadius: 8,
              background: '#eff6ff',
              color: '#1e3a8a',
              fontWeight: 700,
            }}
          >
            Pick {scanQuantity} piece{scanQuantity === 1 ? '' : 's'} from zone{' '}
            <span className="ak-mono">{suggestedZone || 'Unassigned'}</span>
          </div>
        )}
        {suggestion?.received_at && (
          <div className="ak-muted" style={{ fontSize: '0.8rem', marginTop: '0.25rem' }}>
            Oldest batch received {suggestion.received_at}
          </div>
        )}
      </div>

      {!serialized && (currentStep?.suggestions?.length ?? 0) > 1 && (
        <div className="ak-card" style={{ padding: '0.9rem 1rem' }}>
          <div className="ak-label" style={{ marginBottom: '0.5rem' }}>FIFO route for this product</div>
          <ol style={{ margin: 0, paddingLeft: '1.3rem', display: 'grid', gap: '0.35rem' }}>
            {currentStep.suggestions.map((step, index) => (
              <li key={`${step.batch_id ?? 'batch'}-${index}`}>
                <strong>{step.quantity} piece{Number(step.quantity) === 1 ? '' : 's'}</strong>
                {' from zone '}
                <span className="ak-mono">{step.zone_label || 'Unassigned'}</span>
                {step.received_at && <span className="ak-muted"> · received {step.received_at}</span>}
                {index === 0 && <span className="ak-badge ak-badge--green" style={{ marginLeft: 8 }}>Pick first</span>}
              </li>
            ))}
          </ol>
        </div>
      )}

      {/* SCAN FORM */}
      <form onSubmit={submit} className="ak-field" style={{ gap: '0.6rem' }}>
        {!serialized && (
          <div className="ak-field">
            <label className="ak-label" htmlFor="pick-confirm-zone">Confirm zone</label>
            {zoneList.length > 1 ? (
              <select
                id="pick-confirm-zone"
                className="ak-select"
                value={confirmedZone}
                onChange={(e) => setConfirmedZone(e.target.value)}
                disabled={busy}
              >
                {zoneList.map((z) => (
                  <option key={z} value={z}>{z}</option>
                ))}
              </select>
            ) : (
              <input
                id="pick-confirm-zone"
                className="ak-input"
                value={confirmedZone}
                onChange={(e) => setConfirmedZone(e.target.value)}
                disabled={busy}
              />
            )}
          </div>
        )}

        <div className="ak-field">
          <label className="ak-label" htmlFor="pick-barcode">Scan barcode</label>
          <input
            id="pick-barcode"
            ref={inputRef}
            className="ak-input"
            autoFocus
            autoComplete="off"
            placeholder={serialized ? 'Scan the serial / barcode…' : 'Scan the item barcode…'}
            value={barcode}
            onChange={(e) => setBarcode(e.target.value)}
            disabled={busy}
            style={{ fontSize: '1.2rem' }}
          />
          <button
            type="submit"
            className="ak-btn ak-btn--primary"
            disabled={busy || !barcode.trim()}
            style={{ marginTop: '0.6rem' }}
          >
            {busy ? 'Picking…' : `Scan and pick ${scanQuantity} item${scanQuantity === 1 ? '' : 's'}`}
          </button>
        </div>
      </form>

      {/* OPTIONAL CAMERA — feeds the SAME FIFO-checked submitScan path as the
          manual field. Off by default so other consumers are unchanged. */}
      {camera && (
        <CameraScanner
          onDecode={(code) => submitScan(String(code).trim(), currentConfirmedZone)}
          mountId="issuance-pick-cam"
          storageKey="issuance-pick-cam"
          busy={busy}
          hint="Scan the item barcode"
        />
      )}

      {/* VERDICT BANNER */}
      {verdict && banner && (
        <div
          role="status"
          style={{
            background: banner.bg,
            border: `1px solid ${banner.border}`,
            color: banner.color,
            borderRadius: 10,
            padding: '0.9rem 1rem',
          }}
        >
          <div style={{ fontWeight: 700 }}>{verdict.message || verdict.reason || verdict.status}</div>
          {verdict.status === 'FIFO_VIOLATION' && (
            <div style={{ fontSize: '0.85rem', marginTop: '0.4rem' }}>
              Expected zone <strong>{verdict.expected_zone_label || '—'}</strong>
              {verdict.expected_received_at ? <> · received {verdict.expected_received_at}</> : null}
            </div>
          )}
          {/* Incident button is gated on the SERVER's overridable flag, never on a
              status string — the server decides what may be overridden. */}
          {verdict.overridable && (
            <button
              type="button"
              className="ak-btn ak-btn--primary"
              style={{ marginTop: '0.7rem' }}
              onClick={openIncident}
              disabled={busy}
            >
              File Incident Report
            </button>
          )}
        </div>
      )}

      {/* REMAINING STEPS */}
      {remainingSteps.length > 0 && (
        <div>
          <div className="ak-label" style={{ marginBottom: '0.4rem' }}>
            Remaining ({remainingSteps.length})
          </div>
          <ul style={{ listStyle: 'none', margin: 0, padding: 0, display: 'grid', gap: '0.3rem' }}>
            {remainingSteps.map((s) => {
              const z = s.suggestions?.[0]?.zone_label;
              return (
                <li
                  key={s.product_id}
                  style={{
                    display: 'flex',
                    justifyContent: 'space-between',
                    fontSize: '0.9rem',
                    padding: '0.35rem 0.5rem',
                    borderBottom: '1px solid var(--ak-line)',
                  }}
                >
                  <span>{s.product_name || `Product #${s.product_id}`}</span>
                  <span className="ak-muted">
                    {s.needed}× {z ? <span className="ak-mono">{z}</span> : '—'}
                  </span>
                </li>
              );
            })}
          </ul>
        </div>
      )}
    </div>
  );
}
