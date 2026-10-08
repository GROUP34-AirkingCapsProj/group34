import ScanFeedback from '../../components/ScanFeedback';
import useScanFeedback from '../../hooks/useScanFeedback';
import React, { useState, useEffect, useCallback, useRef } from 'react';
import { transfersAPI, getToken } from '../../services/api';
import { toast } from '../../utils/toast';
import { confirmAction } from '../../utils/confirm';
import CameraScanner from '../../components/CameraScanner';

const isLineSerialized = (detail) =>
  detail?.is_serialized || detail?.product?.is_serialized || detail?.product?.product_type === 'appliance';

const lineTarget = (detail) => Number(detail?.quantity_transferred ?? detail?.quantity_scanned ?? 0);

/**
 * Receive panel — destination-warehouse scanning for an In-Transit transfer.
 * Records each arriving unit via barcode/manual entry, then confirms receipt
 * (-> Received) and surfaces the receipt PDF link.
 */
const ReceivePanel = ({ transfer, onDone }) => {
  const id = transfer?.transfer_id;
  const details = transfer?.details || [];

  const [sessionScans, setSessionScans] = useState({});
  const [receivedTotals, setReceivedTotals] = useState({});
  const [activeLine, setActiveLine] = useState(null);
  const { feedback, reportScan } = useScanFeedback();
  const scanInFlight = useRef(false);
  const scanFieldRef = useRef(null);
  const [scanInput, setScanInput] = useState('');
  const [scanQty, setScanQty] = useState(1);
  const [scanning, setScanning] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [received, setReceived] = useState(false);

  const receivedTotalsRef = useRef({});
  useEffect(() => { receivedTotalsRef.current = receivedTotals; }, [receivedTotals]);

  useEffect(() => {
    const init = {};
    details.forEach((d) => { init[d.transfer_detail_id] = d.quantity_received ?? 0; });
    setReceivedTotals(init);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id]);

  const totalReceived = Object.values(receivedTotals).reduce((s, n) => s + (n || 0), 0);
  const activeDetail = details.find((d) => d.transfer_detail_id === activeLine) || null;
  const activeSerialized = activeDetail ? isLineSerialized(activeDetail) : false;

  const handleLineScan = useCallback(async (code) => {
    const barcode = String(code || '').trim();
    if (!barcode || scanInFlight.current) return;
    if (activeLine == null) {
      toast.error('Select a product first', 'Click an item on the left, then scan.');
      return;
    }
    const detail = details.find((d) => d.transfer_detail_id === activeLine);
    const serialized = isLineSerialized(detail);
    const quantity = serialized ? 1 : Math.max(1, parseInt(scanQty, 10) || 1);

    scanInFlight.current = true;
    setScanning(true);
    try {
      const result = await transfersAPI.receiveScan(id, {
        barcode,
        transfer_detail_id: activeLine,
        quantity,
        quantity_unit: serialized ? 'piece' : 'package',
      });
      const data = result?.data ?? result;
      if (result?.success === false || data?.action === 'rejected') {
        throw new Error(data?.reason || result?.message || 'Scan rejected.');
      }
      const did = data.transfer_detail_id ?? activeLine;
      setSessionScans((prev) => ({
        ...prev,
        [did]: [...(prev[did] || []), { scan_id: data.scan_id, barcode, quantity: data.quantity ?? quantity }],
      }));
      const nextTotals = { ...receivedTotalsRef.current, [did]: data.quantity_received };
      receivedTotalsRef.current = nextTotals;
      setReceivedTotals(nextTotals);
      reportScan(true, `${data.quantity ?? quantity} piece(s) received. Keep scanning.`, barcode, detail?.product?.product_name || data.product_name || '');
      if (data.remaining === 0) {
        const next = details.find((d) => d.transfer_detail_id !== did && (nextTotals[d.transfer_detail_id] || 0) < lineTarget(d));
        setActiveLine(next ? next.transfer_detail_id : null);
        setScanQty(1);
      }
    } catch (err) {
      const msg = err?.errors ? Object.values(err.errors).flat().join(' ') : (err?.message || 'Scan rejected.');
      reportScan(false, msg, barcode);
    } finally {
      scanInFlight.current = false;
      setScanning(false);
    }
  }, [id, activeLine, details, scanQty, reportScan]);

  useEffect(() => { if (!scanning) scanFieldRef.current?.focus({ preventScroll: true }); }, [scanning, activeLine]);

  const submitManual = async () => {
    const v = scanInput.trim();
    if (!v) return;
    setScanInput('');
    await handleLineScan(v);
  };

  const handleRemoveScan = async (did, scan) => {
    try {
      await transfersAPI.removeReceiveScan(id, scan.scan_id);
      setSessionScans((prev) => ({ ...prev, [did]: (prev[did] || []).filter((s) => s.scan_id !== scan.scan_id) }));
      setReceivedTotals((prev) => ({ ...prev, [did]: Math.max(0, (prev[did] || 0) - (scan.quantity || 1)) }));
    } catch (err) {
      toast.error('Could not remove scan', err?.message || 'Failed to remove scan.');
    }
  };

  const handleConfirm = async () => {
    if (totalReceived < 1) {
      toast.error("Can't receive yet", 'Record at least one received unit before confirming.');
      return;
    }
    if (!(await confirmAction({
      title: `Confirm receipt of ${transfer?.transfer_number || 'this transfer'}?`,
      text: 'Scanned units will be added to destination stock.',
      confirmText: 'Confirm receipt',
      tone: 'primary',
    }))) return;
    setSubmitting(true);
    try {
      await transfersAPI.receiveConfirm(id, {});
      setReceived(true);
      toast.success('Receipt confirmed', `${transfer.transfer_number} has been received.`);
    } catch (err) {
      toast.error("Couldn't confirm receipt", err?.message || 'Failed to confirm receipt.');
    } finally {
      setSubmitting(false);
    }
  };

  const scannedFlat = [];
  details.forEach((d) => {
    const name = d.product?.product_name || `Product #${d.product_id}`;
    (sessionScans[d.transfer_detail_id] || []).forEach((s) => scannedFlat.push({ ...s, did: d.transfer_detail_id, productName: name }));
  });

  if (received) {
    const pdfUrl = transfersAPI.receiptPdfUrl(id, getToken());
    return (
      <div className="tf-drawer" style={{ textAlign: 'center', padding: '1.5rem 0' }}>
        <div style={{ fontSize: 36, marginBottom: 10 }}>✓</div>
        <h3 style={{ color: 'var(--ink-green)', fontWeight: 700, margin: '0 0 6px' }}>Receipt Confirmed</h3>
        <p className="ak-muted" style={{ marginBottom: 18 }}>{transfer?.transfer_number} has been received successfully.</p>
        <div className="tf-actions-row" style={{ justifyContent: 'center' }}>
          <a href={pdfUrl} target="_blank" rel="noopener noreferrer" className="ak-btn ak-btn--primary" style={{ textDecoration: 'none' }}>
            Download PDF Receipt
          </a>
          <button type="button" className="ak-btn" onClick={() => onDone?.()}>Done</button>
        </div>
      </div>
    );
  }

  return (
    <div className="tf-drawer">
      <div className="bsr-workflow">
        <div className="bsr-left">
          <div className="bsr-card">
            <div className="bsr-card-head">
              <span className="bsr-card-title">ITEMS TO RECEIVE</span>
              <span className="bsr-scanned-count">{totalReceived} received</span>
            </div>
            <div className="bsr-items-list">
              {details.length === 0 ? (
                <p className="bsr-items-empty">No line items on this transfer.</p>
              ) : details.map((detail) => {
                const did = detail.transfer_detail_id;
                const dispatched = lineTarget(detail);
                const rec = receivedTotals[did] ?? 0;
                const remaining = Math.max(0, dispatched - rec);
                const done = remaining === 0 && dispatched > 0;
                const isActive = activeLine === did;
                const serialized = isLineSerialized(detail);
                const name = detail.product?.product_name || `Product #${detail.product_id}`;
                const pct = dispatched > 0 ? Math.min(100, (rec / dispatched) * 100) : 0;
                return (
                  <div
                    key={did}
                    className={`bsr-item-row bsr-item-row--clickable${isActive ? ' bsr-item-row--active' : ''}${done ? ' bsr-item-row--done' : ''}`}
                    onClick={() => !done && setActiveLine(did)}
                    title={done ? 'Fully received' : 'Click to scan this item'}
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
                      <span className={`bsr-item-qty ${done ? 'bsr-item-qty--done' : 'bsr-item-qty--zero'}`}>{rec} / {dispatched} units</span>
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
                    statusOverlay={<ScanFeedback feedback={feedback} product={activeDetail?.product?.product_name}
                    quantity={activeSerialized ? 1 : Math.max(1, parseInt(scanQty, 10) || 1)} unit={activeSerialized ? 'pieces' : 'packages'} completed={receivedTotals[activeLine]}
                    target={activeDetail?.quantity_transferred ?? activeDetail?.quantity_scanned} busy={scanning} overlay />}
              onDecode={handleLineScan}
              mountId="tf-receive-scanner"
              storageKey="bs_inventory_camera_device_id"
              busy={submitting || scanning || activeLine == null}
              hint="Select a product, then scan each arriving unit."
            />

            <div className="bsr-scan-row">
              <input
                type="text"
                className={`bsr-scan-input${scanning ? ' bsr-scan-input--scanning' : ''}`}
                placeholder={activeLine != null ? 'Scan or type barcode / serial…' : 'Select an item on the left first…'}
                ref={scanFieldRef}
                      value={scanInput}
                onChange={(e) => setScanInput(e.target.value)}
                onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); submitManual(); } }}
                disabled={scanning || activeLine == null}
                autoComplete="off"
              />
              {activeDetail && !activeSerialized && (
                <input
                  type="number" min={1} value={scanQty}
                  onChange={(e) => setScanQty(Math.max(1, parseInt(e.target.value, 10) || 1))}
                  disabled={scanning} title="Number of packages represented by this scan"
                  style={{ width: 64, padding: '8px 6px', borderRadius: 6, border: '1px solid var(--border-strong)', fontSize: 13, textAlign: 'center' }}
                />
              )}
              {activeDetail && !activeSerialized && (
                <span style={{ fontSize: 11, color: 'var(--ink-blue-500)', whiteSpace: 'nowrap' }}>
                  package(s) × {Number(activeDetail.product?.pieces_per_package || activeDetail.product?.installation_material_detail?.pieces_per_package || 1)} pcs
                </span>
              )}
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
              <div className="bsr-scanned-empty"><p>No items received yet</p></div>
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
        <button type="button" className="ak-btn ak-btn--primary" onClick={handleConfirm} disabled={submitting || totalReceived < 1}>
          {submitting ? 'Confirming…' : 'Confirm Receipt'}
        </button>
      </div>
    </div>
  );
};

export default ReceivePanel;
