import React, { useState, useMemo, useEffect } from 'react';
import { transfersAPI } from '../../services/api';
import { toast } from '../../utils/toast';
import { confirmWithReason } from '../../utils/confirm';
import { packageBreakdown } from '../../utils/packageFormat';

const productName = (d) => d.product?.product_name || `Product #${d.product_id}`;
const sku = (d) => d.product?.product_code || d.product?.applianceDetail?.model_code || '—';

/**
 * Verify panel — shown for a Pending transfer to an inventory analyst / admin.
 * Adjust per-line approved quantities (0 removes the line), assign the source
 * warehouse, then Submit (approve) or Reject with a reason.
 */
const VerifyPanel = ({ transfer, locations = [], onDone }) => {
  const details = useMemo(() => transfer?.details || [], [transfer]);

  const warehouses = useMemo(
    () => locations.filter((l) => l.location_type === 'warehouse'),
    [locations],
  );

  const [fromLocationId, setFromLocationId] = useState(
    transfer?.from_location_id ? String(transfer.from_location_id) : '',
  );
  const [toLocationId, setToLocationId] = useState(
    transfer?.to_location_id ? String(transfer.to_location_id) : '',
  );
  const [qty, setQty] = useState(() => {
    const init = {};
    details.forEach((d) => { init[d.transfer_detail_id] = d.quantity_requested ?? 0; });
    return init;
  });
  const [busy, setBusy] = useState(false);

  const [availByLoc, setAvailByLoc] = useState(null); // null = loading, {} = loaded
  const [availError, setAvailError] = useState(false);

  useEffect(() => {
    let ok = true;
    setAvailByLoc(null);
    setAvailError(false);
    transfersAPI.sourceAvailability(transfer.transfer_id)
      .then((res) => { if (ok) setAvailByLoc(res?.data && typeof res.data === 'object' ? res.data : {}); })
      .catch(() => { if (ok) { setAvailByLoc({}); setAvailError(true); } });
    return () => { ok = false; };
  }, [transfer.transfer_id]);

  const setLineQty = (did, v) =>
    setQty((prev) => ({ ...prev, [did]: Math.max(0, parseInt(v, 10) || 0) }));

  const anyApproved = Object.values(qty).some((n) => Number(n) > 0);

  const handleSubmit = async () => {
    if (!fromLocationId) {
      toast.error("Can't verify yet", 'Select a source warehouse to fulfill this transfer.');
      return;
    }
    if (!toLocationId) {
      toast.error("Can't verify yet", 'Select a destination for this transfer.');
      return;
    }
    if (fromLocationId === toLocationId) {
      toast.error("Can't verify yet", 'Source and destination must be different.');
      return;
    }
    if (!anyApproved) {
      toast.error("Can't verify yet", 'Approve at least one line (quantity greater than zero).');
      return;
    }
    setBusy(true);
    try {
      await transfersAPI.verify(transfer.transfer_id, {
        from_location_id: Number(fromLocationId),
        to_location_id: Number(toLocationId),
        lines: details.map((d) => ({
          transfer_detail_id: d.transfer_detail_id,
          quantity_approved: Number(qty[d.transfer_detail_id] || 0),
        })),
      });
      toast.success('Transfer approved', `${transfer.transfer_number} is ready to send.`);
      onDone?.();
    } catch (err) {
      const msg = err?.errors ? Object.values(err.errors).flat().join(' ') : (err?.message || 'Verification failed.');
      toast.error("Couldn't verify transfer", msg);
    } finally {
      setBusy(false);
    }
  };

  const handleReject = async () => {
    const { confirmed, reason } = await confirmWithReason({
      title: `Reject ${transfer.transfer_number}?`,
      confirmText: 'Reject',
      tone: 'danger',
      reasonRequired: true,
      reasonLabel: 'Reason for rejection',
      reasonPlaceholder: 'Explain why this transfer is being rejected…',
    });
    if (!confirmed) return;
    setBusy(true);
    try {
      await transfersAPI.reject(transfer.transfer_id, { rejection_reason: reason });
      toast.success('Transfer rejected');
      onDone?.();
    } catch (err) {
      toast.error("Couldn't reject transfer", err?.message || 'Rejection failed.');
    } finally {
      setBusy(false);
    }
  };

  const availFor = (productId) => {
    if (!fromLocationId || !availByLoc) return null;
    const entry = availByLoc[fromLocationId]?.[productId];
    return entry ? Number(entry.available) : 0; // present-but-missing product = 0
  };

  return (
    <div>
      <h4 className="tf-panel-section-title">Assign source warehouse</h4>
      <select
        className="ak-select"
        style={{ width: '100%', marginBottom: '1.1rem' }}
        value={fromLocationId}
        onChange={(e) => setFromLocationId(e.target.value)}
        disabled={busy}
      >
        <option value="">Select source warehouse…</option>
        {warehouses.map((l) => (
          <option key={l.location_id} value={String(l.location_id)}>
            {l.location_name}
          </option>
        ))}
      </select>

      {fromLocationId && availByLoc && !availError && (() => {
        const surviving = details.filter((d) => Number(qty[d.transfer_detail_id] || 0) > 0);
        const okCount = surviving.filter((d) => availFor(d.product_id) >= Number(qty[d.transfer_detail_id] || 0)).length;
        const shortAny = okCount < surviving.length;
        const whName = warehouses.find((l) => String(l.location_id) === String(fromLocationId))?.location_name || 'This warehouse';
        return (
          <div className={`tf-avail-summary${shortAny ? ' tf-avail-summary--warn' : ''}`} style={{ margin: '-0.6rem 0 1.1rem' }}>
            {whName} can fulfill {okCount} of {surviving.length} product{surviving.length === 1 ? '' : 's'}.
            {shortAny && ' Some lines exceed available stock — partial dispatch will auto-close the remainder.'}
          </div>
        );
      })()}
      {availByLoc === null && <div className="ak-muted" style={{ margin: '-0.6rem 0 1.1rem' }}>Checking stock…</div>}
      {availError && <div className="ak-muted" style={{ margin: '-0.6rem 0 1.1rem' }}>Availability unavailable.</div>}

      <h4 className="tf-panel-section-title">Assign destination</h4>
      <select
        className="ak-select"
        style={{ width: '100%', marginBottom: '1.1rem' }}
        value={toLocationId}
        onChange={(e) => setToLocationId(e.target.value)}
        disabled={busy}
      >
        <option value="">Select destination…</option>
        {locations
          .filter((l) => String(l.location_id) !== fromLocationId)
          .map((l) => (
            <option key={l.location_id} value={String(l.location_id)}>
              {l.location_name}
            </option>
          ))}
      </select>

      <h4 className="tf-panel-section-title">Lines to approve</h4>
      <div>
        {details.length === 0 && <p className="ak-muted">No line items on this transfer.</p>}
        {details.map((d) => {
          const did = d.transfer_detail_id;
          const liveAvail = availFor(d.product_id);
          const approvedQty = Number(qty[did] || 0);
          const liveShort = liveAvail == null ? 0 : Math.max(0, approvedQty - liveAvail);
          return (
            <div key={did} className="tf-line">
              <div className="tf-line-name">
                <b>{productName(d)}</b>
                <small>
                  SKU: {sku(d)} · requested {d.quantity_requested}
                  {(() => {
                    const bd = packageBreakdown(d.quantity_requested, d.product?.consumable_detail?.pieces_per_package, d.product?.consumable_detail?.package_unit);
                    return bd ? ` pcs · ${bd}` : '';
                  })()}
                </small>
              </div>
              {fromLocationId && liveAvail != null && (
                <span className={`tf-line-avail${liveShort > 0 ? ' tf-line-avail--short' : ' tf-line-avail--ok'}`}>
                  avail {liveAvail}
                  {liveShort > 0 && <span className="tf-shortfall"> · short {liveShort}</span>}
                </span>
              )}
              {!fromLocationId && <span className="ak-muted" style={{ fontSize: 12 }}>select a source to see stock</span>}
              <input
                type="number"
                min={0}
                className="ak-input tf-line-qty"
                value={qty[did] ?? 0}
                onChange={(e) => setLineQty(did, e.target.value)}
                disabled={busy}
                title="Approved quantity (0 removes this line)"
              />
            </div>
          );
        })}
      </div>

      <div className="tf-actions-row">
        <button type="button" className="ak-btn ak-btn--danger" onClick={handleReject} disabled={busy}>
          Reject
        </button>
        <button type="button" className="ak-btn ak-btn--primary" onClick={handleSubmit} disabled={busy}>
          {busy ? 'Submitting…' : 'Approve & Assign'}
        </button>
      </div>
    </div>
  );
};

export default VerifyPanel;
