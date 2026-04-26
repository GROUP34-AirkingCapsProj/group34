import React, { useState, useEffect, useCallback, useRef } from 'react';
import AdminLayout from '../components/AdminLayout';
import Modal from '../components/Modal';
import { deliveryReceiptsAPI, salesAPI, statusAPI } from '../services/api';
import { toast } from '../utils/toast';
import '../../admin/styles/dashboard_air.css';

const emptyForm = () => ({ dr_number: '', sales_id: '', status_id: '' });

const DeliveryReceiptManagement = () => {
  const [receipts, setReceipts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [currentPage, setCurrentPage] = useState(1);
  const [pagination, setPagination] = useState({ total: 0, last_page: 1 });
  const [searchTerm, setSearchTerm] = useState('');
  const [sales, setSales] = useState([]);
  const [statuses, setStatuses] = useState([]);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [viewingReceipt, setViewingReceipt] = useState(null);
  const [editingReceipt, setEditingReceipt] = useState(null);
  const [submitting, setSubmitting] = useState(false);
  const [formData, setFormData] = useState(emptyForm());
  const debounceRef = useRef(null);

  const fetchDropdowns = useCallback(async () => {
    try {
      const [salesRes, statRes] = await Promise.all([
        salesAPI.getAll({ per_page: 500 }),
        statusAPI.getAll(),
      ]);
      setSales(Array.isArray(salesRes?.data) ? salesRes.data : []);
      setStatuses(Array.isArray(statRes?.data) ? statRes.data : []);
    } catch (e) { console.error(e); }
  }, []);

  const fetchReceipts = useCallback(async (page = 1) => {
    setLoading(true);
    try {
      const params = { page, per_page: 10 };
      if (searchTerm) params.search = searchTerm;
      const res = await deliveryReceiptsAPI.getAll(params);
      setReceipts(Array.isArray(res?.data) ? res.data : []);
      setPagination(res?.pagination || res?.meta || { total: 0, last_page: 1 });
      setError(null);
    } catch (e) { setError(e.message); } finally { setLoading(false); }
  }, [searchTerm]);

  useEffect(() => { fetchDropdowns(); }, [fetchDropdowns]);
  useEffect(() => { fetchReceipts(currentPage); }, [currentPage, fetchReceipts]);
  useEffect(() => {
    if (debounceRef.current) clearTimeout(debounceRef.current);
    debounceRef.current = setTimeout(() => setCurrentPage(1), 400);
    return () => clearTimeout(debounceRef.current);
  }, [searchTerm]);

  const openAddModal = () => { setEditingReceipt(null); setFormData(emptyForm()); setIsModalOpen(true); };
  const openEditModal = (r) => {
    setEditingReceipt(r);
    setFormData({
      dr_number: r.dr_number || '',
      sales_id: r.sales_id || r.sale?.sales_id || '',
      status_id: r.status_id || '',
    });
    setIsModalOpen(true);
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setSubmitting(true);
    try {
      if (editingReceipt) {
        await deliveryReceiptsAPI.update(editingReceipt.dr_id, { status_id: formData.status_id });
        toast.success('Delivery receipt updated.');
      } else {
        await deliveryReceiptsAPI.create(formData);
        toast.success('Delivery receipt issued.');
      }
      setIsModalOpen(false); fetchReceipts(currentPage);
    } catch (e) { toast.error(e.message || 'Failed to save.'); } finally { setSubmitting(false); }
  };

  const handleDelete = async (id) => {
    if (!window.confirm('Delete this delivery receipt?')) return;
    try { await deliveryReceiptsAPI.delete(id); toast.success('Deleted.'); fetchReceipts(currentPage); }
    catch (e) { toast.error(e.message || 'Failed to delete.'); }
  };

  const getCustomerName = (receipt) => {
    const customer = receipt.sale?.customer;
    if (!customer) return '—';
    if (customer.first_name || customer.last_name) return `${customer.first_name || ''} ${customer.last_name || ''}`.trim();
    return customer.company_name || customer.name || '—';
  };

  const printReceipt = (receipt) => {
    const customer  = receipt.sale?.customer;
    const custName  = getCustomerName(receipt);
    const custAddr  = [customer?.address, customer?.city, customer?.province].filter(Boolean).join(', ') || '';
    const items     = receipt.sale?.details || [];
    const drDate    = receipt.created_at
      ? new Date(receipt.created_at).toLocaleDateString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric' })
      : new Date().toLocaleDateString('en-US');
    const issuedBy  = receipt.issued_by_user?.first_name
      ? `${receipt.issued_by_user.first_name} ${receipt.issued_by_user.last_name || ''}`.trim()
      : (receipt.issued_by_user?.username || '');

    /* blank rows to fill to ~15 lines */
    const blankCount = Math.max(0, 15 - items.length);
    const itemRows = items.map(d => `
      <tr>
        <td class="tc">${d.quantity || ''}</td>
        <td class="tc">${d.unit || 'pcs'}</td>
        <td>${d.product?.product_name || d.description || ''}</td>
        <td></td>
      </tr>`).join('') + Array(blankCount).fill(`<tr><td>&nbsp;</td><td></td><td></td><td></td></tr>`).join('');

    const html = `<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8"/>
<title>DR ${receipt.dr_number}</title>
<style>
  *{margin:0;padding:0;box-sizing:border-box;}
  body{font-family:Arial,Helvetica,sans-serif;font-size:11.5px;color:#000;padding:28px 36px;}
  .co-name{font-size:15px;font-weight:bold;text-align:center;letter-spacing:0.04em;}
  .co-addr{font-size:10px;text-align:center;margin:1px 0;}
  .doc-title{font-size:12px;font-weight:bold;text-align:center;letter-spacing:0.12em;margin:4px 0 10px;}
  .top-info{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px;}
  .left-info{font-size:11.5px;line-height:2.2;}
  .left-info .field{display:flex;gap:6px;}
  .left-info .lbl{font-size:11px;white-space:nowrap;}
  .left-info .val{border-bottom:1px solid #000;min-width:180px;font-size:11.5px;}
  .right-info{font-size:11.5px;line-height:2;text-align:right;}
  .right-info .field{display:flex;justify-content:flex-end;gap:6px;align-items:flex-end;}
  .right-info .lbl{font-size:11px;white-space:nowrap;font-weight:600;}
  .right-info .val{border-bottom:1px solid #000;min-width:110px;font-size:11.5px;}
  .pay-row{display:flex;gap:24px;margin:8px 0 10px;font-size:11px;align-items:center;}
  .chk{display:inline-flex;align-items:center;gap:4px;}
  .chk-box{width:11px;height:11px;border:1px solid #000;display:inline-block;}
  .sep{border-top:1px solid #000;margin:4px 0;}
  table.items{width:100%;border-collapse:collapse;margin-bottom:0;}
  table.items th{border:1px solid #000;padding:4px 6px;font-size:10.5px;text-align:center;font-weight:bold;}
  table.items td{border:1px solid #000;padding:3px 6px;font-size:11px;height:20px;}
  .tc{text-align:center;}
  .rcvd-txt{font-size:10.5px;font-weight:bold;margin:10px 0 0;}
  .sigs{display:flex;justify-content:space-between;margin-top:6px;}
  .sig-left{width:45%;}
  .sig-right{width:45%;text-align:right;}
  .sig-line{display:flex;gap:6px;align-items:flex-end;margin-top:8px;}
  .sig-line .lbl{font-size:10.5px;font-weight:bold;white-space:nowrap;}
  .sig-line .line{border-bottom:1px solid #000;flex:1;}
  .sig-line-name{font-size:10px;font-weight:bold;text-decoration:underline;margin-left:70px;}
  @media print{body{padding:15px 20px;}@page{size:A4 portrait;margin:10mm;}}
</style>
</head>
<body>
  <div class="co-name">PARAMOND INC.</div>
  <div class="co-addr">Zone 5, Lower Bulua, Cagayan De Oro City</div>
  <div class="doc-title">DELIVERY RECEIPT</div>

  <div class="top-info">
    <div class="left-info">
      <div class="field"><span class="lbl">Delivered To:</span><span class="val">${custName}</span></div>
      <div class="field"><span class="lbl">Address:</span><span class="val">${custAddr}</span></div>
    </div>
    <div class="right-info">
      <div class="field"><span class="lbl">No.</span><span class="val">${receipt.dr_number}</span></div>
      <div class="field"><span class="lbl">Date:</span><span class="val">${drDate}</span></div>
      <div class="field"><span class="lbl">P.O. No.</span><span class="val">${receipt.sale?.invoice_number || ''}</span></div>
    </div>
  </div>

  <div class="pay-row">
    <span class="chk"><span class="chk-box"></span> Cash sales</span>
    <span class="chk"><span class="chk-box"></span> Charge sales</span>
    <span style="margin-left:32px;">OFT:</span>
    <span class="chk"><span class="chk-box"></span> Check/Pdc</span>
  </div>

  <table class="items">
    <thead>
      <tr>
        <th style="width:60px;">QTY</th>
        <th style="width:60px;">UNIT</th>
        <th>DESCRIPTION</th>
        <th style="width:120px;">REMARKS</th>
      </tr>
    </thead>
    <tbody>${itemRows}</tbody>
  </table>

  <div class="rcvd-txt">Received the goods in order and condition</div>

  <div class="sigs">
    <div class="sig-left">
      <div class="sig-line"><span class="lbl">Prepared by:</span><span class="line"></span></div>
      <div class="sig-line-name">${issuedBy || '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;'}</div>
      <div style="height:14px;"></div>
      <div class="sig-line"><span class="lbl">Approved by:</span><span class="line"></span></div>
    </div>
    <div class="sig-right">
      <div class="sig-line"><span class="lbl">Received by:</span><span class="line"></span></div>
      <div style="font-size:9.5px;text-align:right;margin-top:2px;">Receiver's Signature over Printed Name</div>
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

  const now = new Date();
  const thisMonth = receipts.filter(r => {
    if (!r.created_at) return false;
    const d = new Date(r.created_at);
    return d.getMonth() === now.getMonth() && d.getFullYear() === now.getFullYear();
  });

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
            <h1 className="page-title">Delivery Receipts</h1>
            <p className="page-subtitle">Issue delivery receipts for completed sales</p>
          </div>
          <button className="btn-primary" onClick={openAddModal}>+ New Delivery Receipt</button>
        </div>

        <div className="stats-row">
          {[
            { label: 'Total DRs', value: pagination.total || receipts.length, color: '#6366f1' },
            { label: 'Pending', value: receipts.filter(r => r.status?.status_name === 'Pending').length, color: '#f59e0b' },
            { label: 'Delivered', value: receipts.filter(r => r.status?.status_name === 'Delivered' || r.status?.status_name === 'Completed').length, color: '#10b981' },
            { label: 'This Month', value: thisMonth.length, color: '#3b82f6' },
          ].map((s, i) => (
            <div key={i} className="stat-card">
              <div className="stat-icon" style={{ background: s.color + '20', color: s.color }}>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="20" height="20">
                  <rect x="1" y="3" width="15" height="13"></rect>
                  <polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon>
                  <circle cx="5.5" cy="18.5" r="2.5"></circle>
                  <circle cx="18.5" cy="18.5" r="2.5"></circle>
                </svg>
              </div>
              <div><div className="stat-value">{s.value}</div><div className="stat-label">{s.label}</div></div>
            </div>
          ))}
        </div>

        <div className="table-toolbar">
          <input className="search-input" placeholder="Search DR number..." value={searchTerm} onChange={e => setSearchTerm(e.target.value)} />
        </div>

        <div className="table-wrapper">
          <table className="data-table">
            <thead>
              <tr><th>DR Number</th><th>Sale Invoice #</th><th>Customer</th><th>Issued By</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
              {loading ? <tr><td colSpan="6" className="table-empty">Loading...</td></tr>
                : error ? <tr><td colSpan="6" className="table-empty"><span style={{ color: '#ef4444' }}>{error}</span><button className="btn-link" onClick={() => fetchReceipts(currentPage)} style={{ marginLeft: 8 }}>Retry</button></td></tr>
                  : receipts.length === 0 ? <tr><td colSpan="6" className="table-empty">No delivery receipts found.</td></tr>
                    : receipts.map(r => (
                      <tr key={r.dr_id}>
                        <td><span className="badge badge-blue">{r.dr_number}</span></td>
                        <td>{r.sale?.invoice_number || '—'}</td>
                        <td>{getCustomerName(r)}</td>
                        <td>{r.issued_by_user?.username || r.issuedBy?.username || '—'}</td>
                        <td><span className={`badge ${r.status?.status_name === 'Delivered' || r.status?.status_name === 'Completed' ? 'badge-green' : 'badge-yellow'}`}>{r.status?.status_name || 'N/A'}</span></td>
                        <td>
                          <div className="action-btns">
                            <button className="btn-action btn-view" onClick={() => setViewingReceipt(r)}>View</button>
                            <button className="btn-action" style={{ background: '#f0fdf4', color: '#059669', border: '1px solid #bbf7d0' }} onClick={() => printReceipt(r)}>Print</button>
                            <button className="btn-action btn-edit" onClick={() => openEditModal(r)}>Edit</button>
                            <button className="btn-action btn-delete" onClick={() => handleDelete(r.dr_id)}>Delete</button>
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

      {viewingReceipt && (
        <Modal title={`DR: ${viewingReceipt.dr_number}`} onClose={() => setViewingReceipt(null)}>
          <div className="detail-grid">
            <div><span className="detail-label">DR Number</span><span className="detail-value">{viewingReceipt.dr_number}</span></div>
            <div><span className="detail-label">Sale Invoice</span><span className="detail-value">{viewingReceipt.sale?.invoice_number || '—'}</span></div>
            <div><span className="detail-label">Customer</span><span className="detail-value">{getCustomerName(viewingReceipt)}</span></div>
            <div><span className="detail-label">Issued By</span><span className="detail-value">{viewingReceipt.issued_by_user?.username || viewingReceipt.issuedBy?.username || '—'}</span></div>
            <div><span className="detail-label">Status</span><span className="detail-value">{viewingReceipt.status?.status_name || '—'}</span></div>
          </div>
          {viewingReceipt.sale?.details?.length > 0 && (
            <div style={{ marginTop: 16 }}>
              <h4 style={{ marginBottom: 8, color: '#374151' }}>Sale Items</h4>
              <table className="data-table">
                <thead><tr><th>Product</th><th>Qty</th><th>Unit Price</th><th>Subtotal</th></tr></thead>
                <tbody>{viewingReceipt.sale.details.map((d, i) => (
                  <tr key={i}>
                    <td>{d.product?.product_name || '—'}</td>
                    <td>{d.quantity}</td>
                    <td>₱{parseFloat(d.unit_price || 0).toLocaleString('en-PH', { minimumFractionDigits: 2 })}</td>
                    <td>₱{parseFloat(d.subtotal || (d.quantity * d.unit_price) || 0).toLocaleString('en-PH', { minimumFractionDigits: 2 })}</td>
                  </tr>
                ))}</tbody>
              </table>
            </div>
          )}
          <div className="modal-footer">
            <button className="btn-secondary" onClick={() => setViewingReceipt(null)}>Close</button>
            <button className="btn-primary" onClick={() => printReceipt(viewingReceipt)}>Print DR</button>
          </div>
        </Modal>
      )}

      {isModalOpen && (
        <Modal title={editingReceipt ? 'Update Delivery Status' : 'New Delivery Receipt'} onClose={() => setIsModalOpen(false)}>
          <form onSubmit={handleSubmit}>
            {!editingReceipt && (
              <>
                <div className="form-group">
                  <label>DR Number *</label>
                  <input required type="text" value={formData.dr_number} onChange={e => setFormData(f => ({ ...f, dr_number: e.target.value }))} placeholder="e.g. DR-2024-001" />
                </div>
                <div className="form-group">
                  <label>Sale *</label>
                  <select required value={formData.sales_id} onChange={e => setFormData(f => ({ ...f, sales_id: e.target.value }))}>
                    <option value="">Select sale (invoice number)</option>
                    {sales.map(s => (
                      <option key={s.sales_id} value={s.sales_id}>
                        {s.invoice_number} — {s.customer ? `${s.customer.first_name || ''} ${s.customer.last_name || ''}`.trim() || s.customer.company_name : 'N/A'}
                      </option>
                    ))}
                  </select>
                </div>
              </>
            )}
            <div className="form-group">
              <label>Status</label>
              <select value={formData.status_id} onChange={e => setFormData(f => ({ ...f, status_id: e.target.value }))}>
                <option value="">Select status</option>
                {statuses.map(s => <option key={s.status_id} value={s.status_id}>{s.status_name}</option>)}
              </select>
            </div>
            <div className="modal-footer">
              <button type="button" className="btn-secondary" onClick={() => setIsModalOpen(false)}>Cancel</button>
              <button type="submit" className="btn-primary" disabled={submitting}>{submitting ? 'Saving...' : editingReceipt ? 'Update Status' : 'Issue DR'}</button>
            </div>
          </form>
        </Modal>
      )}
    </AdminLayout>
  );
};

export default DeliveryReceiptManagement;
