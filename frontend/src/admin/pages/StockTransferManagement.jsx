import React, { useState, useEffect, useCallback, useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import Modal from '../components/Modal';
import { transfersAPI, locationsAPI, productsAPI, statusAPI } from '../services/api';
import { toast } from '../utils/toast';
import { getTransferWorkflow, transferShippedQty } from '../utils/moduleFeedStatus';
import '../../admin/styles/dashboard_air.css';
import '../styles/transaction_management.css';

const emptyDetail = () => ({ product_id: '', quantity_transferred: 1 });
const emptyForm = () => ({
  from_location_id: '', to_location_id: '', transfer_number: '',
  transfer_date: '', status_id: '', details: [emptyDetail()],
});

const formatDateTime = (value) => {
  if (!value) return 'N/A';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return String(value);
  return date.toLocaleString('en-PH', {
    year: 'numeric',
    month: 'short',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  });
};

const StockTransferManagement = () => {
  const navigate = useNavigate();
  const [transfers, setTransfers] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [pagination, setPagination] = useState({ total: 0, last_page: 1 });
  const [searchTerm, setSearchTerm] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [locations, setLocations] = useState([]);
  const [products, setProducts] = useState([]);
  const [statuses, setStatuses] = useState([]);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [viewingTransfer, setViewingTransfer] = useState(null);
  const [editingTransfer, setEditingTransfer] = useState(null);
  const [submitting, setSubmitting] = useState(false);
  const [formData, setFormData] = useState(emptyForm());
  const [shipFirst, setShipFirst] = useState(false);

  const fetchDropdowns = useCallback(async () => {
    try {
      const [locRes, prodRes, statRes] = await Promise.all([
        locationsAPI.getAll(),
        productsAPI.getAll({ per_page: 500 }),
        statusAPI.getAll(),
      ]);
      setLocations(Array.isArray(locRes?.data) ? locRes.data : []);
      setProducts(Array.isArray(prodRes?.data) ? prodRes.data : []);
      setStatuses(Array.isArray(statRes?.data) ? statRes.data : []);
    } catch (e) { console.error(e); }
  }, []);

  const fetchTransfers = useCallback(async () => {
    setLoading(true);
    try {
      const res = await transfersAPI.getAll({ page: 1, per_page: 300 });
      setTransfers(Array.isArray(res?.data) ? res.data : []);
      setPagination(res?.pagination || res?.meta || { total: 0, last_page: 1 });
      setError(null);
    } catch (e) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { fetchDropdowns(); }, [fetchDropdowns]);
  useEffect(() => { fetchTransfers(); }, [fetchTransfers]);

  const workflowCounts = useMemo(() => {
    let transit = 0;
    let completed = 0;
    let tbr = 0;
    transfers.forEach((t) => {
      const w = getTransferWorkflow(t);
      if (w.key === 'transit') transit += 1;
      else if (w.key === 'done') completed += 1;
      else tbr += 1;
    });
    return { transit, completed, tbr };
  }, [transfers]);

  const thisMonthCount = useMemo(() => {
    const n = new Date();
    return transfers.filter((t) => {
      if (!t.transfer_date) return false;
      const d = new Date(t.transfer_date);
      return d.getMonth() === n.getMonth() && d.getFullYear() === n.getFullYear();
    }).length;
  }, [transfers]);

  const filteredTransfers = useMemo(() => {
    const q = searchTerm.trim().toLowerCase();
    let rows = transfers;
    if (q) {
      rows = rows.filter((t) => {
        const names = (t.details || []).map((d) => d.product?.product_name).join(' ');
        const fromN = t.from_location?.location_name || t.fromLocation?.location_name || '';
        const toN = t.to_location?.location_name || t.toLocation?.location_name || '';
        const blob = `${t.transfer_number} ${fromN} ${toN} ${names}`.toLowerCase();
        return blob.includes(q);
      });
    }
    if (statusFilter) {
      rows = rows.filter((t) => getTransferWorkflow(t).key === statusFilter);
    }
    return rows;
  }, [transfers, searchTerm, statusFilter]);

  const printTransfer = (t) => {
    const fromLoc = t.from_location?.location_name || t.fromLocation?.location_name || '—';
    const toLoc = t.to_location?.location_name || t.toLocation?.location_name || '—';
    const tDate = t.transfer_date
      ? new Date(t.transfer_date).toLocaleDateString('en-US', { month: 'long', day: '2-digit', year: 'numeric' }).toUpperCase()
      : new Date().toLocaleDateString('en-US', { month: 'long', day: '2-digit', year: 'numeric' }).toUpperCase();
    const items = t.details || [];
    const blankCount = Math.max(0, 18 - items.length);

    const itemRows = items.map((d) => `
      <tr>
        <td class="tc">${d.quantity_transferred || ''}</td>
        <td>${d.product?.product_name || d.product?.product_code || ''}</td>
        <td></td>
      </tr>`).join('') + Array(blankCount).fill('<tr><td>&nbsp;</td><td></td><td></td></tr>').join('');

    const html = `<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8"/>
<title>Transfer ${t.transfer_number}</title>
<style>
  *{margin:0;padding:0;box-sizing:border-box;}
  body{font-family:Arial,Helvetica,sans-serif;font-size:11.5px;color:#000;padding:28px 36px;}
  .co-name{font-size:13px;font-weight:bold;}
  .co-addr{font-size:10px;margin:1px 0;}
  .header-row{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px;}
  .doc-block{text-align:right;}
  .doc-title{font-size:13px;font-weight:bold;letter-spacing:0.1em;text-align:center;margin:8px 0 2px;}
  .no-badge{font-size:13px;font-weight:bold;color:#c00;}
  .from-to{display:grid;grid-template-columns:1fr 1fr;gap:4px 16px;margin:10px 0;font-size:11.5px;}
  .ft-row{display:flex;gap:6px;align-items:flex-end;}
  .ft-lbl{font-weight:bold;white-space:nowrap;font-size:11px;}
  .ft-val{border-bottom:1px solid #000;flex:1;font-size:11.5px;padding-bottom:1px;}
  table.items{width:100%;border-collapse:collapse;margin-top:4px;}
  table.items th{border:1px solid #000;padding:4px 8px;font-size:10.5px;text-align:center;font-weight:bold;}
  table.items td{border:1px solid #000;padding:3px 8px;font-size:11px;height:20px;}
  .tc{text-align:center;}
  .sigs{display:flex;justify-content:space-between;margin-top:16px;}
  .sig-left{width:40%;}
  .sig-right{width:50%;}
  .sig-line{display:flex;gap:6px;align-items:flex-end;margin-bottom:10px;}
  .sig-line .lbl{font-size:10.5px;font-weight:bold;white-space:nowrap;}
  .sig-line .line{border-bottom:1px solid #000;flex:1;}
  .rcvd-txt{font-size:10.5px;font-weight:bold;margin-bottom:8px;}
  .rcvd-sub{font-size:9.5px;}
  @media print{body{padding:15px 20px;}@page{size:A4 portrait;margin:10mm;}}
</style>
</head>
<body>
  <div class="header-row">
    <div>
      <div class="co-name">PARAMOND INC.</div>
      <div class="co-addr">Zone 5, Lower Bulua, Cagayan De Oro City</div>
      <div class="co-addr">Email: mta.paramond@gmail.com</div>
    </div>
    <div class="doc-block">
      <div class="doc-title">INTERBRANCH STOCK TRANSFER</div>
      <div class="no-badge">No. ${t.transfer_number}</div>
    </div>
  </div>

  <div class="from-to">
    <div class="ft-row"><span class="ft-lbl">FROM:</span><span class="ft-val">${fromLoc}</span></div>
    <div class="ft-row"><span class="ft-lbl">DATE:</span><span class="ft-val">${tDate}</span></div>
    <div class="ft-row"><span class="ft-lbl">TO:</span><span class="ft-val">${toLoc}</span></div>
    <div class="ft-row"><span class="ft-lbl">MR #:</span><span class="ft-val"></span></div>
  </div>

  <table class="items">
    <thead>
      <tr>
        <th style="width:80px;">QUANTITY</th>
        <th>MODEL</th>
        <th style="width:160px;">SERIAL NO.</th>
      </tr>
    </thead>
    <tbody>${itemRows}</tbody>
  </table>

  <div class="sigs">
    <div class="sig-left">
      <div class="sig-line"><span class="lbl">Prepared by:</span><span class="line"></span></div>
      <div class="sig-line"><span class="lbl">Approved by:</span><span class="line"></span></div>
    </div>
    <div class="sig-right">
      <div class="rcvd-txt">Received the goods in order and condition</div>
      <div class="sig-line"><span class="line"></span></div>
      <div class="rcvd-sub">Receiver's Signature over Printed Name</div>
    </div>
  </div>
</body>
</html>`;

    const win = window.open('', '_blank', 'width=860,height=750');
    win.document.write(html);
    win.document.close();
    win.focus();
    setTimeout(() => win.print(), 600);
  };

  const openAddModal = () => { setEditingTransfer(null); setFormData(emptyForm()); setShipFirst(false); setIsModalOpen(true); };
  const openEditModal = (t) => {
    setEditingTransfer(t);
    setFormData({
      from_location_id: t.from_location_id || '',
      to_location_id: t.to_location_id || '',
      transfer_number: t.transfer_number || '',
      transfer_date: t.transfer_date ? t.transfer_date.split('T')[0] : '',
      status_id: t.status_id || '',
      details: t.details?.length ? t.details.map((d) => ({
        product_id: d.product_id || '', quantity_transferred: d.quantity_transferred || 1,
      })) : [emptyDetail()],
    });
    setIsModalOpen(true);
    setShipFirst(false);
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (formData.from_location_id && formData.from_location_id === formData.to_location_id) {
      toast.error('From and To locations must be different.');
      return;
    }
    if (!formData.details[0]?.product_id) { toast.error('Add at least one product.'); return; }
    setSubmitting(true);
    try {
      const payload = {
        ...formData,
        details: formData.details.map((d) => ({ ...d, quantity_transferred: parseInt(d.quantity_transferred, 10) || 1 })),
      };
      if (!editingTransfer && shipFirst) {
        payload.ship_only = true;
      }
      if (editingTransfer) {
        await transfersAPI.update(editingTransfer.transfer_id, payload);
        toast.success('Transfer updated.');
      } else {
        await transfersAPI.create(payload);
        toast.success(shipFirst ? 'Transfer shipped — showroom must receive to add stock at destination.' : 'Transfer created. Stock moved.');
      }
      setIsModalOpen(false);
      fetchTransfers();
    } catch (err) { toast.error(err.message || 'Failed to save.'); } finally { setSubmitting(false); }
  };

  const handleDelete = async (id) => {
    if (!window.confirm('Delete this transfer record?')) return;
    try {
      await transfersAPI.delete(id);
      toast.success('Deleted.');
      fetchTransfers();
    } catch (err) { toast.error(err.message || 'Failed to delete.'); }
  };

  const addDetailRow = () => setFormData((f) => ({ ...f, details: [...f.details, emptyDetail()] }));
  const removeDetailRow = (i) => setFormData((f) => ({ ...f, details: f.details.filter((_, idx) => idx !== i) }));
  const updateDetail = (i, field, value) => setFormData((f) => {
    const details = [...f.details];
    details[i] = { ...details[i], [field]: value };
    return { ...f, details };
  });

  const locId = (l) => l.location_id ?? l.id;

  return (
    <AdminLayout>
      <div className="txn-page">
        <div className="txn-page-header">
          <div className="txn-page-header-left">
            <h1>Stock Transfer</h1>
            <p>Transfer inventory between locations — <strong>In transit</strong> = naka-scan na sa warehouse, papunta sa showroom; <strong>Completed</strong> = natanggap na sa destination</p>
          </div>
          <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', justifyContent: 'flex-end' }}>
            <button type="button" className="txn-btn-print" onClick={() => navigate('/admin/transfer-scan')}>
              Transfer scan (OUT / IN)
            </button>
            <button type="button" className="txn-btn-create" onClick={openAddModal}>+ New Transfer</button>
          </div>
        </div>

        <div className="txn-stats-row">
          {[
            { label: 'Total transfers', value: pagination.total || transfers.length, cls: 'txn-stat-icon-blue' },
            { label: 'In transit', value: workflowCounts.transit, cls: 'txn-stat-icon-orange' },
            { label: 'Completed', value: workflowCounts.completed, cls: 'txn-stat-icon-green' },
            { label: 'Awaiting ship', value: workflowCounts.tbr, cls: 'txn-stat-icon-purple' },
          ].map((s) => (
            <div key={s.label} className="txn-stat-card">
              <div className={`txn-stat-icon ${s.cls}`}>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="22" height="22">
                  <path d="M17 3l4 4-4 4" /><path d="M3 7h18" /><path d="M7 21l-4-4 4-4" /><path d="M21 17H3" />
                </svg>
              </div>
              <div className="txn-stat-info">
                <span className="txn-stat-label">{s.label}</span>
                <span className="txn-stat-number">{s.value}</span>
              </div>
            </div>
          ))}
        </div>
        <p style={{ fontSize: 12, color: '#6b7280', margin: '-12px 0 20px' }}>
          This month: <strong>{thisMonthCount}</strong> transfers
        </p>

        {error && (
          <div className="txn-error">
            {error}
            <button type="button" className="txn-btn-reset" style={{ marginLeft: 12 }} onClick={() => fetchTransfers()}>Retry</button>
          </div>
        )}

        <div className="txn-table-card">
          <div className="txn-table-top">
            <div className="txn-table-title">
              <h3>All transfers</h3>
              <p>{filteredTransfers.length} shown</p>
            </div>
          </div>

          <div className="txn-filter-row">
            <div className="txn-search-input">
              <input
                type="text"
                placeholder="Search transfer #, from, to, product..."
                value={searchTerm}
                onChange={(e) => setSearchTerm(e.target.value)}
              />
            </div>
            <div className="txn-filter-group">
              <select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
                <option value="">All status</option>
                <option value="tbr">To be received / pending ship</option>
                <option value="transit">In transit</option>
                <option value="done">Completed</option>
              </select>
              <button type="button" className="txn-btn-reset" onClick={() => { setSearchTerm(''); setStatusFilter(''); }}>Reset</button>
            </div>
          </div>

          <div className="txn-feed">
            {loading ? (
              <div className="txn-empty">Loading transfers...</div>
            ) : filteredTransfers.length === 0 ? (
              <div className="txn-empty">No transfer records match your filters.</div>
            ) : (
              filteredTransfers.map((t) => {
                const wf = getTransferWorkflow(t);
                const qty = transferShippedQty(t);
                const first = t.details?.[0]?.product;
                const title = first?.product_name || (t.details?.length > 1 ? `${t.details.length} products` : 'Stock transfer');
                const fromN = t.from_location?.location_name || t.fromLocation?.location_name || '—';
                const toN = t.to_location?.location_name || t.toLocation?.location_name || '—';
                return (
                  <article className="txn-feed-item" key={t.transfer_id}>
                    <div className="txn-feed-left">
                      <div className="txn-feed-title-row">
                        <span className="txn-type-badge txn-type-transfer">Transfer</span>
                        <h4>{title}</h4>
                      </div>
                      <p className="txn-feed-meta">
                        {fromN}
                        {' → '}
                        {toN}
                        {' • '}
                        {formatDateTime(t.transfer_date || t.created_at)}
                      </p>
                      <p className="txn-feed-ref">Ref: {t.transfer_number || t.transfer_id}</p>
                      <div className="txn-feed-actions">
                        <button type="button" className="txn-btn-view" onClick={() => setViewingTransfer(t)}>View</button>
                        <button type="button" className="txn-btn-view" style={{ color: '#059669' }} onClick={() => printTransfer(t)}>Print</button>
                        <button type="button" className="txn-btn-edit" onClick={() => openEditModal(t)}>Edit</button>
                        <button type="button" className="txn-btn-delete" onClick={() => handleDelete(t.transfer_id)}>Delete</button>
                      </div>
                    </div>
                    <div className="txn-feed-right">
                      <span className={`txn-status-badge ${wf.badgeClass}`}>{wf.label}</span>
                      <span className="txn-feed-qty">{qty.toLocaleString()} units</span>
                    </div>
                  </article>
                );
              })
            )}
          </div>
        </div>
      </div>

      {viewingTransfer && (
        <Modal title={`Transfer: ${viewingTransfer.transfer_number}`} onClose={() => setViewingTransfer(null)}>
          <div className="detail-grid">
            <div><span className="detail-label">Status</span><span className="detail-value">{getTransferWorkflow(viewingTransfer).label}</span></div>
            <div><span className="detail-label">From Location</span><span className="detail-value">{viewingTransfer.from_location?.location_name || viewingTransfer.fromLocation?.location_name || '—'}</span></div>
            <div><span className="detail-label">To Location</span><span className="detail-value">{viewingTransfer.to_location?.location_name || viewingTransfer.toLocation?.location_name || '—'}</span></div>
            <div><span className="detail-label">Transfer Date</span><span className="detail-value">{viewingTransfer.transfer_date ? new Date(viewingTransfer.transfer_date).toLocaleDateString() : '—'}</span></div>
            <div><span className="detail-label">API status</span><span className="detail-value">{viewingTransfer.status?.status_name || '—'}</span></div>
            {(viewingTransfer.total_quantity_transferred != null || viewingTransfer.total_quantity_received != null) && (
              <div style={{ gridColumn: '1 / -1' }}>
                <span className="detail-label">Received at destination</span>
                <span className="detail-value">
                  {Number(viewingTransfer.total_quantity_received ?? 0)} / {Number(viewingTransfer.total_quantity_transferred ?? transferShippedQty(viewingTransfer))} units
                </span>
              </div>
            )}
          </div>
          {viewingTransfer.details?.length > 0 && (
            <div style={{ marginTop: 16 }}>
              <h4 style={{ marginBottom: 8, color: '#374151' }}>Items Transferred</h4>
              <table className="data-table">
                <thead><tr><th>Product</th><th>Qty shipped</th><th>Qty received (showroom)</th></tr></thead>
                <tbody>{viewingTransfer.details.map((d, i) => (
                  <tr key={i}>
                    <td>{d.product?.product_name || '—'}</td>
                    <td>{d.quantity_transferred}</td>
                    <td>
                      {d.quantity_received != null
                        ? Number(d.quantity_received)
                        : (['Received', 'Completed'].includes(viewingTransfer.status?.status_name) ? Number(d.quantity_transferred) : '—')}
                    </td>
                  </tr>
                ))}</tbody>
              </table>
            </div>
          )}
          {getTransferWorkflow(viewingTransfer).key === 'transit' && (
            <p style={{ marginTop: 12, fontSize: 14, color: '#b45309' }}>
              Stock has left the warehouse. The showroom must scan items on{' '}
              <button type="button" className="btn-link" style={{ padding: 0 }} onClick={() => { setViewingTransfer(null); navigate(`/admin/transfer-scan?mode=in&transfer=${viewingTransfer.transfer_id}`); }}>
                Transfer scan → TRANSFER IN
              </button>
              {' '}before units appear at the destination.
            </p>
          )}
          <div className="modal-footer">
            <button type="button" className="btn-secondary" onClick={() => setViewingTransfer(null)}>Close</button>
            <button type="button" className="btn-primary" onClick={() => printTransfer(viewingTransfer)}>Print Transfer</button>
          </div>
        </Modal>
      )}

      {isModalOpen && (
        <Modal title={editingTransfer ? 'Edit Transfer' : 'New Stock Transfer'} onClose={() => setIsModalOpen(false)}>
          <form onSubmit={handleSubmit}>
            <div className="form-grid-2">
              <div className="form-group">
                <label>From Location *</label>
                <select required value={formData.from_location_id} onChange={(e) => setFormData((f) => ({ ...f, from_location_id: e.target.value }))}>
                  <option value="">Select source location</option>
                  {locations.map((l) => <option key={locId(l)} value={String(locId(l))}>{l.location_name || l.name}</option>)}
                </select>
              </div>
              <div className="form-group">
                <label>To Location *</label>
                <select required value={formData.to_location_id} onChange={(e) => setFormData((f) => ({ ...f, to_location_id: e.target.value }))}>
                  <option value="">Select destination location</option>
                  {locations.map((l) => <option key={locId(l)} value={String(locId(l))}>{l.location_name || l.name}</option>)}
                </select>
              </div>
              <div className="form-group">
                <label>Transfer Number *</label>
                <input required type="text" value={formData.transfer_number} onChange={(e) => setFormData((f) => ({ ...f, transfer_number: e.target.value }))} placeholder="e.g. TRF-2024-001" />
              </div>
              <div className="form-group">
                <label>Transfer Date *</label>
                <input required type="date" value={formData.transfer_date} onChange={(e) => setFormData((f) => ({ ...f, transfer_date: e.target.value }))} />
              </div>
              <div className="form-group">
                <label>Status</label>
                <select value={formData.status_id} onChange={(e) => setFormData((f) => ({ ...f, status_id: e.target.value }))}>
                  <option value="">Select status</option>
                  {statuses.map((s) => <option key={s.status_id} value={s.status_id}>{s.status_name}</option>)}
                </select>
              </div>
            </div>

            {!editingTransfer && (
              <label style={{ display: 'flex', alignItems: 'flex-start', gap: 10, marginTop: 12, cursor: 'pointer', fontSize: 14, color: '#374151' }}>
                <input type="checkbox" checked={shipFirst} onChange={(e) => setShipFirst(e.target.checked)} style={{ marginTop: 3 }} />
                <span>
                  <strong>Ship first (showroom receives later)</strong>
                  <span style={{ display: 'block', fontSize: 13, color: '#6b7280', marginTop: 4 }}>
                    Warehouse stock is reduced now; destination stock increases only when the showroom scans each item (status &quot;In transit&quot; until fully received).
                  </span>
                </span>
              </label>
            )}

            <div style={{ marginTop: 16 }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
                <h4 style={{ margin: 0, color: '#374151' }}>Items to Transfer</h4>
                <button type="button" className="btn-secondary" style={{ padding: '4px 12px', fontSize: 13 }} onClick={addDetailRow}>+ Add Item</button>
              </div>
              <table className="data-table">
                <thead><tr><th>Product</th><th>Qty to Transfer</th><th></th></tr></thead>
                <tbody>
                  {formData.details.map((d, i) => (
                    <tr key={i}>
                      <td>
                        <select required value={d.product_id} onChange={(e) => updateDetail(i, 'product_id', e.target.value)} style={{ width: '100%' }}>
                          <option value="">Select product</option>
                          {products.map((p) => <option key={p.product_id || p.id} value={p.product_id || p.id}>{p.product_name || p.name}</option>)}
                        </select>
                      </td>
                      <td><input type="number" min="1" required value={d.quantity_transferred} onChange={(e) => updateDetail(i, 'quantity_transferred', e.target.value)} style={{ width: 100 }} /></td>
                      <td>{formData.details.length > 1 && <button type="button" className="btn-action btn-delete" onClick={() => removeDetailRow(i)}>✕</button>}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            <div className="modal-footer">
              <button type="button" className="btn-secondary" onClick={() => setIsModalOpen(false)}>Cancel</button>
              <button type="submit" className="btn-primary" disabled={submitting}>{submitting ? 'Saving...' : editingTransfer ? 'Update' : 'Create'}</button>
            </div>
          </form>
        </Modal>
      )}
    </AdminLayout>
  );
};

export default StockTransferManagement;
