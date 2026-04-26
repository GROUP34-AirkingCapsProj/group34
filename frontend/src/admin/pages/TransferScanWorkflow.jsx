import React, { useState, useEffect, useCallback, useMemo } from 'react';
import { useSearchParams, useNavigate } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import { transfersAPI, locationsAPI } from '../services/api';
import { toast } from '../utils/toast';
import '../styles/dashboard_air.css';

/**
 * Warehouse ↔ showroom transfer workflow aligned with mobile mockups:
 * TRANSFER OUT = confirm shipment from source (in-transit transfer list from selected warehouse).
 * TRANSFER IN = scan / enter barcode at destination to record receipt into showroom stock.
 */
const TransferScanWorkflow = () => {
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();
  const initialMode = searchParams.get('mode') === 'in' ? 'in' : 'out';

  const [mode, setMode] = useState(initialMode);
  const [locations, setLocations] = useState([]);
  const [locationId, setLocationId] = useState('');
  const [transfers, setTransfers] = useState([]);
  const [loading, setLoading] = useState(true);
  const [selectedId, setSelectedId] = useState(searchParams.get('transfer') || '');
  const [barcode, setBarcode] = useState('');
  const [qty, setQty] = useState(1);
  const [submitting, setSubmitting] = useState(false);

  const loadLocations = useCallback(async () => {
    try {
      const res = await locationsAPI.getAll();
      const list = Array.isArray(res?.data) ? res.data : [];
      setLocations(list);
      setLocationId((prev) => {
        if (prev) return prev;
        if (!list.length) return '';
        const first = list[0];
        return String(first.location_id ?? first.id ?? '');
      });
    } catch (e) {
      toast.error(e.message || 'Failed to load locations.');
    }
  }, []);

  const loadTransfers = useCallback(async () => {
    setLoading(true);
    try {
      const res = await transfersAPI.getAll({ per_page: 100 });
      const list = Array.isArray(res?.data) ? res.data : [];
      setTransfers(list);
    } catch (e) {
      toast.error(e.message || 'Failed to load transfers.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadLocations();
  }, [loadLocations]);

  useEffect(() => {
    loadTransfers();
  }, [loadTransfers]);

  useEffect(() => {
    const m = searchParams.get('mode');
    if (m === 'in' || m === 'out') setMode(m);
  }, [searchParams]);

  useEffect(() => {
    const tid = searchParams.get('transfer');
    if (!tid || transfers.length === 0) return;
    const t = transfers.find((x) => String(x.transfer_id) === String(tid));
    if (!t) return;
    setSelectedId(String(tid));
    const m = searchParams.get('mode') || 'out';
    if (m === 'in' && t.to_location_id) setLocationId(String(t.to_location_id));
    if (m === 'out' && t.from_location_id) setLocationId(String(t.from_location_id));
  }, [searchParams, transfers]);

  useEffect(() => {
    setSearchParams(
      (prev) => {
        const next = new URLSearchParams(prev);
        next.set('mode', mode);
        if (selectedId) next.set('transfer', selectedId);
        else next.delete('transfer');
        return next;
      },
      { replace: true }
    );
  }, [mode, selectedId, setSearchParams]);

  const filtered = useMemo(() => {
    const lid = String(locationId || '');
    return transfers.filter((t) => {
      const st = t.status?.status_name || '';
      const inTransit = st === 'In Transit';
      if (!inTransit) return false;
      if (mode === 'out') {
        return String(t.from_location_id ?? '') === lid;
      }
      return String(t.to_location_id ?? '') === lid;
    });
  }, [transfers, locationId, mode]);

  const selected = useMemo(
    () => filtered.find((t) => String(t.transfer_id) === String(selectedId)) || transfers.find((t) => String(t.transfer_id) === String(selectedId)),
    [filtered, transfers, selectedId]
  );

  const totals = useMemo(() => {
    if (!selected?.details?.length) return { shipped: 0, received: 0, lines: 0 };
    let shipped = 0;
    let received = 0;
    selected.details.forEach((d) => {
      shipped += Number(d.quantity_transferred ?? 0);
      received += Number(d.quantity_received ?? 0);
    });
    return { shipped, received, lines: selected.details.length };
  }, [selected]);

  const pendingUnits = Math.max(0, totals.shipped - totals.received);

  const handleReceive = async (e) => {
    e.preventDefault();
    if (!selected) {
      toast.error('Select a transfer first.');
      return;
    }
    const code = barcode.trim();
    if (!code) {
      toast.error('Enter or scan a barcode / model code.');
      return;
    }
    setSubmitting(true);
    try {
      await transfersAPI.receive(selected.transfer_id, {
        barcode: code,
        quantity: Math.max(1, parseInt(qty, 10) || 1),
      });
      toast.success('Received — stock updated at destination.');
      setBarcode('');
      await loadTransfers();
    } catch (err) {
      toast.error(err.message || 'Receive failed.');
    } finally {
      setSubmitting(false);
    }
  };

  const printDr = () => {
    if (!selected) return;
    const t = selected;
    const fromLoc = t.from_location?.location_name || t.fromLocation?.location_name || '—';
    const toLoc = t.to_location?.location_name || t.toLocation?.location_name || '—';
    const tDate = t.transfer_date
      ? new Date(t.transfer_date).toLocaleDateString('en-PH', { month: 'long', day: 'numeric', year: 'numeric' })
      : '';
    const items = t.details || [];
    const rows = items
      .map(
        (d) => `
      <tr>
        <td class="tc">${d.quantity_transferred ?? ''}</td>
        <td>${d.product?.product_name || d.product?.product_code || ''}</td>
        <td>${Number(d.quantity_received ?? 0)} / ${Number(d.quantity_transferred ?? 0)}</td>
      </tr>`
      )
      .join('');
    const html = `<!DOCTYPE html><html><head><meta charset="utf-8"/><title>DR ${t.transfer_number}</title>
      <style>body{font-family:Arial,sans-serif;padding:24px;font-size:12px;} h1{font-size:15px;} table{width:100%;border-collapse:collapse;margin-top:12px;} th,td{border:1px solid #ccc;padding:6px;} .tc{text-align:center;}</style></head><body>
      <h1>Delivery / transfer — ${t.transfer_number}</h1>
      <p><strong>From:</strong> ${fromLoc} &nbsp; <strong>To:</strong> ${toLoc}</p>
      <p><strong>Date:</strong> ${tDate}</p>
      <table><thead><tr><th>Qty</th><th>Product</th><th>Recv / Shipped</th></tr></thead><tbody>${rows}</tbody></table>
      <p style="margin-top:20px;">Receiver: _______________________</p>
      </body></html>`;
    const win = window.open('', '_blank', 'width=800,height=700');
    win.document.write(html);
    win.document.close();
    win.focus();
    setTimeout(() => win.print(), 400);
  };

  const headerTitle = mode === 'out' ? 'TRANSFER OUT — scanning checklist' : 'TRANSFER IN — receive at showroom';

  return (
    <AdminLayout>
      <div className="page-container" style={{ maxWidth: 560, margin: '0 auto' }}>
        <div style={{ background: 'linear-gradient(135deg,#1e3a5f 0%,#0f172a 100%)', borderRadius: 12, padding: '16px 18px', color: '#fff', marginBottom: 16 }}>
          <p style={{ fontSize: 11, letterSpacing: '0.12em', opacity: 0.85, margin: 0 }}>TRANSFER SCANNING BARCODES</p>
          <h1 style={{ fontSize: 17, margin: '6px 0 0', fontWeight: 700 }}>{headerTitle}</h1>
        </div>

        <div style={{ display: 'flex', gap: 8, marginBottom: 16 }}>
          <button
            type="button"
            className={mode === 'out' ? 'btn-primary' : 'btn-secondary'}
            style={{ flex: 1, borderRadius: 8, padding: '10px 12px', ...(mode === 'out' ? { background: '#dc2626', borderColor: '#dc2626' } : {}) }}
            onClick={() => setMode('out')}
          >
            TRANSFER OUT
          </button>
          <button
            type="button"
            className={mode === 'in' ? 'btn-primary' : 'btn-secondary'}
            style={{ flex: 1, borderRadius: 8, padding: '10px 12px', ...(mode === 'in' ? { background: '#dc2626', borderColor: '#dc2626' } : {}) }}
            onClick={() => setMode('in')}
          >
            TRANSFER IN
          </button>
        </div>

        <p style={{ fontSize: 13, color: '#64748b', marginBottom: 12 }}>
          {mode === 'out'
            ? 'Pick your warehouse. Lists transfers that are in transit (stock has left this site; showroom has not fully received yet).'
            : 'Pick your showroom / destination. Scan barcodes to pull stock into this location when the truck arrives.'}
        </p>

        <div className="form-group" style={{ marginBottom: 12 }}>
          <label>{mode === 'out' ? 'From (warehouse) location' : 'To (showroom) location'}</label>
          <select value={locationId} onChange={(e) => setLocationId(e.target.value)} style={{ width: '100%', padding: 10, borderRadius: 8 }}>
            {locations.map((l) => (
              <option key={l.location_id ?? l.id} value={l.location_id ?? l.id}>
                {l.location_name || l.name}
              </option>
            ))}
          </select>
        </div>

        <div className="form-group" style={{ marginBottom: 12 }}>
          <label>
            {mode === 'out'
              ? 'Shipment in transit (from this warehouse)'
              : 'In-transit transfer (receiving at this showroom)'}
          </label>
          <p style={{ fontSize: 12, color: '#64748b', margin: '0 0 8px', lineHeight: 1.45 }}>
            {mode === 'out'
              ? 'You do not create the warehouse shipment here. Choose an existing transfer that is already In Transit (created in Stock Transfer with “Ship first”) to open the checklist and print a DR.'
              : 'Choose the shipment arriving at this showroom, then scan barcodes below to record receipt into stock.'}
          </p>
          <select value={selectedId} onChange={(e) => setSelectedId(e.target.value)} style={{ width: '100%', padding: 10, borderRadius: 8 }}>
            <option value="">{mode === 'out' ? 'Select shipment…' : 'Select transfer…'}</option>
            {filtered.map((t) => (
              <option key={t.transfer_id} value={t.transfer_id}>
                {t.transfer_number} — {t.from_location?.location_name || '—'} → {t.to_location?.location_name || '—'}
              </option>
            ))}
          </select>
        </div>

        {loading ? (
          <p style={{ color: '#64748b' }}>Loading transfers…</p>
        ) : filtered.length === 0 ? (
          <div style={{ background: '#f8fafc', borderRadius: 10, padding: 16, color: '#64748b', fontSize: 14 }}>
            No in-transit transfers for this location. Create one from{' '}
            <button type="button" className="btn-link" style={{ padding: 0 }} onClick={() => navigate('/admin/transfers')}>
              Stock Transfer
            </button>{' '}
            with &quot;Ship first (showroom receives later)&quot; checked.
          </div>
        ) : null}

        {selected && (
          <div style={{ background: '#fef2f2', borderRadius: 12, padding: 16, marginBottom: 16, border: '1px solid #fecaca' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 8 }}>
              <div>
                <strong style={{ color: '#991b1b' }}>Product scan checklist</strong>
                <div style={{ fontSize: 12, color: '#7f1d1d', marginTop: 4 }}>
                  {selected.from_location?.location_name || '—'} → {selected.to_location?.location_name || '—'}
                </div>
              </div>
              <button type="button" className="btn-primary" style={{ background: '#dc2626', borderColor: '#dc2626', whiteSpace: 'nowrap' }} onClick={printDr}>
                PRINT DR
              </button>
            </div>
            <div style={{ marginTop: 12 }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 13, marginBottom: 6 }}>
                <span>
                  {totals.received}/{totals.shipped} units {mode === 'in' ? 'received' : 'received at destination'}
                </span>
                <span style={{ color: '#b91c1c' }}>{pendingUnits} pending</span>
              </div>
              <div style={{ height: 8, background: '#fee2e2', borderRadius: 4, overflow: 'hidden' }}>
                <div
                  style={{
                    height: '100%',
                    width: `${totals.shipped ? Math.min(100, (totals.received / totals.shipped) * 100) : 0}%`,
                    background: '#dc2626',
                    transition: 'width 0.3s ease',
                  }}
                />
              </div>
            </div>

            <ul style={{ listStyle: 'none', padding: 0, margin: '14px 0 0' }}>
              {(selected.details || []).map((d, i) => {
                const tr = Number(d.quantity_transferred ?? 0);
                const rc = Number(d.quantity_received ?? 0);
                const pend = tr - rc;
                return (
                  <li
                    key={d.transfer_detail_id ?? i}
                    style={{
                      display: 'flex',
                      justifyContent: 'space-between',
                      alignItems: 'center',
                      padding: '10px 0',
                      borderBottom: i < selected.details.length - 1 ? '1px solid #fecaca' : 'none',
                      fontSize: 14,
                    }}
                  >
                    <span>{d.product?.product_name || 'Product'}</span>
                    <span style={{ fontSize: 12, color: pend > 0 ? '#b45309' : '#059669' }}>
                      {rc}/{tr} units
                    </span>
                  </li>
                );
              })}
            </ul>
          </div>
        )}

        {mode === 'in' && selected && pendingUnits > 0 && (
          <form onSubmit={handleReceive} style={{ background: '#fff', border: '1px solid #e2e8f0', borderRadius: 12, padding: 16 }}>
            <h3 style={{ margin: '0 0 12px', fontSize: 15 }}>Scan or enter barcode</h3>
            <input
              className="search-input"
              style={{ width: '100%', marginBottom: 10 }}
              placeholder="Barcode or model code"
              value={barcode}
              onChange={(e) => setBarcode(e.target.value)}
              autoComplete="off"
            />
            <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
              <label style={{ fontSize: 13 }}>Qty</label>
              <input type="number" min={1} value={qty} onChange={(e) => setQty(e.target.value)} style={{ width: 80, padding: 8, borderRadius: 8 }} />
            </div>
            <button type="submit" className="btn-primary" style={{ marginTop: 12, width: '100%', background: '#1d4ed8' }} disabled={submitting}>
              {submitting ? 'Recording…' : 'Record receipt'}
            </button>
          </form>
        )}

        {mode === 'in' && selected && pendingUnits === 0 && totals.shipped > 0 && (
          <p style={{ color: '#059669', fontWeight: 600 }}>Fully received — showroom stock is up to date for this transfer.</p>
        )}

        <div style={{ marginTop: 20 }}>
          <button type="button" className="btn-secondary" onClick={() => navigate('/admin/transfers')}>
            ← Back to Stock Transfer
          </button>
        </div>
      </div>
    </AdminLayout>
  );
};

export default TransferScanWorkflow;
