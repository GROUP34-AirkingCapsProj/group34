import React, { useState, useEffect, useCallback, useRef } from 'react';
import { transfersAPI } from '../../services/api';
import { toast } from '../../utils/toast';
import { confirmAction } from '../../utils/confirm';
import PickScanner from '../../components/picking/PickScanner';
import IncidentModal from '../../components/picking/IncidentModal';

const isLineSerialized = (detail) =>
  detail?.is_serialized || detail?.product?.is_serialized || detail?.product?.product_type === 'appliance';

/**
 * Dispatch panel — source-warehouse scanning for an Approved transfer.
 * Scanning now runs through the shared, FIFO-enforcing <PickScanner> (the same
 * engine + UI as issuance picking): it renders the FIFO pick plan, records each
 * unit leaving the source, and surfaces the server's verdict / incident path.
 * Per-line progress, the scan history (with remove), and the dispatch
 * confirmation are preserved around it.
 */
const DispatchPanel = ({ transfer, onDone }) => {
  const id = transfer?.transfer_id;
  const details = transfer?.details || [];

  const [plan, setPlan] = useState([]);
  const [sessionScans, setSessionScans] = useState({}); // { [did]: [{ scan_id, barcode, quantity }] }
  const [scannedTotals, setScannedTotals] = useState({}); // { [did]: total_scanned }
  const [planLoading, setPlanLoading] = useState(false);
  const [planLoaded, setPlanLoaded] = useState(false); // true only after loadPlan resolves ≥ once
  const [scanBusy, setScanBusy] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [incidentCtx, setIncidentCtx] = useState(null);

  const scannedTotalsRef = useRef({});
  useEffect(() => { scannedTotalsRef.current = scannedTotals; }, [scannedTotals]);

  const loadPlan = useCallback(async () => {
    setPlanLoading(true);
    try {
      const r = await transfersAPI.pickList(id);
      setPlan(Array.isArray(r?.data) ? r.data : []);
      setPlanLoaded(true);   // only now is an empty plan trustworthy as "all picked"
    } catch (err) {
      // Never silently blank the plan — that would flash a false "all picked".
      toast.error('Could not load the pick plan.', err?.message || 'Please try again.');
    } finally {
      setPlanLoading(false);
    }
  }, [id]);

  // Seed line totals from the transfer, then hydrate scan history + pick plan.
  useEffect(() => {
    const init = {};
    details.forEach((d) => { init[d.transfer_detail_id] = d.quantity_scanned ?? 0; });
    setScannedTotals(init);

    setPlanLoaded(false);   // don't trust the prior transfer's plan across a switch
    loadPlan();

    transfersAPI.scanStatus(id)
      .then((r) => {
        const data = r?.data ?? r;
        const history = data?.scan_history || [];
        const pidToDid = {};
        details.forEach((d) => { if (pidToDid[d.product_id] == null) pidToDid[d.product_id] = d.transfer_detail_id; });
        const grouped = {};
        history.forEach((h) => {
          const did = pidToDid[h.product_id];
          if (did == null) return;
          (grouped[did] = grouped[did] || []).push({ scan_id: h.id, barcode: h.barcode, quantity: h.quantity ?? 1 });
        });
        setSessionScans(grouped);
      })
      .catch(() => { /* best effort */ });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id]);

  const totalScanned = Object.values(scannedTotals).reduce((s, n) => s + (n || 0), 0);

  // ── PickScanner onScan ──────────────────────────────────────
  // Mirrors IssuanceScanPage: recover the 422 verdict body via err.data, and
  // normalize an accepted scan (HTTP 200, no scan_result) into a synthetic
  // ACCEPTED verdict so PickScanner advances. transfer_detail_id is omitted —
  // recordDispatchScan resolves the line from the scanned product/serial.
  const handleScan = useCallback(async (barcode, confirmedZone) => {
    let body;
    try {
      body = await transfersAPI.scan(id, { barcode, confirmed_zone: confirmedZone });
    } catch (e) {
      if (e?.data) body = e.data;   // recover the HTTP 422 body (carries scan_result)
      else throw e;
    }
    // Accept: HTTP 200 success with NO scan_result of its own.
    if (body?.success && !body?.scan_result) {
      const data = body.data ?? body;
      const did = data.transfer_detail_id;
      if (did != null) {
        setSessionScans((prev) => ({
          ...prev,
          [did]: [...(prev[did] || []), { scan_id: data.scan_id, barcode, quantity: data.quantity ?? 1 }],
        }));
        const nextTotals = { ...scannedTotalsRef.current, [did]: data.total_scanned };
        scannedTotalsRef.current = nextTotals;
        setScannedTotals(nextTotals);
      }
      // Refetch so the picked unit drops out and the next step appears.
      await loadPlan();
      return {
        scan_result: {
          status: 'ACCEPTED',
          accepted: true,
          overridable: false,
          message: `${data.product_name || 'Item'} scanned.`,
        },
      };
    }
    return body;   // rejected: 422 {scan_result} or legacy 422 {success:false, message}
  }, [id, loadPlan]);

  const onScan = useCallback(async (barcode, confirmedZone) => {
    setScanBusy(true);
    try {
      return await handleScan(barcode, confirmedZone);
    } finally {
      setScanBusy(false);
    }
  }, [handleScan]);

  // The incident is raised WHERE THE STOCK IS — the source (from) location.
  const handleIncident = useCallback((context) => {
    setIncidentCtx({
      ...context,
      source_type: 'transfer',
      source_id: id,
      location_id: transfer?.from_location_id ?? null,
    });
  }, [id, transfer?.from_location_id]);

  const handleIncidentFiled = useCallback(async () => {
    setIncidentCtx(null);
    await loadPlan();   // excluded batch drops out, next step appears
  }, [loadPlan]);

  const handleRemoveScan = async (did, scan) => {
    try {
      await transfersAPI.removeScan(id, scan.scan_id);
      setSessionScans((prev) => ({ ...prev, [did]: (prev[did] || []).filter((s) => s.scan_id !== scan.scan_id) }));
      setScannedTotals((prev) => ({ ...prev, [did]: Math.max(0, (prev[did] || 0) - (scan.quantity || 1)) }));
      await loadPlan();   // restored need reappears in the plan
    } catch (err) {
      toast.error('Could not remove scan', err?.message || 'Failed to remove scan.');
    }
  };

  const handleConfirm = async () => {
    if (totalScanned < 1) {
      toast.error("Can't dispatch yet", 'Scan at least one unit before confirming dispatch.');
      return;
    }
    if (!(await confirmAction({
      title: `Confirm dispatch of ${transfer?.transfer_number || 'this transfer'}?`,
      text: 'Scanned units will be marked In Transit.',
      confirmText: 'Confirm dispatch',
      tone: 'primary',
    }))) return;
    setSubmitting(true);
    try {
      await transfersAPI.confirmDispatch(id);
      toast.success('Dispatch confirmed', `${transfer.transfer_number} is now In Transit.`);
      onDone?.();
    } catch (err) {
      toast.error("Couldn't confirm dispatch", err?.message || 'Failed to confirm dispatch.');
    } finally {
      setSubmitting(false);
    }
  };

  const scannedFlat = [];
  details.forEach((d) => {
    const name = d.product?.product_name || `Product #${d.product_id}`;
    (sessionScans[d.transfer_detail_id] || []).forEach((s) => scannedFlat.push({ ...s, did: d.transfer_detail_id, productName: name }));
  });

  return (
    <div className="tf-drawer">
      <div className="bsr-workflow">
        <div className="bsr-left">
          <div className="bsr-card">
            <div className="bsr-card-head">
              <span className="bsr-card-title">ITEMS TO DISPATCH</span>
              <span className="bsr-scanned-count">{totalScanned} scanned</span>
            </div>
            <div className="bsr-items-list">
              {details.length === 0 ? (
                <p className="bsr-items-empty">No line items on this transfer.</p>
              ) : details.map((detail) => {
                const did = detail.transfer_detail_id;
                const target = detail.quantity_transferred ?? detail.quantity_requested ?? 0;
                const scanned = scannedTotals[did] ?? 0;
                const remaining = Math.max(0, target - scanned);
                const done = remaining === 0 && target > 0;
                const serialized = isLineSerialized(detail);
                const name = detail.product?.product_name || `Product #${detail.product_id}`;
                const pct = target > 0 ? Math.min(100, (scanned / target) * 100) : 0;
                return (
                  <div
                    key={did}
                    className={`bsr-item-row${done ? ' bsr-item-row--done' : ''}`}
                  >
                    <div className="bsr-item-info">
                      <span className="bsr-item-name">{name}</span>
                      <div className="bsr-item-badges">
                        {serialized ? <span className="bsr-serialized-badge">SERIAL</span> : <span className="bsr-bulk-badge">BULK</span>}
                      </div>
                    </div>
                    <div className="bsr-item-progress-row">
                      <span className="bsr-item-progress-label">Progress:</span>
                      <span className={`bsr-item-qty ${done ? 'bsr-item-qty--done' : 'bsr-item-qty--zero'}`}>{scanned} / {target} units</span>
                    </div>
                    <div className="bsr-progress-bar-wrap">
                      <div className={`bsr-progress-bar-fill ${done ? 'bsr-progress-bar-fill--done' : ''}`} style={{ width: `${pct}%` }} />
                    </div>
                  </div>
                );
              })}
            </div>
          </div>
        </div>

        <div className="bsr-right">
          <div className="bsr-card">
            <div className="bsr-card-head">
              <span className="bsr-card-title">SCAN BARCODE (FIFO ENFORCED)</span>
            </div>
            {!planLoaded && planLoading ? (
              <div className="ak-card" style={{ textAlign: 'center', padding: '2rem' }}>Loading pick plan…</div>
            ) : (
              <PickScanner
                plan={plan}
                onScan={onScan}
                onIncident={handleIncident}
                busy={scanBusy || planLoading}
                camera
              />
            )}
          </div>

          <div className="bsr-card">
            <div className="bsr-card-head">
              <span className="bsr-card-title">SCANNED ITEMS</span>
              <span className="bsr-scanned-count">{scannedFlat.length} {scannedFlat.length === 1 ? 'item' : 'items'}</span>
            </div>
            {scannedFlat.length === 0 ? (
              <div className="bsr-scanned-empty"><p>No items scanned yet</p></div>
            ) : (
              <div className="bsr-scanned-list">
                {scannedFlat.map((s) => (
                  <div key={s.scan_id ?? `${s.did}-${s.barcode}`} className="bsr-scanned-item">
                    <div className="bsr-scanned-item-info">
                      <span className="bsr-scanned-item-name">{s.productName}</span>
                      <span className="bsr-scanned-item-serial">{s.barcode}{s.quantity > 1 ? ` ×${s.quantity}` : ''}</span>
                    </div>
                    {s.scan_id != null && (
                      <button type="button" onClick={() => handleRemoveScan(s.did, s)}
                        style={{ background: 'none', border: 'none', cursor: 'pointer', color: 'var(--text-danger)', fontSize: 11, padding: '2px 6px' }}
                        title="Remove this scan">Remove</button>
                    )}
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>
      </div>

      <div className="tf-actions-row">
        <button type="button" className="ak-btn ak-btn--primary" onClick={handleConfirm} disabled={submitting || totalScanned < 1}>
          {submitting ? 'Confirming…' : 'Confirm Dispatch'}
        </button>
      </div>

      <IncidentModal
        context={incidentCtx}
        onFiled={handleIncidentFiled}
        onClose={() => setIncidentCtx(null)}
      />
    </div>
  );
};

export default DispatchPanel;
