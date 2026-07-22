import React, { useState, useEffect, useCallback, useRef } from 'react';
import { transfersAPI } from '../../services/api';
import { toast } from '../../utils/toast';
import { confirmAction } from '../../utils/confirm';
import CameraScanner from '../../components/CameraScanner';

const isLineSerialized = (detail) =>
  detail?.is_serialized || detail?.product?.is_serialized || detail?.product?.product_type === 'appliance';

const fmtWhen = (v) => {
  if (!v) return '';
  const d = new Date(v);
  return Number.isNaN(d.getTime()) ? String(v).slice(0, 10) : d.toLocaleDateString();
};

/**
 * Dispatch panel — source-warehouse scanning for an Approved transfer.
 * Shows FIFO pick suggestions per line, records each unit leaving the source
 * via barcode/manual entry, then confirms dispatch (-> In Transit).
 */
const DispatchPanel = ({ transfer, onDone }) => {
  const id = transfer?.transfer_id;
  const details = transfer?.details || [];

  const [pickList, setPickList] = useState([]);
  const [sessionScans, setSessionScans] = useState({}); // { [did]: [{ scan_id, barcode, quantity }] }
  const [scannedTotals, setScannedTotals] = useState({}); // { [did]: total_scanned }
  const [activeLine, setActiveLine] = useState(null);
  const [scanInput, setScanInput] = useState('');
  const [scanning, setScanning] = useState(false);
  const [submitting, setSubmitting] = useState(false);

  const scannedTotalsRef = useRef({});
  useEffect(() => { scannedTotalsRef.current = scannedTotals; }, [scannedTotals]);

  // Seed line totals from the transfer, then hydrate scan history + pick list.
  useEffect(() => {
    const init = {};
    details.forEach((d) => { init[d.transfer_detail_id] = d.quantity_scanned ?? 0; });
    setScannedTotals(init);

    transfersAPI.pickList(id)
      .then((r) => setPickList(Array.isArray(r?.data) ? r.data : []))
      .catch(() => setPickList([]));

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
  const activeDetail = details.find((d) => d.transfer_detail_id === activeLine) || null;

  const handleLineScan = useCallback(async (code) => {
    const barcode = String(code || '').trim();
    if (!barcode) return;
    if (activeLine == null) {
      toast.error('Select a product first', 'Click an item on the left, then scan.');
      return;
    }
    setScanning(true);
    try {
      const result = await transfersAPI.scan(id, { barcode, transfer_detail_id: activeLine });
      const data = result?.data ?? result;
      const did = data.transfer_detail_id ?? activeLine;
      setSessionScans((prev) => ({
        ...prev,
        [did]: [...(prev[did] || []), { scan_id: data.scan_id, barcode, quantity: data.quantity ?? 1 }],
      }));
      const nextTotals = { ...scannedTotalsRef.current, [did]: data.total_scanned };
      scannedTotalsRef.current = nextTotals;
      setScannedTotals(nextTotals);
      toast.success(`Scanned ${data.product_name || ''}`.trim());
      if (data.remaining === 0) {
        const next = details.find((d) =>
          d.transfer_detail_id !== did && (nextTotals[d.transfer_detail_id] || 0) < (d.quantity_transferred ?? d.quantity_requested ?? 0));
        setActiveLine(next ? next.transfer_detail_id : null);
      }
    } catch (err) {
      const msg = err?.errors ? Object.values(err.errors).flat().join(' ') : (err?.message || 'Scan rejected.');
      toast.error('Scan rejected', msg);
    } finally {
      setScanning(false);
    }
  }, [id, activeLine, details]);

  const submitManual = async () => {
    const v = scanInput.trim();
    if (!v) return;
    setScanInput('');
    await handleLineScan(v);
  };

  const handleRemoveScan = async (did, scan) => {
    try {
      await transfersAPI.removeScan(id, scan.scan_id);
      setSessionScans((prev) => ({ ...prev, [did]: (prev[did] || []).filter((s) => s.scan_id !== scan.scan_id) }));
      setScannedTotals((prev) => ({ ...prev, [did]: Math.max(0, (prev[did] || 0) - (scan.quantity || 1)) }));
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
      {/* FIFO pick guidance */}
      {pickList.length > 0 && (
        <>
          <h4 className="tf-panel-section-title">Suggested picks (FIFO)</h4>
          {pickList.map((line) => (
            <div key={line.product_id} className="tf-pick-group">
              <div className="tf-pick-head">
                <b>{line.product_name}</b>
                <span className="ak-muted" style={{ fontSize: '0.78rem' }}>need {line.needed}</span>
              </div>
              {(line.suggestions || []).length === 0
                ? <div className="ak-muted" style={{ fontSize: '0.76rem' }}>No stock suggestions available.</div>
                : (line.suggestions || []).map((s, i) => (
                  <div key={`${line.product_id}-${i}`} className="tf-pick-sugg">
                    <span className="tf-pick-zone">{s.zone_label || '—'}</span>
                    {s.serial_number
                      ? <span className="tf-pick-serial">{s.serial_number}</span>
                      : <span>qty {s.quantity}</span>}
                    {s.received_at && <span className="ak-muted">· {fmtWhen(s.received_at)}</span>}
                  </div>
                ))}
            </div>
          ))}
        </>
      )}

      {/* Scan workspace */}
      <div className="bsr-workflow" style={{ marginTop: '1rem' }}>
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
                const isActive = activeLine === did;
                const serialized = isLineSerialized(detail);
                const name = detail.product?.product_name || `Product #${detail.product_id}`;
                const pct = target > 0 ? Math.min(100, (scanned / target) * 100) : 0;
                return (
                  <div
                    key={did}
                    className={`bsr-item-row bsr-item-row--clickable${isActive ? ' bsr-item-row--active' : ''}${done ? ' bsr-item-row--done' : ''}`}
                    onClick={() => !done && setActiveLine(did)}
                    title={done ? 'Fully scanned' : 'Click to scan this item'}
                  >
                    <div className="bsr-item-info">
                      <span className="bsr-item-name">{name}</span>
                      <div className="bsr-item-badges">
                        {serialized ? <span className="bsr-serialized-badge">SERIAL</span> : <span className="bsr-bulk-badge">BULK</span>}
                        {isActive && <span className="bsr-active-badge">SCANNING NOW</span>}
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
              <div>
                <span className="bsr-card-title">SCAN BARCODE</span>
                {activeDetail ? (
                  <p className="bsr-card-subtitle"><strong>{activeDetail.product?.product_name || 'Selected item'}</strong></p>
                ) : (
                  <p className="bsr-card-subtitle bsr-card-subtitle--warn">Select an item on the left to begin</p>
                )}
              </div>
            </div>

            <CameraScanner
              onDecode={handleLineScan}
              mountId="tf-dispatch-scanner"
              storageKey="bs_inventory_camera_device_id"
              busy={submitting || scanning || activeLine == null}
              hint="Select a product, then scan each unit leaving the source."
            />

            <div className="bsr-scan-row">
              <input
                type="text"
                className={`bsr-scan-input${scanning ? ' bsr-scan-input--scanning' : ''}`}
                placeholder={activeLine != null ? 'Scan or type barcode / serial…' : 'Select an item on the left first…'}
                value={scanInput}
                onChange={(e) => setScanInput(e.target.value)}
                onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); submitManual(); } }}
                disabled={scanning || activeLine == null}
                autoComplete="off"
              />
              <button type="button" className="bsr-capture-btn" onClick={submitManual} disabled={scanning || activeLine == null || !scanInput.trim()}>
                {scanning ? 'Scanning…' : 'Submit'}
              </button>
            </div>
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
                        style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#dc2626', fontSize: 11, padding: '2px 6px' }}
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
    </div>
  );
};

export default DispatchPanel;
