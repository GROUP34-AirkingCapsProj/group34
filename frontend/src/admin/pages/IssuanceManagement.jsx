import React, { useState, useEffect, useCallback, useMemo } from 'react';
import AdminLayout from '../components/AdminLayout';
import Modal from '../components/Modal';
import { issuancesAPI, locationsAPI, productsAPI, statusAPI } from '../services/api';
import { toast } from '../utils/toast';
import { getIssuanceDisplay, issuanceLineQty } from '../utils/moduleFeedStatus';
import '../../admin/styles/dashboard_air.css';
import '../styles/transaction_management.css';

const ISSUANCE_TYPES = ['Internal', 'Maintenance', 'Operations', 'Office Use', 'Other'];
const emptyDetail = () => ({ product_id: '', quantity_issued: 1, condition_issued: 'Good' });
const emptyForm = () => ({
  location_id: '', issuance_date: '', issuance_type: 'Internal',
  purpose: '', expected_return_date: '', status_id: '', details: [emptyDetail()],
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

const IssuanceManagement = () => {
  const [issuances, setIssuances] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [pagination, setPagination] = useState({ total: 0, last_page: 1 });
  const [searchTerm, setSearchTerm] = useState('');
  const [filterType, setFilterType] = useState('');
  const [locations, setLocations] = useState([]);
  const [products, setProducts] = useState([]);
  const [statuses, setStatuses] = useState([]);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [viewingIssuance, setViewingIssuance] = useState(null);
  const [editingIssuance, setEditingIssuance] = useState(null);
  const [submitting, setSubmitting] = useState(false);
  const [formData, setFormData] = useState(emptyForm());

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

  const fetchIssuances = useCallback(async () => {
    setLoading(true);
    try {
      const params = { page: 1, per_page: 300 };
      if (filterType) params.issuance_type = filterType;
      const res = await issuancesAPI.getAll(params);
      setIssuances(Array.isArray(res?.data) ? res.data : []);
      setPagination(res?.pagination || res?.meta || { total: 0, last_page: 1 });
      setError(null);
    } catch (e) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  }, [filterType]);

  useEffect(() => { fetchDropdowns(); }, [fetchDropdowns]);
  useEffect(() => { fetchIssuances(); }, [fetchIssuances]);

  const thisMonthCount = useMemo(() => {
    const n = new Date();
    return issuances.filter((iss) => {
      if (!iss.issuance_date) return false;
      const d = new Date(iss.issuance_date);
      return d.getMonth() === n.getMonth() && d.getFullYear() === n.getFullYear();
    }).length;
  }, [issuances]);

  const filteredIssuances = useMemo(() => {
    const q = searchTerm.trim().toLowerCase();
    if (!q) return issuances;
    return issuances.filter((iss) => {
      const names = (iss.details || []).map((d) => d.product?.product_name).join(' ');
      const blob = `${iss.issuance_number || ''} ${iss.issuance_type} ${iss.purpose || ''} ${iss.location?.location_name || ''} ${names}`.toLowerCase();
      return blob.includes(q);
    });
  }, [issuances, searchTerm]);

  const openAddModal = () => { setEditingIssuance(null); setFormData(emptyForm()); setIsModalOpen(true); };
  const openEditModal = (item) => {
    setEditingIssuance(item);
    setFormData({
      location_id: item.location_id || '',
      issuance_date: item.issuance_date ? item.issuance_date.split('T')[0] : '',
      issuance_type: item.issuance_type || 'Internal',
      purpose: item.purpose || '',
      expected_return_date: item.expected_return_date ? item.expected_return_date.split('T')[0] : '',
      status_id: item.status_id || '',
      details: item.details?.length ? item.details.map((d) => ({
        product_id: d.product_id || '', quantity_issued: d.quantity_issued || 1, condition_issued: d.condition_issued || 'Good',
      })) : [emptyDetail()],
    });
    setIsModalOpen(true);
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!formData.details[0]?.product_id) { toast.error('Add at least one product.'); return; }
    setSubmitting(true);
    try {
      const payload = { ...formData, details: formData.details.map((d) => ({ ...d, quantity_issued: parseInt(d.quantity_issued, 10) || 1 })) };
      if (editingIssuance) {
        await issuancesAPI.update(editingIssuance.issuance_id, payload);
        toast.success('Issuance updated.');
      } else {
        await issuancesAPI.create(payload);
        toast.success('Issuance created. Stock deducted.');
      }
      setIsModalOpen(false);
      fetchIssuances();
    } catch (err) { toast.error(err.message || 'Failed to save.'); } finally { setSubmitting(false); }
  };

  const handleDelete = async (id) => {
    if (!window.confirm('Delete this issuance record?')) return;
    try {
      await issuancesAPI.delete(id);
      toast.success('Deleted.');
      fetchIssuances();
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
            <h1>Issuance for Internal Use</h1>
            <p>Issue stock for internal use — Transactions-style list (empty until you add records)</p>
          </div>
          <button type="button" className="txn-btn-create" onClick={openAddModal}>+ New Issuance</button>
        </div>

        <div className="txn-stats-row">
          {[
            { label: 'Total issuances', value: pagination.total || issuances.length, cls: 'txn-stat-icon-blue' },
            { label: 'Internal', value: issuances.filter((i) => i.issuance_type === 'Internal').length, cls: 'txn-stat-icon-purple' },
            { label: 'Maintenance / Ops', value: issuances.filter((i) => ['Maintenance', 'Operations'].includes(i.issuance_type)).length, cls: 'txn-stat-icon-orange' },
            { label: 'This month', value: thisMonthCount, cls: 'txn-stat-icon-green' },
          ].map((s) => (
            <div key={s.label} className="txn-stat-card">
              <div className={`txn-stat-icon ${s.cls}`}>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="22" height="22">
                  <polyline points="9 11 12 14 22 4" />
                  <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11" />
                </svg>
              </div>
              <div className="txn-stat-info">
                <span className="txn-stat-label">{s.label}</span>
                <span className="txn-stat-number">{s.value}</span>
              </div>
            </div>
          ))}
        </div>

        {error && (
          <div className="txn-error">
            {error}
            <button type="button" className="txn-btn-reset" style={{ marginLeft: 12 }} onClick={() => fetchIssuances()}>Retry</button>
          </div>
        )}

        <div className="txn-table-card">
          <div className="txn-table-top">
            <div className="txn-table-title">
              <h3>All issuances</h3>
              <p>{filteredIssuances.length} shown</p>
            </div>
          </div>

          <div className="txn-filter-row">
            <div className="txn-search-input">
              <input
                type="text"
                placeholder="Search issuance #, type, purpose, product..."
                value={searchTerm}
                onChange={(e) => setSearchTerm(e.target.value)}
              />
            </div>
            <div className="txn-filter-group">
              <select value={filterType} onChange={(e) => setFilterType(e.target.value)}>
                <option value="">All types</option>
                {ISSUANCE_TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
              </select>
              <button type="button" className="txn-btn-reset" onClick={() => { setSearchTerm(''); setFilterType(''); }}>Reset</button>
            </div>
          </div>

          <div className="txn-feed">
            {loading ? (
              <div className="txn-empty">Loading issuances...</div>
            ) : filteredIssuances.length === 0 ? (
              <div className="txn-empty">
                No issuance records yet — same clean view as Transactions. Use <strong>+ New Issuance</strong> to add one.
              </div>
            ) : (
              filteredIssuances.map((iss) => {
                const st = getIssuanceDisplay(iss);
                const qty = issuanceLineQty(iss);
                const first = iss.details?.[0]?.product;
                const title = first?.product_name || (iss.details?.length > 1 ? `${iss.details.length} products` : 'Internal issuance');
                const ref = iss.issuance_number || `ISS-${iss.issuance_id}`;
                return (
                  <article className="txn-feed-item" key={iss.issuance_id}>
                    <div className="txn-feed-left">
                      <div className="txn-feed-title-row">
                        <span className="txn-type-badge txn-type-issuance">Issuance</span>
                        <h4>{title}</h4>
                      </div>
                      <p className="txn-feed-meta">
                        {iss.issuance_type}
                        {' · '}
                        {iss.location?.location_name || '—'}
                        {' • '}
                        {formatDateTime(iss.issuance_date || iss.created_at)}
                      </p>
                      <p className="txn-feed-ref">Ref: {ref}</p>
                      <div className="txn-feed-actions">
                        <button type="button" className="txn-btn-view" onClick={() => setViewingIssuance(iss)}>View</button>
                        <button type="button" className="txn-btn-edit" onClick={() => openEditModal(iss)}>Edit</button>
                        <button type="button" className="txn-btn-delete" onClick={() => handleDelete(iss.issuance_id)}>Delete</button>
                      </div>
                    </div>
                    <div className="txn-feed-right">
                      <span className={`txn-status-badge ${st.badgeClass}`}>{st.label}</span>
                      <span className="txn-feed-qty">{qty.toLocaleString()} units</span>
                    </div>
                  </article>
                );
              })
            )}
          </div>
        </div>
      </div>

      {viewingIssuance && (
        <Modal title={`Issuance: ${viewingIssuance.issuance_number || `ISS-${viewingIssuance.issuance_id}`}`} onClose={() => setViewingIssuance(null)}>
          <div className="detail-grid">
            <div><span className="detail-label">Status</span><span className="detail-value">{getIssuanceDisplay(viewingIssuance).label}</span></div>
            <div><span className="detail-label">Type</span><span className="detail-value">{viewingIssuance.issuance_type}</span></div>
            <div><span className="detail-label">Location</span><span className="detail-value">{viewingIssuance.location?.location_name || '—'}</span></div>
            <div><span className="detail-label">Date</span><span className="detail-value">{viewingIssuance.issuance_date ? new Date(viewingIssuance.issuance_date).toLocaleDateString() : '—'}</span></div>
            <div><span className="detail-label">Purpose</span><span className="detail-value">{viewingIssuance.purpose || '—'}</span></div>
            <div><span className="detail-label">Expected Return</span><span className="detail-value">{viewingIssuance.expected_return_date ? new Date(viewingIssuance.expected_return_date).toLocaleDateString() : '—'}</span></div>
          </div>
          {viewingIssuance.details?.length > 0 && (
            <div style={{ marginTop: 16 }}>
              <h4 style={{ marginBottom: 8, color: '#374151' }}>Items Issued</h4>
              <table className="data-table">
                <thead><tr><th>Product</th><th>Qty</th><th>Condition</th></tr></thead>
                <tbody>{viewingIssuance.details.map((d, i) => (
                  <tr key={i}><td>{d.product?.product_name || '—'}</td><td>{d.quantity_issued}</td><td>{d.condition_issued || '—'}</td></tr>
                ))}</tbody>
              </table>
            </div>
          )}
          <div className="modal-footer"><button type="button" className="btn-secondary" onClick={() => setViewingIssuance(null)}>Close</button></div>
        </Modal>
      )}

      {isModalOpen && (
        <Modal title={editingIssuance ? 'Edit Issuance' : 'New Issuance'} onClose={() => setIsModalOpen(false)}>
          <form onSubmit={handleSubmit}>
            <div className="form-grid-2">
              <div className="form-group">
                <label>Location *</label>
                <select required value={formData.location_id} onChange={(e) => setFormData((f) => ({ ...f, location_id: e.target.value }))}>
                  <option value="">Select location</option>
                  {locations.map((l) => <option key={locId(l)} value={String(locId(l))}>{l.location_name || l.name}</option>)}
                </select>
              </div>
              <div className="form-group">
                <label>Issuance Date *</label>
                <input required type="date" value={formData.issuance_date} onChange={(e) => setFormData((f) => ({ ...f, issuance_date: e.target.value }))} />
              </div>
              <div className="form-group">
                <label>Type *</label>
                <select required value={formData.issuance_type} onChange={(e) => setFormData((f) => ({ ...f, issuance_type: e.target.value }))}>
                  {ISSUANCE_TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
                </select>
              </div>
              <div className="form-group">
                <label>Expected Return Date</label>
                <input type="date" value={formData.expected_return_date} onChange={(e) => setFormData((f) => ({ ...f, expected_return_date: e.target.value }))} />
              </div>
              <div className="form-group" style={{ gridColumn: '1 / -1' }}>
                <label>Purpose</label>
                <textarea rows="2" value={formData.purpose} onChange={(e) => setFormData((f) => ({ ...f, purpose: e.target.value }))} placeholder="Describe the purpose of this issuance..." style={{ resize: 'vertical' }} />
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
                <h4 style={{ margin: 0, color: '#374151' }}>Items to Issue</h4>
                <button type="button" className="btn-secondary" style={{ padding: '4px 12px', fontSize: 13 }} onClick={addDetailRow}>+ Add Item</button>
              </div>
              <table className="data-table">
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
                      <td><input type="number" min="1" required value={d.quantity_issued} onChange={(e) => updateDetail(i, 'quantity_issued', e.target.value)} style={{ width: 80 }} /></td>
                      <td>
                        <select value={d.condition_issued} onChange={(e) => updateDetail(i, 'condition_issued', e.target.value)}>
                          {['Good', 'Fair', 'Damaged'].map((c) => <option key={c} value={c}>{c}</option>)}
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
              <button type="submit" className="btn-primary" disabled={submitting}>{submitting ? 'Saving...' : editingIssuance ? 'Update' : 'Create'}</button>
            </div>
          </form>
        </Modal>
      )}
    </AdminLayout>
  );
};

export default IssuanceManagement;
