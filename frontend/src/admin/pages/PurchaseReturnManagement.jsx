import React, { useState, useEffect, useCallback, useRef } from 'react';
import AdminLayout from '../components/AdminLayout';
import Modal from '../components/Modal';
import { purchaseReturnsAPI, purchaseOrdersAPI, suppliersAPI, productsAPI, statusAPI } from '../services/api';
import { toast } from '../utils/toast';
import '../../admin/styles/dashboard_air.css';

const emptyDetail = () => ({ product_id: '', quantity_returned: 1, unit_cost: '', condition: 'Good' });
const emptyForm = () => ({
  pc_id: '', supplier_id: '', pr_number: '', return_date: '',
  reason: '', status_id: '', details: [emptyDetail()],
});

const PurchaseReturnManagement = () => {
  const [returns, setReturns] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [currentPage, setCurrentPage] = useState(1);
  const [pagination, setPagination] = useState({ total: 0, last_page: 1 });
  const [searchTerm, setSearchTerm] = useState('');
  const [purchaseOrders, setPurchaseOrders] = useState([]);
  const [suppliers, setSuppliers] = useState([]);
  const [products, setProducts] = useState([]);
  const [statuses, setStatuses] = useState([]);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [viewingReturn, setViewingReturn] = useState(null);
  const [editingReturn, setEditingReturn] = useState(null);
  const [submitting, setSubmitting] = useState(false);
  const [formData, setFormData] = useState(emptyForm());
  const debounceRef = useRef(null);

  const fetchDropdowns = useCallback(async () => {
    try {
      const [poRes, supRes, prodRes, statRes] = await Promise.all([
        purchaseOrdersAPI.getAll({ per_page: 500 }),
        suppliersAPI.getAll(),
        productsAPI.getAll({ per_page: 500 }),
        statusAPI.getAll(),
      ]);
      setPurchaseOrders(Array.isArray(poRes?.data) ? poRes.data : []);
      setSuppliers(Array.isArray(supRes?.data) ? supRes.data : []);
      setProducts(Array.isArray(prodRes?.data) ? prodRes.data : []);
      setStatuses(Array.isArray(statRes?.data) ? statRes.data : []);
    } catch (e) { console.error(e); }
  }, []);

  const fetchReturns = useCallback(async (page = 1) => {
    setLoading(true);
    try {
      const params = { page, per_page: 10 };
      if (searchTerm) params.search = searchTerm;
      const res = await purchaseReturnsAPI.getAll(params);
      setReturns(Array.isArray(res?.data) ? res.data : []);
      setPagination(res?.pagination || res?.meta || { total: 0, last_page: 1 });
      setError(null);
    } catch (e) { setError(e.message); } finally { setLoading(false); }
  }, [searchTerm]);

  useEffect(() => { fetchDropdowns(); }, [fetchDropdowns]);
  useEffect(() => { fetchReturns(currentPage); }, [currentPage, fetchReturns]);
  useEffect(() => {
    if (debounceRef.current) clearTimeout(debounceRef.current);
    debounceRef.current = setTimeout(() => setCurrentPage(1), 400);
    return () => clearTimeout(debounceRef.current);
  }, [searchTerm]);

  const openAddModal = () => { setEditingReturn(null); setFormData(emptyForm()); setIsModalOpen(true); };
  const openEditModal = (r) => {
    setEditingReturn(r);
    setFormData({
      pc_id: r.pc_id || r.purchase_order?.po_id || '',
      supplier_id: r.supplier_id || r.supplier?.id || '',
      pr_number: r.pr_number || '',
      return_date: r.return_date ? r.return_date.split('T')[0] : '',
      reason: r.reason || '',
      status_id: r.status_id || '',
      details: r.details?.length ? r.details.map(d => ({
        product_id: d.product_id || '', quantity_returned: d.quantity_returned || 1,
        unit_cost: d.unit_cost || '', condition: d.condition || 'Good',
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
          quantity_returned: parseInt(d.quantity_returned, 10) || 1,
          unit_cost: parseFloat(d.unit_cost) || 0,
        })),
      };
      if (editingReturn) {
        await purchaseReturnsAPI.update(editingReturn.pr_id, payload);
        toast.success('Purchase return updated.');
      } else {
        await purchaseReturnsAPI.create(payload);
        toast.success('Purchase return created.');
      }
      setIsModalOpen(false); fetchReturns(currentPage);
    } catch (e) { toast.error(e.message || 'Failed to save.'); } finally { setSubmitting(false); }
  };

  const handleDelete = async (id) => {
    if (!window.confirm('Delete this purchase return?')) return;
    try { await purchaseReturnsAPI.delete(id); toast.success('Deleted.'); fetchReturns(currentPage); }
    catch (e) { toast.error(e.message || 'Failed to delete.'); }
  };

  const addDetailRow = () => setFormData(f => ({ ...f, details: [...f.details, emptyDetail()] }));
  const removeDetailRow = (i) => setFormData(f => ({ ...f, details: f.details.filter((_, idx) => idx !== i) }));
  const updateDetail = (i, field, value) => setFormData(f => {
    const details = [...f.details]; details[i] = { ...details[i], [field]: value }; return { ...f, details };
  });

  const totalAmount = formData.details.reduce((sum, d) => {
    return sum + (parseFloat(d.quantity_returned) || 0) * (parseFloat(d.unit_cost) || 0);
  }, 0);

  const now = new Date();
  const thisMonth = returns.filter(r => {
    if (!r.return_date) return false;
    const d = new Date(r.return_date);
    return d.getMonth() === now.getMonth() && d.getFullYear() === now.getFullYear();
  });
  const totalValue = returns.reduce((s, r) => s + parseFloat(r.total_amount || 0), 0);

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
            <h1 className="page-title">Purchase Returns</h1>
            <p className="page-subtitle">Return defective or excess items back to suppliers</p>
          </div>
          <button className="btn-primary" onClick={openAddModal}>+ New Return</button>
        </div>

        <div className="stats-row">
          {[
            { label: 'Total Returns', value: pagination.total || returns.length, color: '#6366f1' },
            { label: 'Pending', value: returns.filter(r => r.status?.status_name === 'Pending').length, color: '#f59e0b' },
            { label: 'Approved', value: returns.filter(r => r.status?.status_name === 'Approved').length, color: '#10b981' },
            { label: 'Total Value', value: '₱' + totalValue.toLocaleString('en-PH', { minimumFractionDigits: 2 }), color: '#ef4444' },
          ].map((s, i) => (
            <div key={i} className="stat-card">
              <div className="stat-icon" style={{ background: s.color + '20', color: s.color }}>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="20" height="20">
                  <polyline points="1 4 1 10 7 10"></polyline>
                  <path d="M3.51 15a9 9 0 1 0 .49-4"></path>
                </svg>
              </div>
              <div><div className="stat-value">{s.value}</div><div className="stat-label">{s.label}</div></div>
            </div>
          ))}
        </div>

        <div className="table-toolbar">
          <input className="search-input" placeholder="Search return number..." value={searchTerm} onChange={e => setSearchTerm(e.target.value)} />
        </div>

        <div className="table-wrapper">
          <table className="data-table">
            <thead>
              <tr><th>Return #</th><th>PO Number</th><th>Supplier</th><th>Return Date</th><th>Reason</th><th>Total Amount</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
              {loading ? <tr><td colSpan="8" className="table-empty">Loading...</td></tr>
                : error ? <tr><td colSpan="8" className="table-empty"><span style={{ color: '#ef4444' }}>{error}</span><button className="btn-link" onClick={() => fetchReturns(currentPage)} style={{ marginLeft: 8 }}>Retry</button></td></tr>
                  : returns.length === 0 ? <tr><td colSpan="8" className="table-empty">No purchase returns found.</td></tr>
                    : returns.map(r => (
                      <tr key={r.pr_id}>
                        <td><span className="badge badge-blue">{r.pr_number}</span></td>
                        <td>{r.purchase_order?.po_number || '—'}</td>
                        <td>{r.supplier?.name || r.supplier?.supplier_name || '—'}</td>
                        <td>{r.return_date ? new Date(r.return_date).toLocaleDateString() : '—'}</td>
                        <td style={{ maxWidth: 160, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{r.reason || '—'}</td>
                        <td>₱{parseFloat(r.total_amount || 0).toLocaleString('en-PH', { minimumFractionDigits: 2 })}</td>
                        <td><span className={`badge ${r.status?.status_name === 'Approved' ? 'badge-green' : 'badge-yellow'}`}>{r.status?.status_name || 'N/A'}</span></td>
                        <td>
                          <div className="action-btns">
                            <button className="btn-action btn-view" onClick={() => setViewingReturn(r)}>View</button>
                            <button className="btn-action btn-edit" onClick={() => openEditModal(r)}>Edit</button>
                            <button className="btn-action btn-delete" onClick={() => handleDelete(r.pr_id)}>Delete</button>
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

      {viewingReturn && (
        <Modal title={`Return: ${viewingReturn.pr_number}`} onClose={() => setViewingReturn(null)}>
          <div className="detail-grid">
            <div><span className="detail-label">PO Number</span><span className="detail-value">{viewingReturn.purchase_order?.po_number || '—'}</span></div>
            <div><span className="detail-label">Supplier</span><span className="detail-value">{viewingReturn.supplier?.name || viewingReturn.supplier?.supplier_name || '—'}</span></div>
            <div><span className="detail-label">Return Date</span><span className="detail-value">{viewingReturn.return_date ? new Date(viewingReturn.return_date).toLocaleDateString() : '—'}</span></div>
            <div><span className="detail-label">Total Amount</span><span className="detail-value" style={{ color: '#ef4444' }}>₱{parseFloat(viewingReturn.total_amount || 0).toLocaleString('en-PH', { minimumFractionDigits: 2 })}</span></div>
            <div style={{ gridColumn: '1 / -1' }}><span className="detail-label">Reason</span><span className="detail-value">{viewingReturn.reason || '—'}</span></div>
            <div><span className="detail-label">Status</span><span className="detail-value">{viewingReturn.status?.status_name || '—'}</span></div>
          </div>
          {viewingReturn.details?.length > 0 && (
            <div style={{ marginTop: 16 }}>
              <h4 style={{ marginBottom: 8, color: '#374151' }}>Items Returned</h4>
              <table className="data-table">
                <thead><tr><th>Product</th><th>Qty</th><th>Unit Cost</th><th>Condition</th><th>Subtotal</th></tr></thead>
                <tbody>{viewingReturn.details.map((d, i) => (
                  <tr key={i}>
                    <td>{d.product?.product_name || '—'}</td>
                    <td>{d.quantity_returned}</td>
                    <td>₱{parseFloat(d.unit_cost || 0).toLocaleString('en-PH', { minimumFractionDigits: 2 })}</td>
                    <td>{d.condition || '—'}</td>
                    <td>₱{parseFloat(d.subtotal || (d.quantity_returned * d.unit_cost) || 0).toLocaleString('en-PH', { minimumFractionDigits: 2 })}</td>
                  </tr>
                ))}</tbody>
              </table>
            </div>
          )}
          <div className="modal-footer"><button className="btn-secondary" onClick={() => setViewingReturn(null)}>Close</button></div>
        </Modal>
      )}

      {isModalOpen && (
        <Modal title={editingReturn ? 'Edit Purchase Return' : 'New Purchase Return'} onClose={() => setIsModalOpen(false)}>
          <form onSubmit={handleSubmit}>
            <div className="form-grid-2">
              <div className="form-group">
                <label>Purchase Order *</label>
                <select required value={formData.pc_id} onChange={e => setFormData(f => ({ ...f, pc_id: e.target.value }))}>
                  <option value="">Select PO</option>
                  {purchaseOrders.map(po => <option key={po.po_id} value={po.po_id}>{po.po_number}</option>)}
                </select>
              </div>
              <div className="form-group">
                <label>Supplier *</label>
                <select required value={formData.supplier_id} onChange={e => setFormData(f => ({ ...f, supplier_id: e.target.value }))}>
                  <option value="">Select supplier</option>
                  {suppliers.map(s => <option key={s.id} value={s.id}>{s.name || s.supplier_name}</option>)}
                </select>
              </div>
              <div className="form-group">
                <label>Return Number *</label>
                <input required type="text" value={formData.pr_number} onChange={e => setFormData(f => ({ ...f, pr_number: e.target.value }))} placeholder="e.g. PRN-2024-001" />
              </div>
              <div className="form-group">
                <label>Return Date *</label>
                <input required type="date" value={formData.return_date} onChange={e => setFormData(f => ({ ...f, return_date: e.target.value }))} />
              </div>
              <div className="form-group" style={{ gridColumn: '1 / -1' }}>
                <label>Reason</label>
                <textarea rows="2" value={formData.reason} onChange={e => setFormData(f => ({ ...f, reason: e.target.value }))} placeholder="Reason for return..." style={{ resize: 'vertical' }} />
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
                <h4 style={{ margin: 0, color: '#374151' }}>Items to Return</h4>
                <button type="button" className="btn-secondary" style={{ padding: '4px 12px', fontSize: 13 }} onClick={addDetailRow}>+ Add Item</button>
              </div>
              <table className="data-table" style={{ marginBottom: 8 }}>
                <thead><tr><th>Product</th><th>Qty</th><th>Unit Cost</th><th>Condition</th><th></th></tr></thead>
                <tbody>
                  {formData.details.map((d, i) => (
                    <tr key={i}>
                      <td>
                        <select required value={d.product_id} onChange={e => updateDetail(i, 'product_id', e.target.value)} style={{ width: '100%' }}>
                          <option value="">Select product</option>
                          {products.map(p => <option key={p.product_id || p.id} value={p.product_id || p.id}>{p.product_name || p.name}</option>)}
                        </select>
                      </td>
                      <td><input type="number" min="1" required value={d.quantity_returned} onChange={e => updateDetail(i, 'quantity_returned', e.target.value)} style={{ width: 70 }} /></td>
                      <td><input type="number" min="0" step="0.01" required value={d.unit_cost} onChange={e => updateDetail(i, 'unit_cost', e.target.value)} style={{ width: 90 }} placeholder="0.00" /></td>
                      <td>
                        <select value={d.condition} onChange={e => updateDetail(i, 'condition', e.target.value)}>
                          {['Good', 'Damaged', 'Defective', 'Expired'].map(c => <option key={c} value={c}>{c}</option>)}
                        </select>
                      </td>
                      <td>{formData.details.length > 1 && <button type="button" className="btn-action btn-delete" onClick={() => removeDetailRow(i)}>✕</button>}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
              <div style={{ textAlign: 'right', fontWeight: 600, color: '#374151' }}>
                Total Amount: ₱{totalAmount.toLocaleString('en-PH', { minimumFractionDigits: 2 })}
              </div>
            </div>

            <div className="modal-footer">
              <button type="button" className="btn-secondary" onClick={() => setIsModalOpen(false)}>Cancel</button>
              <button type="submit" className="btn-primary" disabled={submitting}>{submitting ? 'Saving...' : editingReturn ? 'Update' : 'Create'}</button>
            </div>
          </form>
        </Modal>
      )}
    </AdminLayout>
  );
};

export default PurchaseReturnManagement;
