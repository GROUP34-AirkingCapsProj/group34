import React, { useState, useEffect, useCallback, useRef } from 'react';
import AdminLayout from '../components/AdminLayout';
import Modal from '../components/Modal';
import { profitLossAPI, productsAPI, modelsAPI, statusAPI, usersAPI } from '../services/api';
import { toast } from '../utils/toast';
import '../styles/profit_loss_management.css';

const REFERENCE_TYPES = ['Damage', 'Theft', 'Expiry', 'Lost', 'Write-off', 'Other'];

const emptyForm = () => ({
  model_id: '',
  product_id: '',
  reference_type: 'Damage',
  transaction_date: '',
  incident_date: '',
  serial_number: '',
  quantity_lost: '',
  unit_cost: '',
  total_loss_amount: '',
  recorded_by: '',
  approved_by: '',
  status_id: '',
});

const ProfitLossManagement = () => {
  const [records, setRecords]       = useState([]);
  const [loading, setLoading]       = useState(true);
  const [error, setError]           = useState(null);
  const [currentPage, setCurrentPage] = useState(1);
  const [pagination, setPagination] = useState({ total: 0, last_page: 1 });
  const [searchTerm, setSearchTerm] = useState('');
  const [filterType, setFilterType] = useState('');

  const [products, setProducts]     = useState([]);
  const [models, setModels]         = useState([]);
  const [statuses, setStatuses]     = useState([]);
  const [users, setUsers]           = useState([]);

  const [isModalOpen, setIsModalOpen]   = useState(false);
  const [viewingRecord, setViewingRecord] = useState(null);
  const [editingRecord, setEditingRecord] = useState(null);
  const [submitting, setSubmitting]     = useState(false);
  const [formData, setFormData]         = useState(emptyForm());

  const debounceRef = useRef(null);

  /* ── Dropdowns ─────────────────────────────────────────── */
  const fetchDropdowns = useCallback(async () => {
    try {
      const [prodRes, modRes, statRes, userRes] = await Promise.all([
        productsAPI.getAll({ per_page: 500 }),
        modelsAPI.getAll(),
        statusAPI.getAll(),
        usersAPI.getAll({ per_page: 200 }),
      ]);
      setProducts(Array.isArray(prodRes?.data) ? prodRes.data : []);
      setModels(Array.isArray(modRes?.data) ? modRes.data : modRes?.data ? [modRes.data] : []);
      setStatuses(Array.isArray(statRes?.data) ? statRes.data : []);
      setUsers(Array.isArray(userRes?.data) ? userRes.data : []);
    } catch (e) { console.error('Dropdowns failed:', e); }
  }, []);

  /* ── Records ───────────────────────────────────────────── */
  const fetchRecords = useCallback(async (page = 1) => {
    setLoading(true);
    try {
      const params = { page, per_page: 10 };
      if (searchTerm) params.search = searchTerm;
      if (filterType) params.reference_type = filterType;
      const res = await profitLossAPI.getAll(params);
      setRecords(Array.isArray(res?.data) ? res.data : []);
      setPagination(res?.pagination || res?.meta || { total: 0, last_page: 1 });
      setError(null);
    } catch (e) { setError(e.message); }
    finally { setLoading(false); }
  }, [searchTerm, filterType]);

  useEffect(() => { fetchDropdowns(); }, [fetchDropdowns]);
  useEffect(() => { fetchRecords(currentPage); }, [currentPage, fetchRecords]);
  useEffect(() => {
    if (debounceRef.current) clearTimeout(debounceRef.current);
    debounceRef.current = setTimeout(() => setCurrentPage(1), 400);
    return () => clearTimeout(debounceRef.current);
  }, [searchTerm, filterType]);

  /* ── Helpers ────────────────────────────────────────────── */
  const productName = (id) => products.find(p => p.product_id === id)?.product_name || id || '—';
  const modelCode   = (id) => models.find(m => m.model_id === id)?.model_code || '—';
  const statusName  = (id) => statuses.find(s => s.status_id === id)?.status_name || '—';
  const userName    = (id) => {
    const u = users.find(u => u.user_id === id);
    return u ? `${u.first_name ?? ''} ${u.last_name ?? ''}`.trim() || u.username : '—';
  };
  const fmtDate = (d) => d ? new Date(d).toLocaleDateString() : '—';
  const fmtCurrency = (v) => v != null ? `₱${parseFloat(v).toLocaleString('en-PH', { minimumFractionDigits: 2 })}` : '—';

  /* ── Auto-compute total_loss_amount ─────────────────────── */
  const handleFormChange = (field, value) => {
    setFormData(prev => {
      const next = { ...prev, [field]: value };
      if (field === 'quantity_lost' || field === 'unit_cost') {
        const qty  = parseFloat(field === 'quantity_lost' ? value : next.quantity_lost) || 0;
        const cost = parseFloat(field === 'unit_cost'     ? value : next.unit_cost)     || 0;
        next.total_loss_amount = qty > 0 && cost > 0 ? (qty * cost).toFixed(2) : next.total_loss_amount;
      }
      return next;
    });
  };

  /* ── Modal open/close ───────────────────────────────────── */
  const openAdd = () => {
    setEditingRecord(null);
    setFormData(emptyForm());
    setIsModalOpen(true);
  };

  const openEdit = (rec) => {
    setEditingRecord(rec);
    setFormData({
      model_id:           rec.model_id         || '',
      product_id:         rec.product_id        || '',
      reference_type:     rec.reference_type    || 'Damage',
      transaction_date:   rec.transaction_date  ? rec.transaction_date.split('T')[0] : '',
      incident_date:      rec.incident_date     ? rec.incident_date.split('T')[0]    : '',
      serial_number:      rec.serial_number     || '',
      quantity_lost:      rec.quantity_lost     ?? '',
      unit_cost:          rec.unit_cost         ?? '',
      total_loss_amount:  rec.total_loss_amount ?? '',
      recorded_by:        rec.recorded_by       || '',
      approved_by:        rec.approved_by       || '',
      status_id:          rec.status_id         || '',
    });
    setIsModalOpen(true);
  };

  const closeModal = () => { setIsModalOpen(false); setEditingRecord(null); };

  /* ── Submit ─────────────────────────────────────────────── */
  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!formData.product_id || !formData.transaction_date || !formData.quantity_lost) {
      toast.error('Product, Transaction Date, and Quantity Lost are required.');
      return;
    }
    setSubmitting(true);
    try {
      const payload = {
        ...formData,
        model_id:          formData.model_id          || null,
        recorded_by:       formData.recorded_by       || null,
        approved_by:       formData.approved_by       || null,
        status_id:         formData.status_id         || null,
        quantity_lost:     parseInt(formData.quantity_lost, 10),
        unit_cost:         formData.unit_cost         ? parseFloat(formData.unit_cost) : null,
        total_loss_amount: formData.total_loss_amount ? parseFloat(formData.total_loss_amount) : null,
        incident_date:     formData.incident_date     || null,
        serial_number:     formData.serial_number     || null,
      };
      if (editingRecord) {
        await profitLossAPI.update(editingRecord.profit_loss_id, payload);
        toast.success('Record updated.');
      } else {
        await profitLossAPI.create(payload);
        toast.success('Record created.');
      }
      closeModal();
      fetchRecords(currentPage);
    } catch (err) {
      toast.error(err.message || 'Save failed.');
    } finally { setSubmitting(false); }
  };

  /* ── Delete ─────────────────────────────────────────────── */
  const handleDelete = async (id) => {
    if (!window.confirm('Delete this profit/loss record?')) return;
    try {
      await profitLossAPI.delete(id);
      toast.success('Record deleted.');
      fetchRecords(currentPage);
    } catch (err) { toast.error(err.message || 'Delete failed.'); }
  };

  /* ── Totals (current page) ──────────────────────────────── */
  const totalLoss = records.reduce((sum, r) => sum + (parseFloat(r.total_loss_amount) || 0), 0);

  /* ── Render ─────────────────────────────────────────────── */
  return (
    <AdminLayout>
      <div className="plm-page">

        {/* Header */}
        <div className="plm-header">
          <div>
            <h1 className="plm-title">Profit / Loss Records</h1>
            <p className="plm-subtitle">Track inventory losses — damage, theft, expiry, write-offs</p>
          </div>
          <button className="plm-btn-add" onClick={openAdd}>+ New Record</button>
        </div>

        {/* Stats */}
        <div className="plm-stats-row">
          <div className="plm-stat-card">
            <span className="plm-stat-label">Total Records</span>
            <span className="plm-stat-value">{pagination.total ?? records.length}</span>
          </div>
          <div className="plm-stat-card plm-stat-loss">
            <span className="plm-stat-label">Page Total Loss</span>
            <span className="plm-stat-value">{fmtCurrency(totalLoss)}</span>
          </div>
          {REFERENCE_TYPES.map(t => (
            <div
              key={t}
              className={`plm-stat-card plm-stat-type${filterType === t ? ' active' : ''}`}
              onClick={() => setFilterType(prev => prev === t ? '' : t)}
              title={`Filter by ${t}`}
            >
              <span className="plm-stat-label">{t}</span>
              <span className="plm-stat-value">{records.filter(r => r.reference_type === t).length}</span>
            </div>
          ))}
        </div>

        {/* Filters */}
        <div className="plm-filters">
          <input
            className="plm-search"
            type="text"
            placeholder="Search serial number, reference type..."
            value={searchTerm}
            onChange={e => setSearchTerm(e.target.value)}
          />
          <select
            className="plm-select"
            value={filterType}
            onChange={e => setFilterType(e.target.value)}
          >
            <option value="">All Types</option>
            {REFERENCE_TYPES.map(t => <option key={t} value={t}>{t}</option>)}
          </select>
          {(searchTerm || filterType) && (
            <button className="plm-btn-clear" onClick={() => { setSearchTerm(''); setFilterType(''); }}>
              Clear
            </button>
          )}
        </div>

        {/* Table */}
        {loading ? (
          <div className="plm-loading">Loading records…</div>
        ) : error ? (
          <div className="plm-error">Error: {error}</div>
        ) : (
          <div className="plm-table-wrapper">
            <table className="plm-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Product</th>
                  <th>Model</th>
                  <th>Type</th>
                  <th>Serial No.</th>
                  <th>Qty Lost</th>
                  <th>Unit Cost</th>
                  <th>Total Loss</th>
                  <th>Txn Date</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {records.length === 0 ? (
                  <tr><td colSpan={11} className="plm-empty">No records found.</td></tr>
                ) : records.map((r, i) => (
                  <tr key={r.profit_loss_id}>
                    <td>{(currentPage - 1) * 10 + i + 1}</td>
                    <td>{r.product?.product_name || productName(r.product_id)}</td>
                    <td>{r.model?.model_code || modelCode(r.model_id)}</td>
                    <td>
                      <span className={`plm-type-badge plm-type-${(r.reference_type || '').toLowerCase().replace(/[^a-z]/g, '')}`}>
                        {r.reference_type || '—'}
                      </span>
                    </td>
                    <td>{r.serial_number || '—'}</td>
                    <td className="plm-num">{r.quantity_lost}</td>
                    <td className="plm-num">{fmtCurrency(r.unit_cost)}</td>
                    <td className="plm-num plm-loss-amt">{fmtCurrency(r.total_loss_amount)}</td>
                    <td>{fmtDate(r.transaction_date)}</td>
                    <td>
                      <span className="plm-status-badge">
                        {r.status?.status_name || statusName(r.status_id)}
                      </span>
                    </td>
                    <td>
                      <div className="plm-actions">
                        <button className="plm-btn-view"  onClick={() => setViewingRecord(r)}>View</button>
                        <button className="plm-btn-edit"  onClick={() => openEdit(r)}>Edit</button>
                        <button className="plm-btn-delete" onClick={() => handleDelete(r.profit_loss_id)}>Delete</button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {/* Pagination */}
        {pagination.last_page > 1 && (
          <div className="plm-pagination">
            <button disabled={currentPage === 1} onClick={() => setCurrentPage(p => p - 1)}>‹ Prev</button>
            <span>Page {currentPage} of {pagination.last_page}</span>
            <button disabled={currentPage === pagination.last_page} onClick={() => setCurrentPage(p => p + 1)}>Next ›</button>
          </div>
        )}

        {/* View Modal */}
        {viewingRecord && (
          <Modal isOpen onClose={() => setViewingRecord(null)} title="Profit / Loss Detail">
            <div className="plm-view-grid">
              <div className="plm-view-row"><span>Product</span><strong>{viewingRecord.product?.product_name || productName(viewingRecord.product_id)}</strong></div>
              <div className="plm-view-row"><span>Model</span><strong>{viewingRecord.model?.model_code || modelCode(viewingRecord.model_id)}</strong></div>
              <div className="plm-view-row"><span>Reference Type</span><strong>{viewingRecord.reference_type}</strong></div>
              <div className="plm-view-row"><span>Serial Number</span><strong>{viewingRecord.serial_number || '—'}</strong></div>
              <div className="plm-view-row"><span>Quantity Lost</span><strong>{viewingRecord.quantity_lost}</strong></div>
              <div className="plm-view-row"><span>Unit Cost</span><strong>{fmtCurrency(viewingRecord.unit_cost)}</strong></div>
              <div className="plm-view-row"><span>Total Loss Amount</span><strong className="plm-loss-amt">{fmtCurrency(viewingRecord.total_loss_amount)}</strong></div>
              <div className="plm-view-row"><span>Transaction Date</span><strong>{fmtDate(viewingRecord.transaction_date)}</strong></div>
              <div className="plm-view-row"><span>Incident Date</span><strong>{fmtDate(viewingRecord.incident_date)}</strong></div>
              <div className="plm-view-row"><span>Recorded By</span><strong>{viewingRecord.recordedBy ? `${viewingRecord.recordedBy.first_name} ${viewingRecord.recordedBy.last_name}` : userName(viewingRecord.recorded_by)}</strong></div>
              <div className="plm-view-row"><span>Approved By</span><strong>{viewingRecord.approvedBy ? `${viewingRecord.approvedBy.first_name} ${viewingRecord.approvedBy.last_name}` : userName(viewingRecord.approved_by)}</strong></div>
              <div className="plm-view-row"><span>Status</span><strong>{viewingRecord.status?.status_name || statusName(viewingRecord.status_id)}</strong></div>
            </div>
            <div className="plm-modal-footer">
              <button className="plm-btn-secondary" onClick={() => setViewingRecord(null)}>Close</button>
            </div>
          </Modal>
        )}

        {/* Add / Edit Modal */}
        <Modal isOpen={isModalOpen} onClose={closeModal} title={editingRecord ? 'Edit Record' : 'New Profit/Loss Record'}>
          <form onSubmit={handleSubmit} className="plm-form">
            <div className="plm-form-grid">
              <div className="plm-form-group">
                <label>Product *</label>
                <select value={formData.product_id} onChange={e => handleFormChange('product_id', e.target.value)} required>
                  <option value="">— Select Product —</option>
                  {products.map(p => <option key={p.product_id} value={p.product_id}>{p.product_name}</option>)}
                </select>
              </div>
              <div className="plm-form-group">
                <label>Model</label>
                <select value={formData.model_id} onChange={e => handleFormChange('model_id', e.target.value)}>
                  <option value="">— Select Model —</option>
                  {models.map(m => <option key={m.model_id} value={m.model_id}>{m.model_code}{m.variant ? ` (${m.variant})` : ''}</option>)}
                </select>
              </div>
              <div className="plm-form-group">
                <label>Reference Type *</label>
                <select value={formData.reference_type} onChange={e => handleFormChange('reference_type', e.target.value)} required>
                  {REFERENCE_TYPES.map(t => <option key={t} value={t}>{t}</option>)}
                </select>
              </div>
              <div className="plm-form-group">
                <label>Serial Number</label>
                <input type="text" value={formData.serial_number} onChange={e => handleFormChange('serial_number', e.target.value)} placeholder="e.g. SN-001234" />
              </div>
              <div className="plm-form-group">
                <label>Transaction Date *</label>
                <input type="date" value={formData.transaction_date} onChange={e => handleFormChange('transaction_date', e.target.value)} required />
              </div>
              <div className="plm-form-group">
                <label>Incident Date</label>
                <input type="date" value={formData.incident_date} onChange={e => handleFormChange('incident_date', e.target.value)} />
              </div>
              <div className="plm-form-group">
                <label>Quantity Lost *</label>
                <input type="number" min="1" value={formData.quantity_lost} onChange={e => handleFormChange('quantity_lost', e.target.value)} required />
              </div>
              <div className="plm-form-group">
                <label>Unit Cost</label>
                <input type="number" min="0" step="0.01" value={formData.unit_cost} onChange={e => handleFormChange('unit_cost', e.target.value)} placeholder="0.00" />
              </div>
              <div className="plm-form-group plm-form-group-full">
                <label>Total Loss Amount</label>
                <input type="number" min="0" step="0.01" value={formData.total_loss_amount} onChange={e => handleFormChange('total_loss_amount', e.target.value)} placeholder="Auto-computed from Qty × Unit Cost" />
              </div>
              <div className="plm-form-group">
                <label>Recorded By</label>
                <select value={formData.recorded_by} onChange={e => handleFormChange('recorded_by', e.target.value)}>
                  <option value="">— Select User —</option>
                  {users.map(u => <option key={u.user_id} value={u.user_id}>{u.first_name} {u.last_name} ({u.username})</option>)}
                </select>
              </div>
              <div className="plm-form-group">
                <label>Approved By</label>
                <select value={formData.approved_by} onChange={e => handleFormChange('approved_by', e.target.value)}>
                  <option value="">— Select User —</option>
                  {users.map(u => <option key={u.user_id} value={u.user_id}>{u.first_name} {u.last_name} ({u.username})</option>)}
                </select>
              </div>
              <div className="plm-form-group">
                <label>Status</label>
                <select value={formData.status_id} onChange={e => handleFormChange('status_id', e.target.value)}>
                  <option value="">— Select Status —</option>
                  {statuses.map(s => <option key={s.status_id} value={s.status_id}>{s.status_name}</option>)}
                </select>
              </div>
            </div>
            <div className="plm-modal-footer">
              <button type="button" className="plm-btn-secondary" onClick={closeModal}>Cancel</button>
              <button type="submit" className="plm-btn-primary" disabled={submitting}>
                {submitting ? 'Saving…' : editingRecord ? 'Update' : 'Create Record'}
              </button>
            </div>
          </form>
        </Modal>

      </div>
    </AdminLayout>
  );
};

export default ProfitLossManagement;
