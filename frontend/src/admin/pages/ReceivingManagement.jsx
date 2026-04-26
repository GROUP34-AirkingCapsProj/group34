import React, { useState, useEffect, useCallback, useMemo } from 'react';
import AdminLayout from '../components/AdminLayout';
import Modal from '../components/Modal';
import { receivingsAPI, purchaseOrdersAPI, locationsAPI, productsAPI, statusAPI } from '../services/api';
import { toast } from '../utils/toast';
import { getReceivingWorkflow, receivingReceivedQty } from '../utils/moduleFeedStatus';
import '../../admin/styles/dashboard_air.css';
import '../styles/transaction_management.css';

const CONDITIONS = ['Good', 'Damaged', 'Expired', 'Defective'];
const emptyDetail = () => ({ product_id: '', quantity_amount: 1, condition: 'Good' });
const emptyForm = () => ({
  pc_id: '', location_id: '', receiving_number: '', receiving_date: '',
  total_quantity_damaged: 0, status_id: '', details: [emptyDetail()],
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

const ReceivingManagement = () => {
  const [receivings, setReceivings] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [pagination, setPagination] = useState({ total: 0, last_page: 1 });
  const [searchTerm, setSearchTerm] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [purchaseOrders, setPurchaseOrders] = useState([]);
  const [locations, setLocations] = useState([]);
  const [products, setProducts] = useState([]);
  const [statuses, setStatuses] = useState([]);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [viewingReceiving, setViewingReceiving] = useState(null);
  const [editingReceiving, setEditingReceiving] = useState(null);
  const [submitting, setSubmitting] = useState(false);
  const [formData, setFormData] = useState(emptyForm());

  const fetchDropdowns = useCallback(async () => {
    try {
      const [poRes, locRes, prodRes, statRes] = await Promise.all([
        purchaseOrdersAPI.getAll({ per_page: 500 }),
        locationsAPI.getAll(),
        productsAPI.getAll({ per_page: 500 }),
        statusAPI.getAll(),
      ]);
      setPurchaseOrders(Array.isArray(poRes?.data) ? poRes.data : []);
      setLocations(Array.isArray(locRes?.data) ? locRes.data : []);
      setProducts(Array.isArray(prodRes?.data) ? prodRes.data : []);
      setStatuses(Array.isArray(statRes?.data) ? statRes.data : []);
    } catch (e) { console.error('Dropdown fetch error:', e); }
  }, []);

  const fetchReceivings = useCallback(async () => {
    setLoading(true);
    try {
      const res = await receivingsAPI.getAll({ page: 1, per_page: 300 });
      setReceivings(Array.isArray(res?.data) ? res.data : []);
      setPagination(res?.pagination || res?.meta || { total: 0, last_page: 1 });
      setError(null);
    } catch (e) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { fetchDropdowns(); }, [fetchDropdowns]);
  useEffect(() => { fetchReceivings(); }, [fetchReceivings]);

  const workflowCounts = useMemo(() => {
    let tbr = 0;
    let ongoing = 0;
    let completed = 0;
    receivings.forEach((r) => {
      const w = getReceivingWorkflow(r);
      if (w.key === 'tbr') tbr += 1;
      else if (w.key === 'ongoing') ongoing += 1;
      else completed += 1;
    });
    return { tbr, ongoing, completed };
  }, [receivings]);

  const totalQtyLoaded = useMemo(
    () => receivings.reduce((s, r) => s + receivingReceivedQty(r), 0),
    [receivings],
  );

  const filteredReceivings = useMemo(() => {
    const q = searchTerm.trim().toLowerCase();
    let rows = receivings;
    if (q) {
      rows = rows.filter((r) => {
        const names = (r.details || []).map((d) => d.product?.product_name).join(' ');
        const blob = `${r.receiving_number || ''} ${r.purchase_order?.po_number || ''} ${r.location?.location_name || ''} ${names}`.toLowerCase();
        return blob.includes(q);
      });
    }
    if (statusFilter) {
      rows = rows.filter((r) => getReceivingWorkflow(r).key === statusFilter);
    }
    return rows;
  }, [receivings, searchTerm, statusFilter]);

  const openAddModal = () => { setEditingReceiving(null); setFormData(emptyForm()); setIsModalOpen(true); };
  const openEditModal = (r) => {
    setEditingReceiving(r);
    setFormData({
      pc_id: r.pc_id || r.purchase_order?.po_id || '',
      location_id: r.location_id || '',
      receiving_number: r.receiving_number || '',
      receiving_date: r.receiving_date ? r.receiving_date.split('T')[0] : '',
      total_quantity_damaged: r.total_quantity_damaged || 0,
      status_id: r.status_id || '',
      details: r.details?.length ? r.details.map((d) => ({
        product_id: d.product_id || '', quantity_amount: d.quantity_amount || 1, condition: d.condition || 'Good',
      })) : [emptyDetail()],
    });
    setIsModalOpen(true);
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!formData.details[0]?.product_id) { toast.error('Add at least one product.'); return; }
    setSubmitting(true);
    try {
      const payload = { ...formData, details: formData.details.map((d) => ({ ...d, quantity_amount: parseInt(d.quantity_amount, 10) || 0 })) };
      if (editingReceiving) {
        await receivingsAPI.update(editingReceiving.receiving_id, payload);
        toast.success('Receiving record updated.');
      } else {
        await receivingsAPI.create(payload);
        toast.success('Receiving record created. Inventory updated.');
      }
      setIsModalOpen(false);
      fetchReceivings();
    } catch (err) { toast.error(err.message || 'Failed to save.'); } finally { setSubmitting(false); }
  };

  const handleDelete = async (id) => {
    if (!window.confirm('Delete this receiving record?')) return;
    try {
      await receivingsAPI.delete(id);
      toast.success('Deleted.');
      fetchReceivings();
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
            <h1>Receiving of Items</h1>
            <p>Record goods received from purchase orders — same layout as Transactions</p>
          </div>
          <button type="button" className="txn-btn-create" onClick={openAddModal}>+ New Receiving</button>
        </div>

        <div className="txn-stats-row">
          {[
            { label: 'Total receivings', value: pagination.total || receivings.length, cls: 'txn-stat-icon-blue' },
            { label: 'To be received', value: workflowCounts.tbr, cls: 'txn-stat-icon-purple' },
            { label: 'On going', value: workflowCounts.ongoing, cls: 'txn-stat-icon-orange' },
            { label: 'Completed', value: workflowCounts.completed, cls: 'txn-stat-icon-green' },
          ].map((s) => (
            <div key={s.label} className="txn-stat-card">
              <div className={`txn-stat-icon ${s.cls}`}>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="22" height="22">
                  <path d="M5 12h14" /><path d="m12 5 7 7-7 7" />
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
          Total qty (loaded): <strong>{totalQtyLoaded}</strong>
          {(pagination.total > 300) && ' — showing latest 300 records; refine search if needed.'}
        </p>

        {error && (
          <div className="txn-error">
            {error}
            <button type="button" className="txn-btn-reset" style={{ marginLeft: 12 }} onClick={() => fetchReceivings()}>Retry</button>
          </div>
        )}

        <div className="txn-table-card">
          <div className="txn-table-top">
            <div className="txn-table-title">
              <h3>All receivings</h3>
              <p>{filteredReceivings.length} shown</p>
            </div>
          </div>

          <div className="txn-filter-row">
            <div className="txn-search-input">
              <input
                type="text"
                placeholder="Search receiving #, PO, location, product..."
                value={searchTerm}
                onChange={(e) => setSearchTerm(e.target.value)}
              />
            </div>
            <div className="txn-filter-group">
              <select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
                <option value="">All status</option>
                <option value="tbr">To be received</option>
                <option value="ongoing">On going</option>
                <option value="completed">Completed</option>
              </select>
              <button type="button" className="txn-btn-reset" onClick={() => { setSearchTerm(''); setStatusFilter(''); }}>Reset</button>
            </div>
          </div>

          <div className="txn-feed">
            {loading ? (
              <div className="txn-empty">Loading receivings...</div>
            ) : filteredReceivings.length === 0 ? (
              <div className="txn-empty">No receiving records match your filters.</div>
            ) : (
              filteredReceivings.map((r) => {
                const wf = getReceivingWorkflow(r);
                const qty = receivingReceivedQty(r);
                const first = r.details?.[0]?.product;
                const title = first?.product_name
                  || (r.details?.length > 1 ? `${r.details.length} products` : 'Stock receiving');
                const loc = r.location?.location_name || r.location?.name || '—';
                const po = r.purchase_order?.po_number || '—';
                return (
                  <article className="txn-feed-item" key={r.receiving_id}>
                    <div className="txn-feed-left">
                      <div className="txn-feed-title-row">
                        <span className="txn-type-badge txn-type-receiving">Receiving</span>
                        <h4>{title}</h4>
                      </div>
                      <p className="txn-feed-meta">
                        {loc}
                        {' • '}
                        {formatDateTime(r.receiving_date || r.created_at)}
                      </p>
                      <p className="txn-feed-ref">PO: {po} · Ref: {r.receiving_number || r.receiving_id}</p>
                      <div className="txn-feed-actions">
                        <button type="button" className="txn-btn-view" onClick={() => setViewingReceiving(r)}>View</button>
                        <button type="button" className="txn-btn-edit" onClick={() => openEditModal(r)}>Edit</button>
                        <button type="button" className="txn-btn-delete" onClick={() => handleDelete(r.receiving_id)}>Delete</button>
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

      {viewingReceiving && (
        <Modal title={`Receiving: ${viewingReceiving.receiving_number}`} onClose={() => setViewingReceiving(null)}>
          <div className="detail-grid">
            <div><span className="detail-label">Workflow</span><span className="detail-value">{getReceivingWorkflow(viewingReceiving).label}</span></div>
            <div><span className="detail-label">PO Number</span><span className="detail-value">{viewingReceiving.purchase_order?.po_number || '—'}</span></div>
            <div><span className="detail-label">Location</span><span className="detail-value">{viewingReceiving.location?.location_name || '—'}</span></div>
            <div><span className="detail-label">Receiving Date</span><span className="detail-value">{viewingReceiving.receiving_date ? new Date(viewingReceiving.receiving_date).toLocaleDateString() : '—'}</span></div>
            <div><span className="detail-label">Total Qty Received</span><span className="detail-value">{receivingReceivedQty(viewingReceiving)}</span></div>
            <div><span className="detail-label">Total Qty Damaged</span><span className="detail-value" style={{ color: viewingReceiving.total_quantity_damaged > 0 ? '#ef4444' : undefined }}>{viewingReceiving.total_quantity_damaged ?? 0}</span></div>
            <div><span className="detail-label">DB Status</span><span className="detail-value">{viewingReceiving.status?.status_name || '—'}</span></div>
          </div>
          {viewingReceiving.details?.length > 0 && (
            <div style={{ marginTop: 16 }}>
              <h4 style={{ marginBottom: 8, color: '#374151' }}>Items Received</h4>
              <table className="data-table">
                <thead><tr><th>Product</th><th>Qty</th><th>Condition</th></tr></thead>
                <tbody>{viewingReceiving.details.map((d, i) => (
                  <tr key={i}><td>{d.product?.product_name || '—'}</td><td>{d.quantity_amount}</td><td>{d.condition || '—'}</td></tr>
                ))}</tbody>
              </table>
            </div>
          )}
          <div className="modal-footer"><button type="button" className="btn-secondary" onClick={() => setViewingReceiving(null)}>Close</button></div>
        </Modal>
      )}

      {isModalOpen && (
        <Modal title={editingReceiving ? 'Edit Receiving' : 'New Receiving'} onClose={() => setIsModalOpen(false)}>
          <form onSubmit={handleSubmit}>
            <div className="form-grid-2">
              <div className="form-group">
                <label>Purchase Order *</label>
                <select required value={formData.pc_id} onChange={(e) => setFormData((f) => ({ ...f, pc_id: e.target.value }))}>
                  <option value="">Select PO</option>
                  {purchaseOrders.map((po) => <option key={po.po_id} value={po.po_id}>{po.po_number}</option>)}
                </select>
              </div>
              <div className="form-group">
                <label>Location *</label>
                <select required value={formData.location_id} onChange={(e) => setFormData((f) => ({ ...f, location_id: e.target.value }))}>
                  <option value="">Select location</option>
                  {locations.map((l) => <option key={locId(l)} value={String(locId(l))}>{l.location_name || l.name}</option>)}
                </select>
              </div>
              <div className="form-group">
                <label>Receiving Number *</label>
                <input required type="text" value={formData.receiving_number} onChange={(e) => setFormData((f) => ({ ...f, receiving_number: e.target.value }))} placeholder="e.g. RCV-2024-001" />
              </div>
              <div className="form-group">
                <label>Receiving Date *</label>
                <input required type="date" value={formData.receiving_date} onChange={(e) => setFormData((f) => ({ ...f, receiving_date: e.target.value }))} />
              </div>
              <div className="form-group">
                <label>Damaged Qty</label>
                <input type="number" min="0" value={formData.total_quantity_damaged} onChange={(e) => setFormData((f) => ({ ...f, total_quantity_damaged: parseInt(e.target.value, 10) || 0 }))} />
              </div>
              <div className="form-group">
                <label>Status</label>
                <select value={formData.status_id} onChange={(e) => setFormData((f) => ({ ...f, status_id: e.target.value }))}>
                  <option value="">Select status</option>
                  {statuses.map((s) => <option key={s.status_id} value={s.status_id}>{s.status_name}</option>)}
                </select>
              </div>
            </div>

            <div style={{ marginTop: 16 }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
                <h4 style={{ margin: 0, color: '#374151' }}>Items Received</h4>
                <button type="button" className="btn-secondary" style={{ padding: '4px 12px', fontSize: 13 }} onClick={addDetailRow}>+ Add Item</button>
              </div>
              <table className="data-table" style={{ marginBottom: 8 }}>
                <thead><tr><th>Product</th><th>Qty</th><th>Condition</th><th></th></tr></thead>
                <tbody>
                  {formData.details.map((d, i) => (
                    <tr key={i}>
                      <td>
                        <select required value={d.product_id} onChange={(e) => updateDetail(i, 'product_id', e.target.value)} style={{ width: '100%' }}>
                          <option value="">Select product</option>
                          {products.map((p) => <option key={p.product_id || p.id} value={p.product_id || p.id}>{p.product_name || p.name}</option>)}
                        </select>
                      </td>
                      <td><input type="number" min="0" required value={d.quantity_amount} onChange={(e) => updateDetail(i, 'quantity_amount', e.target.value)} style={{ width: 80 }} /></td>
                      <td>
                        <select value={d.condition} onChange={(e) => updateDetail(i, 'condition', e.target.value)}>
                          {CONDITIONS.map((c) => <option key={c} value={c}>{c}</option>)}
                        </select>
                      </td>
                      <td>{formData.details.length > 1 && <button type="button" className="btn-action btn-delete" onClick={() => removeDetailRow(i)}>✕</button>}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            <div className="modal-footer">
              <button type="button" className="btn-secondary" onClick={() => setIsModalOpen(false)}>Cancel</button>
              <button type="submit" className="btn-primary" disabled={submitting}>{submitting ? 'Saving...' : editingReceiving ? 'Update' : 'Create'}</button>
            </div>
          </form>
        </Modal>
      )}
    </AdminLayout>
  );
};

export default ReceivingManagement;
