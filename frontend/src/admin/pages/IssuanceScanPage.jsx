import React, { useCallback, useEffect, useMemo, useState } from 'react';
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
const STEP_ORDER = 1;
const STEP_QUOTE = 2;
const STEP_PICK = 3;

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

  const [locations, setLocations] = useState([]);
  const [statusMap, setStatusMap] = useState({});   // status_id → status_name (issuance)

  const [session, setSession] = useState(null);      // init response (dr_number, customer, type)
  const [quote, setQuote] = useState(null);          // quote `data` payload — source of truth for status
  const [draftStep, setDraftStep] = useState(STEP_ORDER); // local nav WHILE Draft only

  const [plan, setPlan] = useState([]);
  const [planLoading, setPlanLoading] = useState(false);
  const [scanBusy, setScanBusy] = useState(false);
  const [incidentCtx, setIncidentCtx] = useState(null);

  const [receipt, setReceipt] = useState(null);
  const [completing, setCompleting] = useState(false);

  const issuanceId = session?.issuance_id ?? quote?.issuance_id ?? null;

  // Status name derived from the quote's status_id — NEVER local step state, so a
  // refresh mid-pick lands the user back on the right step.
  const statusName = quote?.status_id != null ? (statusMap[quote.status_id] ?? null) : null;
  const isPending = statusName === 'Pending';
  const isCompleted = statusName === 'Completed';

  // Effective step: Pending forces Pick; Draft uses the local sub-step toggle.
  const currentStep = isPending ? STEP_PICK : draftStep;

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
    }).catch(() => {});
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

  const loadPlan = useCallback(async (id = issuanceId) => {
    if (!id) return;
    setPlanLoading(true);
    try {
      const res = await pickingAPI.issuancePlan(id);
      setPlan(Array.isArray(res?.data) ? res.data : []);
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
    setSession(res);
    setDraftStep(STEP_ORDER);
    if (res?.issuance_id) await loadQuote(res.issuance_id);
  }, [loadQuote]);

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
      await loadQuote(issuanceId);
    } catch (err) {
      toast.error(err.message || 'Failed to complete the issuance.');
    } finally {
      setCompleting(false);
    }
  }, [issuanceId, loadQuote]);

  const resetAll = useCallback(() => {
    setSession(null);
    setQuote(null);
    setDraftStep(STEP_ORDER);
    setPlan([]);
    setIncidentCtx(null);
    setReceipt(null);
  }, []);

  const scanBusyWrapped = useMemo(() => scanBusy || planLoading, [scanBusy, planLoading]);

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
  const canGoOrder = !isPending && !isCompleted;
  const canGoQuote = !isPending && !isCompleted && (quote?.lines?.length ?? 0) > 0;

  const goStep = (s) => {
    if (s === STEP_ORDER && canGoOrder) setDraftStep(STEP_ORDER);
    else if (s === STEP_QUOTE && canGoQuote) setDraftStep(STEP_QUOTE);
    // Pick is status-driven — never a manual navigation target.
  };

  const stepLabels = ['Order', 'Quote', 'Pick'];
  const stepReachable = [canGoOrder, canGoQuote, false];

  const allPicked = plan.filter((s) => Number(s?.needed) > 0).length === 0;

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
              <button type="button" className="isp-btn-secondary" onClick={resetAll}>New Order</button>
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
                {idx < 2 && <div className={`isp-step-line ${done ? 'isp-step-line-done' : ''}`} />}
              </div>
            );
          })}
        </div>

        {/* Completed → receipt summary */}
        {isCompleted && receipt ? (
          <div className="isp-card">
            <h2 className="isp-card-title">Issuance Completed</h2>
            <div className="isp-confirm-summary">
              <div className="isp-confirm-row"><span>DR Number</span><strong>{receipt.dr_number ?? quote?.dr_number}</strong></div>
              <div className="isp-confirm-row"><span>Date</span><span>{fmtDate(receipt.issuance_date)}</span></div>
              <div className="isp-confirm-row isp-confirm-total"><span>Total Amount</span><strong>{fmt(receipt.total_amount ?? quote?.total_amount)}</strong></div>
            </div>
            <div className="isp-dr-actions no-print" style={{ marginTop: 16 }}>
              <button
                type="button"
                className="isp-btn-secondary"
                onClick={() => window.open(issuanceScanAPI.drCsvUrl(receipt.issuance_id ?? issuanceId), '_blank')}
              >
                Download DR (CSV)
              </button>
              <button type="button" className="isp-btn-ghost" onClick={resetAll}>New Order</button>
            </div>
          </div>
        ) : (
          <>
            {/* STEP 1 — Order */}
            {currentStep === STEP_ORDER && (
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
                onConfirmed={setQuote}
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
