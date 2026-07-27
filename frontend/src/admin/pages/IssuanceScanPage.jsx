import React, { useCallback, useEffect, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import { useAuth, useUserLocation } from '../context/AuthContext';
import { getRoleKey, ROLES } from '../utils/roles';
import OrderBuilder from '../components/issuance/OrderBuilder';
import QuotePanel from '../components/issuance/QuotePanel';
import PickScanner from '../components/picking/PickScanner';
import IncidentModal from '../components/picking/IncidentModal';
import {
  locationsAPI,
  issuanceScanAPI,
  issuanceOrderAPI,
  pickingAPI,
  statusAPI,
} from '../services/api';
import { toast } from '../utils/toast';
import { confirmAction } from '../utils/confirm';
import '../styles/dashboard_air.css';
import '../styles/issuance_scan.css';

// Wizard steps. State DERIVES from the issuance status (see `currentStep`); these
// constants are only for the stepper UI and the local Draft sub-step toggle.
const STEP_SETUP = 1; // order setup (customer, type, discount, location)
const STEP_ORDER = 2; // build the line list
const STEP_QUOTE = 3;
const STEP_PICK = 4;

const fmt = (val) => {
  const n = parseFloat(val);
  if (val === null || val === undefined || Number.isNaN(n)) return '—';
  return '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
};

const fmtDate = (d) => {
  if (!d) return '—';
  return new Date(d).toLocaleDateString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric' });
};

export default function IssuanceScanPage() {
  const { user, refreshUser, can } = useAuth();
  const { locationId: enforcedLocationId, locationName, locationType, isStrictLocation, allowedLocations } = useUserLocation();

  const roleKey = getRoleKey(user || {});
  const isAdmin = roleKey === ROLES.ADMIN;
  const canIssue = isAdmin || (typeof can === 'function' && can('inventory_issuance', 'create'));

  const [searchParams, setSearchParams] = useSearchParams();

  const [locations, setLocations] = useState([]);
  const [statusMap, setStatusMap] = useState({});   // status_id → status_name (issuance)
  // The active issuance id lives in the URL (?issuance=<id>) so a hard refresh can
  // rehydrate. `hydrating` guards the first render while the mount-fetch resolves,
  // preventing an OrderBuilder setup-form flash on a mid-pick refresh.
  const [hydrating, setHydrating] = useState(!!searchParams.get('issuance'));

  const [session, setSession] = useState(null);      // init response (dr_number, customer, type)
  const [quote, setQuote] = useState(null);          // quote `data` payload — source of truth for status
  const [draftStep, setDraftStep] = useState(STEP_ORDER); // local nav WHILE Draft only

  const [plan, setPlan] = useState([]);
  const [planLoading, setPlanLoading] = useState(false);
  const [planLoaded, setPlanLoaded] = useState(false); // true only after loadPlan resolves ≥ once
  const [scanBusy, setScanBusy] = useState(false);
  const [incidentCtx, setIncidentCtx] = useState(null);

  // Set when confirmQuote succeeds. Lets the wizard advance to Pick even if the
  // status-id→name map failed to load — a failed map load must never strand a
  // confirmed order off the Pick step.
  const [confirmedPick, setConfirmedPick] = useState(false);

  const [receipt, setReceipt] = useState(null);
  const [completing, setCompleting] = useState(false);

  const issuanceId = session?.issuance_id ?? quote?.issuance_id ?? null;

  // Status name derived from the quote's status_id — NEVER local step state, so a
  // refresh mid-pick lands the user back on the right step.
  const statusName = quote?.status_id != null ? (statusMap[quote.status_id] ?? null) : null;
  const isCompleted = statusName === 'Completed';
  // Voided/cancelled (or any non-active status) is terminal — not editable.
  const isTerminal = statusName != null && !['Draft', 'Pending', 'Completed'].includes(statusName);
  // Pending drives Pick; confirmedPick is the fallback when the name map is missing.
  const isPending = statusName === 'Pending' || (confirmedPick && !isCompleted && !isTerminal);

  // Effective step: Pending forces Pick; with no draft yet the user is on Order
  // Setup; once a Draft exists the local sub-step toggle (Order/Quote) applies.
  const currentStep = isPending ? STEP_PICK : (!issuanceId ? STEP_SETUP : draftStep);

  // Mirror the live "an abandoned pick should revert to Draft" condition into a ref
  // so unmount/beforeunload cleanups (which capture stale state) read the truth.
  const revertableRef = useRef(false);
  const revertFiredRef = useRef(false);
  useEffect(() => {
    revertableRef.current = Boolean(issuanceId) && isPending && !isCompleted && !isTerminal;
  }, [issuanceId, isPending, isCompleted, isTerminal]);

  useEffect(() => { refreshUser(); }, [refreshUser]);

  useEffect(() => {
    locationsAPI.getAll({ per_page: 200 }).then((res) => {
      setLocations(Array.isArray(res?.data) ? res.data : []);
    }).catch(() => {});
  }, []);

  // Resolve the issuance status_id → name map once.
  useEffect(() => {
    statusAPI.getAll({ category: 'issuance' }).then((res) => {
      const list = Array.isArray(res?.data) ? res.data : (res?.data?.data ?? []);
      const map = {};
      (Array.isArray(list) ? list : []).forEach((s) => {
        if (s?.status_id != null) map[s.status_id] = s.status_name;
      });
      setStatusMap(map);
    }).catch(() => {
      // A missing map would otherwise silently lock the wizard; surface it and
      // rely on the confirmedPick fallback to keep confirmed orders on Pick.
      toast.error('Could not load issuance statuses. Step tracking may be limited.');
    });
  }, []);

  const loadQuote = useCallback(async (id) => {
    if (!id) return;
    try {
      const res = await issuanceOrderAPI.quote(id);
      setQuote(res?.data ?? res);
    } catch (err) {
      toast.error(err.message || 'Failed to load the quote.');
    }
  }, []);

  // ── Rehydrate from the URL on mount ─────────────────────────
  // If ?issuance=<id> is present, load its quote; the status-derived step logic
  // then places the user on the right step (Pending → Pick, Draft → Order/Quote,
  // Completed → receipt). A stale/invalid id falls back to the setup form.
  useEffect(() => {
    const id = searchParams.get('issuance');
    let cancelled = false;
    if (id) {
      (async () => {
        try {
          const res = await issuanceOrderAPI.quote(id);
          if (!cancelled) setQuote(res?.data ?? res);
        } catch (err) {
          if (cancelled) return;
          // Only discard the resumable session on a genuine not-found/forbidden.
          // A transient network error keeps the param so a reload can retry.
          if (err?.status === 404 || err?.status === 403) {
            toast.error('Could not resume the previous order. Starting a new one.');
            setSearchParams((p) => { p.delete('issuance'); return p; }, { replace: true });
          } else {
            toast.error(err?.message || 'Could not reach the server to resume the order. Reload to retry.');
          }
        } finally {
          if (!cancelled) setHydrating(false);
        }
      })();
    } else {
      // No ?issuance= param → start fresh on the Order Setup step. We do NOT
      // silently resume a leftover draft here: that jumped the user off Setup
      // into an old order they never asked for. Resuming is explicit only, via
      // the Transactions "Continue" action (which navigates with ?issuance=).
      setHydrating(false);
    }
    return () => { cancelled = true; };
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Revert an abandoned picking-phase order on hard exits: a tab close/refresh fires
  // a sendBeacon (best-effort), and leaving the page (unmount) fires the endpoint.
  useEffect(() => {
    const onBeforeUnload = () => {
      if (revertableRef.current && !revertFiredRef.current && issuanceId) {
        revertFiredRef.current = true;
        try {
          navigator.sendBeacon(issuanceScanAPI.revertToDraftUrl(issuanceId));
        } catch { /* best-effort */ }
      }
    };
    window.addEventListener('beforeunload', onBeforeUnload);
    return () => {
      window.removeEventListener('beforeunload', onBeforeUnload);
      // Navigating away inside the app: revert the abandoned pick (fire-and-forget).
      if (revertableRef.current && !revertFiredRef.current && issuanceId) {
        revertFiredRef.current = true;
        issuanceScanAPI.revertToDraft(issuanceId).catch(() => {});
      }
    };
  }, [issuanceId]);

  const loadPlan = useCallback(async (id = issuanceId) => {
    if (!id) return;
    setPlanLoading(true);
    try {
      const res = await pickingAPI.issuancePlan(id);
      setPlan(Array.isArray(res?.data) ? res.data : []);
      setPlanLoaded(true);   // only now is an empty plan trustworthy as "all picked"
    } catch (err) {
      toast.error(err.message || 'Failed to load the pick plan.');
    } finally {
      setPlanLoading(false);
    }
  }, [issuanceId]);

  // Load the pick plan whenever the order enters the Pending (Pick) phase.
  useEffect(() => {
    if (isPending && issuanceId) loadPlan(issuanceId);
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isPending, issuanceId]);

  // ── Step 1 → creates the draft ──────────────────────────────
  const handleSessionCreated = useCallback(async (res) => {
    revertFiredRef.current = false;
    setSession(res);
    setDraftStep(STEP_ORDER);
    if (res?.issuance_id) {
      setSearchParams((p) => { p.set('issuance', String(res.issuance_id)); return p; }, { replace: true });
      await loadQuote(res.issuance_id);
    }
  }, [loadQuote, setSearchParams]);

  // ── Step 3: PickScanner onScan ──────────────────────────────
  // Recovers the scan verdict from BOTH rejection shapes and normalizes an
  // accepted apply to an ACCEPTED scan_result (the scan endpoint returns
  // {success,action:'applied'} on accept, with no scan_result of its own).
  const handleScan = useCallback(async (barcode, confirmedZone) => {
    const payload = {
      issuance_id: issuanceId,
      barcode,
      confirmed_zone: confirmedZone ?? null,
      location_id: quote?.location_id ?? null,
    };
    let body;
    try {
      body = await issuanceScanAPI.scanItem(payload);
    } catch (e) {
      if (e?.data) body = e.data;   // recover the HTTP 422 body (carries scan_result)
      else throw e;
    }
    if (body?.success && body?.action === 'applied') {
      // Refetch so the picked unit drops out and the next step appears.
      await loadPlan(issuanceId);
      return {
        scan_result: {
          status: 'ACCEPTED',
          accepted: true,
          overridable: false,
          message: `${body.product_name ?? 'Item'} picked.`,
        },
      };
    }
    return body;   // rejected: 422 {scan_result} or legacy 200 {success:false, reason}
  }, [issuanceId, quote?.location_id, loadPlan]);

  const handleIncident = useCallback((context) => {
    setIncidentCtx({
      ...context,
      source_type: 'issuance',
      source_id: issuanceId,
      location_id: quote?.location_id ?? null,
    });
  }, [issuanceId, quote?.location_id]);

  const handleIncidentFiled = useCallback(async () => {
    setIncidentCtx(null);
    await loadPlan(issuanceId);
  }, [issuanceId, loadPlan]);

  const handleComplete = useCallback(async () => {
    if (!issuanceId) return;
    if (!(await confirmAction({
      title: 'Complete this issuance?',
      text: 'This finalizes the issuance, creates the delivery receipt, and activates warranties. This cannot be undone.',
      confirmText: 'Complete',
      tone: 'primary',
    }))) return;
    setCompleting(true);
    try {
      const res = await issuanceScanAPI.complete(issuanceId);
      setReceipt(res?.issuance ?? res?.data ?? res);
      setConfirmedPick(false);   // completed — no longer a pending pick
      await loadQuote(issuanceId);
    } catch (err) {
      toast.error(err.message || 'Failed to complete the issuance.');
    } finally {
      setCompleting(false);
    }
  }, [issuanceId, loadQuote]);

  const handleConfirmed = useCallback((data) => {
    setQuote(data);
    setConfirmedPick(true);   // advance to Pick regardless of the status-name map
  }, []);

  const resetAll = useCallback(() => {
    setSession(null);
    setQuote(null);
    setDraftStep(STEP_ORDER);
    setPlan([]);
    setPlanLoaded(false);
    setConfirmedPick(false);
    setIncidentCtx(null);
    setReceipt(null);
    setSearchParams((p) => { p.delete('issuance'); return p; }, { replace: true });
  }, [setSearchParams]);

  // Best-effort revert of an abandoned picking-phase order to Draft. Safe to call
  // when nothing is revertable (no-op) and safe to call twice (server is idempotent).
  const revertIfAbandoned = useCallback(async () => {
    if (!revertableRef.current || revertFiredRef.current || !issuanceId) return;
    revertFiredRef.current = true;
    try {
      await issuanceScanAPI.revertToDraft(issuanceId);
    } catch {
      /* best-effort — the order stays resumable/cancellable either way */
    }
  }, [issuanceId]);

  const scanBusyWrapped = scanBusy || planLoading;

  // Wrap onScan to drive the busy flag while a scan is in flight.
  const onScan = useCallback(async (barcode, confirmedZone) => {
    setScanBusy(true);
    try {
      return await handleScan(barcode, confirmedZone);
    } finally {
      setScanBusy(false);
    }
  }, [handleScan]);

  // Stepper reachability: a door the UI must not offer when the server would slam it.
  const canGoOrder = !isPending && !isCompleted && !isTerminal;
  const canGoQuote = !isPending && !isCompleted && !isTerminal && (quote?.lines?.length ?? 0) > 0;

  const goStep = (s) => {
    if (s === STEP_ORDER && canGoOrder) setDraftStep(STEP_ORDER);
    else if (s === STEP_QUOTE && canGoQuote) setDraftStep(STEP_QUOTE);
    // Order Setup is entered by starting fresh (no draft); Pick is status-driven —
    // neither is a manual navigation target once a draft exists.
  };

  const stepLabels = ['Order Setup', 'Order', 'Quote', 'Pick'];
  // Setup is only "reachable" before a draft exists; after that it's a completed step.
  const stepReachable = [!issuanceId, canGoOrder, canGoQuote, false];

  // Only trust an empty plan as "all picked" once loadPlan has actually resolved.
  const allPicked = planLoaded && plan.every((s) => Number(s?.needed) <= 0);

  return (
    <AdminLayout>
      <div className="ak-page isp-page">
        <header className="ak-header">
          <div className="ak-header-row">
            <div>
              <p className="ak-eyebrow">Inventory · Issuance</p>
              <h1 className="ak-title">Issuance Order</h1>
              <p className="ak-sub">Build the order, confirm the quote, then pick with FIFO enforcement.</p>
            </div>
            {issuanceId && (
              <button type="button" className="isp-btn-secondary" onClick={async () => { await revertIfAbandoned(); resetAll(); }}>New Order</button>
            )}
          </div>
        </header>

        {/* Stepper */}
        <div className="isp-steps">
          {stepLabels.map((label, idx) => {
            const s = idx + 1;
            const active = currentStep === s;
            const done = currentStep > s;
            const reachable = stepReachable[idx];
            return (
              <div key={s} className={`isp-step ${active ? 'isp-step-active' : ''} ${done ? 'isp-step-done' : ''}`}>
                <button
                  type="button"
                  className="isp-step-dot"
                  onClick={() => reachable && goStep(s)}
                  disabled={!reachable}
                  style={{ cursor: reachable ? 'pointer' : 'default', border: 'none', background: 'inherit' }}
                  aria-current={active ? 'step' : undefined}
                >
                  {done ? (
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" width="14" height="14">
                      <polyline points="20 6 9 17 4 12" />
                    </svg>
                  ) : s}
                </button>
                <span className="isp-step-label">{label}</span>
                {idx < stepLabels.length - 1 && <div className={`isp-step-line ${done ? 'isp-step-line-done' : ''}`} />}
              </div>
            );
          })}
        </div>

        {/* Rehydrating from the URL — hold the wizard until the mount-fetch resolves. */}
        {hydrating ? (
          <div className="isp-card" style={{ textAlign: 'center', padding: '2.5rem' }}>Resuming order…</div>
        ) : isCompleted ? (
          /* Completed → receipt summary (survives refresh even without an in-memory receipt) */
          <div className="isp-card">
            <h2 className="isp-card-title">Issuance Completed</h2>
            <div className="isp-confirm-summary">
              <div className="isp-confirm-row"><span>DR Number</span><strong>{receipt?.dr_number ?? quote?.dr_number ?? '—'}</strong></div>
              {receipt?.issuance_date && (
                <div className="isp-confirm-row"><span>Date</span><span>{fmtDate(receipt.issuance_date)}</span></div>
              )}
              <div className="isp-confirm-row isp-confirm-total"><span>Total Amount</span><strong>{fmt(receipt?.total_amount ?? quote?.total_amount)}</strong></div>
            </div>
            <div className="isp-dr-actions no-print" style={{ marginTop: 16 }}>
              <button
                type="button"
                className="isp-btn-secondary"
                onClick={() => window.open(issuanceScanAPI.drCsvUrl(receipt?.issuance_id ?? issuanceId), '_blank')}
              >
                Download DR (CSV)
              </button>
              <button type="button" className="isp-btn-ghost" onClick={resetAll}>New Order</button>
            </div>
          </div>
        ) : isTerminal ? (
          /* Voided/cancelled → terminal, read-only. Never an editable order form. */
          <div className="isp-card">
            <h2 className="isp-card-title">Order {statusName}</h2>
            <p className="ak-muted" style={{ marginTop: 4 }}>
              This order is {String(statusName).toLowerCase()} and can no longer be edited.
            </p>
            <div className="isp-confirm-summary" style={{ marginTop: 12 }}>
              <div className="isp-confirm-row"><span>DR Number</span><strong>{quote?.dr_number ?? '—'}</strong></div>
              <div className="isp-confirm-row isp-confirm-total"><span>Total Amount</span><strong>{fmt(quote?.total_amount)}</strong></div>
            </div>
            <div className="isp-dr-actions no-print" style={{ marginTop: 16 }}>
              <button type="button" className="isp-btn-ghost" onClick={resetAll}>New Order</button>
            </div>
          </div>
        ) : (
          <>
            {/* STEP 1–2 — Order Setup (no draft yet) then Order line-building.
                OrderBuilder renders the setup form when there is no draft and the
                line list once one exists, so it backs both stepper steps. */}
            {(currentStep === STEP_SETUP || currentStep === STEP_ORDER) && (
              <OrderBuilder
                issuanceId={issuanceId}
                quote={quote}
                session={session}
                canIssue={canIssue}
                isStrictLocation={isStrictLocation}
                enforcedLocationId={enforcedLocationId}
                locationName={locationName}
                locationType={locationType}
                allowedLocations={allowedLocations}
                locations={locations}
                onSessionCreated={handleSessionCreated}
                onQuoteUpdate={setQuote}
                onProceed={() => setDraftStep(STEP_QUOTE)}
              />
            )}

            {/* STEP 2 — Quote */}
            {currentStep === STEP_QUOTE && quote && (
              <QuotePanel
                issuanceId={issuanceId}
                quote={quote}
                canIssue={canIssue}
                onQuoteUpdate={setQuote}
                onConfirmed={handleConfirmed}
                onBack={() => setDraftStep(STEP_ORDER)}
              />
            )}

            {/* STEP 3 — Pick */}
            {currentStep === STEP_PICK && (
              <div className="ak-field" style={{ gap: '1rem' }}>
                <div className="isp-session-header">
                  <div className="isp-session-info">
                    <span className="isp-session-dr">DR: {session?.dr_number ?? quote?.dr_number ?? '—'}</span>
                    <span className="isp-session-sep">|</span>
                    <span className="ak-badge ak-badge--violet">Pending — picking</span>
                  </div>
                </div>

                {planLoading && plan.length === 0 ? (
                  <div className="ak-card" style={{ textAlign: 'center', padding: '2rem' }}>Loading pick plan…</div>
                ) : (
                  <PickScanner
                    plan={plan}
                    onScan={onScan}
                    onIncident={handleIncident}
                    busy={scanBusyWrapped}
                    camera
                  />
                )}

                {allPicked && !planLoading && (
                  <div className="isp-form-actions" style={{ justifyContent: 'flex-end' }}>
                    <button
                      type="button"
                      className="isp-btn-complete"
                      onClick={handleComplete}
                      disabled={completing || !canIssue}
                    >
                      {completing ? 'Completing…' : 'Complete Issuance'}
                    </button>
                  </div>
                )}
              </div>
            )}
          </>
        )}

        <IncidentModal
          context={incidentCtx}
          onFiled={handleIncidentFiled}
          onClose={() => setIncidentCtx(null)}
        />
      </div>
    </AdminLayout>
  );
}
