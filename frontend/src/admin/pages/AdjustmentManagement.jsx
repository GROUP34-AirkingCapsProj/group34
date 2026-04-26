import React, { useState, useEffect, useCallback, useRef } from 'react';
import AdminLayout from '../components/AdminLayout';
import Modal from '../components/Modal';
import { adjustmentsAPI, locationsAPI, productsAPI, statusAPI } from '../services/api';
import { toast } from '../utils/toast';
import '../../admin/styles/dashboard_air.css';

const ADJUSTMENT_TYPES = ['Stock Count', 'Damage Write-off', 'Theft Loss', 'Found Stock', 'Expiry', 'Other'];
const emptyDetail = () => ({ product_id: '', add_quantity: 0, deduct_quantity: 0 });
const emptyForm = () => ({
  location_id: '', adjustment_number: '', date: '', adjustment_type: 'Stock Count',
  adjusted_by: '', status_id: '', details: [emptyDetail()],
});

const AdjustmentManagement = () => {
  const [adjustments, setAdjustments] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [currentPage, setCurrentPage] = useState(1);
  const [pagination, setPagination] = useState({ total: 0, last_page: 1 });
  const [searchTerm, setSearchTerm] = useState('');
  const [locations, setLocations] = useState([]);
  const [products, setProducts] = useState([]);
  const [statuses, setStatuses] = useState([]);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [viewingAdj, setViewingAdj] = useState(null);
  const [editingAdj, setEditingAdj] = useState(null);
  const [submitting, setSubmitting] = useState(false);
  const [formData, setFormData] = useState(emptyForm());
  const debounceRef = useRef(null);

  const fetchDropdowns = useCallback(async () => {
    try {
      const [locRes, prodRes, statRes] = await Promise.all([
        locationsAPI.getAll(), productsAPI.getAll({ per_page: 500 }), statusAPI.getAll(),
      ]);
      setLocations(Array.isArray(locRes?.data) ? locRes.data : []);
      setProducts(Array.isArray(prodRes?.data) ? prodRes.data : []);
      setStatuses(Array.isArray(statRes?.data) ? statRes.data : []);
    } catch (e) { console.error(e); }
  }, []);

  const fetchAdjustments = useCallback(async (page = 1) => {
    setLoading(true);
    try {
      const params = { page, per_page: 10 };
      if (searchTerm) params.search = searchTerm;
      const res = await adjustmentsAPI.getAll(params);
      setAdjustments(Array.isArray(res?.data) ? res.data : []);
      setPagination(res?.pagination || res?.meta || { total: 0, last_page: 1 });
      setError(null);
    } catch (e) { setError(e.message); } finally { setLoading(false); }
  }, [searchTerm]);

  useEffect(() => { fetchDropdowns(); }, [fetchDropdowns]);
  useEffect(() => { fetchAdjustments(currentPage); }, [currentPage, fetchAdjustments]);
  useEffect(() => {
    if (debounceRef.current) clearTimeout(debounceRef.current);
    debounceRef.current = setTimeout(() => setCurrentPage(1), 400);
    return () => clearTimeout(debounceRef.current);
  }, [searchTerm]);

  const openAddModal = () => { setEditingAdj(null); setFormData(emptyForm()); setIsModalOpen(true); };
  const openEditModal = (adj) => {
    setEditingAdj(adj);
    setFormData({
      location_id: adj.location_id || '',
      adjustment_number: adj.adjustment_number || '',
      date: adj.date ? adj.date.split('T')[0] : '',
      adjustment_type: adj.adjustment_type || 'Stock Count',
      adjusted_by: adj.adjusted_by || '',
      status_id: adj.status_id || '',
      details: adj.details?.length ? adj.details.map(d => ({
        product_id: d.product_id || '', add_quantity: d.add_quantity || 0, deduct_quantity: d.deduct_quantity || 0,
      })) : [emptyDetail()],
    });
    setIsModalOpen(true);
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!formData.details[0]?.product_id) { toast.error('Add at least one product.'); return; }
    setSubmitting(true);
    try {
      const payload = {
        ...formData,
        details: formData.details.map(d => ({
          ...d,
          add_quantity: parseInt(d.add_quantity, 10) || 0,
          deduct_quantity: parseInt(d.deduct_quantity, 10) || 0,
        })),
      };
      if (editingAdj) {
        await adjustmentsAPI.update(editingAdj.adjustment_id, payload);
        toast.success('Adjustment updated.');
      } else {
        await adjustmentsAPI.create(payload);
        toast.success('Adjustment created. Inventory corrected.');
      }
      setIsModalOpen(false); fetchAdjustments(currentPage);
    } catch (e) { toast.error(e.message || 'Failed to save.'); } finally { setSubmitting(false); }
  };

  const handleDelete = async (id) => {
    if (!window.confirm('Delete this adjustment record?')) return;
    try { await adjustmentsAPI.delete(id); toast.success('Deleted.'); fetchAdjustments(currentPage); }
    catch (e) { toast.error(e.message || 'Failed to delete.'); }
  };

  const addDetailRow = () => setFormData(f => ({ ...f, details: [...f.details, emptyDetail()] }));
  const removeDetailRow = (i) => setFormData(f => ({ ...f, details: f.details.filter((_, idx) => idx !== i) }));
  const updateDetail = (i, field, value) => setFormData(f => {
    const details = [...f.details]; details[i] = { ...details[i], [field]: value }; return { ...f, details };
  });

  const now = new Date();
  const thisMonth = adjustments.filter(a => {
    if (!a.date) return false;
    const d = new Date(a.date);
    return d.getMonth() === now.getMonth() && d.getFullYear() === now.getFullYear();
  });
  const addTypes = ['Found Stock', 'Stock Count'];
  const deductTypes = ['Damage Write-off', 'Theft Loss', 'Expiry'];

  const renderPages = () => {
    const total = pagination.last_page || 1;
    let start = Math.max(1, currentPage - 2), end = Math.min(total, start + 4);
    if (end - start < 4) start = Math.max(1, end - 4);
    const pages = []; for (let i = start; i <= end; i++) pages.push(i); return pages;
  };

  return (
    <AdminLayout>
      <div className="page-container">
        <div className="page-header">
          <div>
            <h1 className="page-title">Adjustment / Stock Correction</h1>
            <p className="page-subtitle">Correct inventory discrepancies from damage, loss, or stock counts</p>
          </div>
          <button className="btn-primary" onClick={openAddModal}>+ New Adjustment</button>
        </div>

        <div className="stats-row">
          {[
            { label: 'Total Adjustments', value: pagination.total || adjustments.length, color: '#6366f1' },
            { label: 'Add Stock', value: adjustments.filter(a => addTypes.includes(a.adjustment_type)).length, color: '#10b981' },
            { label: 'Deduct Stock', value: adjustments.filter(a => deductTypes.includes(a.adjustment_type)).length, color: '#ef4444' },
            { label: 'This Month', value: thisMonth.length, color: '#f59e0b' },
          ].map((s, i) => (
            <div key={i} className="stat-card">
              <div className="stat-icon" style={{ background: s.color + '20', color: s.color }}>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="20" height="20">
                  <line x1="12" y1="5" x2="12" y2="19"></line>
                  <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
              </div>
              <div><div className="stat-value">{s.value}</div><div className="stat-label">{s.label}</div></div>
            </div>
          ))}
        </div>

        <div className="table-toolbar">
          <input className="search-input" placeholder="Search adjustment number..." value={searchTerm} onChange={e => setSearchTerm(e.target.value)} />
        </div>

        <div className="table-wrapper">
          <table className="data-table">
            <thead>
              <tr><th>Adjustment #</th><th>Type</th><th>Location</th><th>Date</th><th>Adjusted By</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
              {loading ? <tr><td colSpan="7" className="table-empty">Loading...</td></tr>
                : error ? <tr><td colSpan="7" className="table-empty"><span style={{ color: '#ef4444' }}>{error}</span><button className="btn-link" onClick={() => fetchAdjustments(currentPage)} style={{ marginLeft: 8 }}>Retry</button></td></tr>
                  : adjustments.length === 0 ? <tr><td colSpan="7" className="table-empty">No adjustment records found.</td></tr>
                    : adjustments.map(adj => (
                      <tr key={adj.adjustment_id}>
                        <td><span className="badge badge-blue">{adj.adjustment_number}</span></td>
                        <td><span className={`badge ${addTypes.includes(adj.adjustment_type) ? 'badge-green' : deductTypes.includes(adj.adjustment_type) ? 'badge-red' : 'badge-gray'}`}>{adj.adjustment_type}</span></td>
                        <td>{adj.location?.location_name || '—'}</td>
                        <td>{adj.date ? new Date(adj.date).toLocaleDateString() : '—'}</td>
                        <td>{adj.adjusted_by || adj.created_by_user?.username || '—'}</td>
                        <td><span className={`badge ${adj.status?.status_name === 'Approved' ? 'badge-green' : 'badge-yellow'}`}>{adj.status?.status_name || 'N/A'}</span></td>
                        <td>
                          <div className="action-btns">
                            <button className="btn-action btn-view" onClick={() => setViewingAdj(adj)}>View</button>
                            <button className="btn-action btn-edit" onClick={() => openEditModal(adj)}>Edit</button>
                            <button className="btn-action btn-delete" onClick={() => handleDelete(adj.adjustment_id)}>Delete</button>
                          </div>
                        </td>
                      </tr>
                    ))}
            </tbody>
          </table>
        </div>

        {pagination.last_page > 1 && (
          <div className="pagination">
            <button className="page-btn" disabled={currentPage === 1} onClick={() => setCurrentPage(p => p - 1)}>Previous</button>
            {renderPages().map(p => <button key={p} className={`page-btn ${p === currentPage ? 'active' : ''}`} onClick={() => setCurrentPage(p)}>{p}</button>)}
            <button className="page-btn" disabled={currentPage === pagination.last_page} onClick={() => setCurrentPage(p => p + 1)}>Next</button>
          </div>
        )}
      </div>

      {viewingAdj && (
        <Modal title={`Adjustment: ${viewingAdj.adjustment_number}`} onClose={() => setViewingAdj(null)}>
          <div className="detail-grid">
            <div><span className="detail-label">Type</span><span className="detail-value">{viewingAdj.adjustment_type}</span></div>
            <div><span className="detail-label">Location</span><span className="detail-value">{viewingAdj.location?.location_name || '—'}</span></div>
            <div><span className="detail-label">Date</span><span className="detail-value">{viewingAdj.date ? new Date(viewingAdj.date).toLocaleDateString() : '—'}</span></div>
            <div><span className="detail-label">Adjusted By</span><span className="detail-value">{viewingAdj.adjusted_by || '—'}</span></div>
            <div><span className="detail-label">Status</span><span className="detail-value">{viewingAdj.status?.status_name || '—'}</span></div>
          </div>
          {viewingAdj.details?.length > 0 && (
            <div style={{ marginTop: 16 }}>
              <h4 style={{ marginBottom: 8, color: '#374151' }}>Items Adjusted</h4>
              <table className="data-table">
                <thead><tr><th>Product</th><th style={{ color: '#10b981' }}>Add Qty</th><th style={{ color: '#ef4444' }}>Deduct Qty</th></tr></thead>
                <tbody>{viewingAdj.details.map((d, i) => (
                  <tr key={i}>
                    <td>{d.product?.product_name || '—'}</td>
                    <td style={{ color: '#10b981', fontWeight: 600 }}>+{d.add_quantity || 0}</td>
                    <td style={{ color: '#ef4444', fontWeight: 600 }}>-{d.deduct_quantity || 0}</td>
                  </tr>
                ))}</tbody>
              </table>
            </div>
          )}
          <div className="modal-footer"><button className="btn-secondary" onClick={() => setViewingAdj(null)}>Close</button></div>
        </Modal>
      )}

      {isModalOpen && (
        <Modal title={editingAdj ? 'Edit Adjustment' : 'New Adjustment'} onClose={() => setIsModalOpen(false)}>
          <form onSubmit={handleSubmit}>
            <div className="form-grid-2">
              <div className="form-group">
                <label>Location *</label>
                <select required value={formData.location_id} onChange={e => setFormData(f => ({ ...f, location_id: e.target.value }))}>
                  <option value="">Select location</option>
                  {locations.map(l => <option key={l.id} value={l.id}>{l.location_name || l.name}</option>)}
                </select>
              </div>
              <div className="form-group">
                <label>Adjustment Number *</label>
                <input required type="text" value={formData.adjustment_number} onChange={e => setFormData(f => ({ ...f, adjustment_number: e.target.value }))} placeholder="e.g. ADJ-2024-001" />
              </div>
              <div className="form-group">
                <label>Date *</label>
                <input required type="date" value={formData.date} onChange={e => setFormData(f => ({ ...f, date: e.target.value }))} />
              </div>
              <div className="form-group">
                <label>Type *</label>
                <select required value={formData.adjustment_type} onChange={e => setFormData(f => ({ ...f, adjustment_type: e.target.value }))}>
                  {ADJUSTMENT_TYPES.map(t => <option key={t} value={t}>{t}</option>)}
                </select>
              </div>
              <div className="form-group">
                <label>Adjusted By</label>
                <input type="text" value={formData.adjusted_by} onChange={e => setFormData(f => ({ ...f, adjusted_by: e.target.value }))} placeholder="Name of person adjusting" />
              </div>
              <div className="form-group">
                <label>Status</label>
                <select value={formData.status_id} onChange={e => setFormData(f => ({ ...f, status_id: e.target.value }))}>
                  <option value="">Select status</option>
                  {statuses.map(s => <option key={s.status_id} value={s.status_id}>{s.status_name}</option>)}
                </select>
              </div>
            </div>

            <div style={{ marginTop: 16 }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
                <h4 style={{ margin: 0, color: '#374151' }}>Items to Adjust</h4>
                <button type="button" className="btn-secondary" style={{ padding: '4px 12px', fontSize: 13 }} onClick={addDetailRow}>+ Add Item</button>
              </div>
              <table className="data-table">
                <thead><tr><th>Product</th><th>Add Qty</th><th>Deduct Qty</th><th></th></tr></thead>
                <tbody>
                  {formData.details.map((d, i) => (
                    <tr key={i}>
                      <td>
                        <select required value={d.product_id} onChange={e => updateDetail(i, 'product_id', e.target.value)} style={{ width: '100%' }}>
                          <option value="">Select product</option>
                          {products.map(p => <option key={p.product_id || p.id} value={p.product_id || p.id}>{p.product_name || p.name}</option>)}
                        </select>
                      </td>
                      <td><input type="number" min="0" value={d.add_quantity} onChange={e => updateDetail(i, 'add_quantity', e.target.value)} style={{ width: 80 }} /></td>
                      <td><input type="number" min="0" value={d.deduct_quantity} onChange={e => updateDetail(i, 'deduct_quantity', e.target.value)} style={{ width: 80 }} /></td>
                      <td>{formData.details.length > 1 && <button type="button" className="btn-action btn-delete" onClick={() => removeDetailRow(i)}>✕</button>}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            <div className="modal-footer">
              <button type="button" className="btn-secondary" onClick={() => setIsModalOpen(false)}>Cancel</button>
              <button type="submit" className="btn-primary" disabled={submitting}>{submitting ? 'Saving...' : editingAdj ? 'Update' : 'Create'}</button>
            </div>
          </form>
        </Modal>
      )}
    </AdminLayout>
  );
};

export default AdjustmentManagement;
