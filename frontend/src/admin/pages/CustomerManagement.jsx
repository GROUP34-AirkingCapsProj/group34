import React, { useState, useEffect, useCallback } from 'react';
import AdminLayout from '../components/AdminLayout';
import Modal from '../components/Modal';
import Pagination from '../components/Pagination';
import ArchiveButton from '../components/ArchiveButton';
import { customersAPI, getApiBaseUrl, getToken } from '../services/api';
import '../styles/dashboard_air.css';
import '../styles/customer_registry.css';
import '../styles/archive_management.css';

const PAGE_SIZE = 15;

const EMPTY_FORM = {
  customer_type:  'registered',
  customer_name:  '',
  contact_number: '',
  email:          '',
  company_name:   '',
  address:        '',
  city:           '',
  province:       '',
  region:         '',
  tin:            '',
  notes:          '',
  credit_limit:   '',
};

const TYPE_LABELS = {
  registered: 'Registered',
  walk_in:    'Walk-in',
};

const CustomerManagement = () => {
  const [customers, setCustomers]       = useState([]);
  const [meta, setMeta]                 = useState({ total: 0, last_page: 1 });
  const [loading, setLoading]           = useState(true);
  const [error, setError]               = useState(null);
  const [searchTerm, setSearchTerm]     = useState('');
  const [filterType, setFilterType]     = useState('');
  const [filterStatus, setFilterStatus] = useState('');
  const [showArchived, setShowArchived] = useState(false);
  const [currentPage, setCurrentPage]   = useState(1);

  const [viewingCustomer, setViewingCustomer] = useState(null);
  const [editingCustomer, setEditingCustomer] = useState(null);
  const [addModalOpen, setAddModalOpen]       = useState(false);

  const [historyCustomer, setHistoryCustomer]     = useState(null);
  const [historyData, setHistoryData]             = useState(null);
  const [historyLoading, setHistoryLoading]       = useState(false);
  const [historyError, setHistoryError]           = useState(null);
  const [historyTab, setHistoryTab]               = useState('transactions');
  const [expandedIssuances, setExpandedIssuances] = useState(new Set());

  const [formData, setFormData]     = useState({ ...EMPTY_FORM });
  const [submitting, setSubmitting] = useState(false);
  const [formError, setFormError]   = useState('');

  const fetchCustomers = useCallback(async (page = 1) => {
    try {
      setLoading(true);
      const params = { per_page: PAGE_SIZE, page };
      if (searchTerm)   params.search           = searchTerm;
      if (filterType)   params.customer_type    = filterType;
      if (filterStatus) params.status_id        = filterStatus;
      if (showArchived) params.include_archived = 1;
      const res = await customersAPI.getAll(params);
      const payload = res?.data ?? res;
      const list = Array.isArray(payload?.data) ? payload.data
                 : Array.isArray(payload)        ? payload
                 : [];
      setCustomers(list);
      setMeta({
        total:     payload?.total     ?? list.length,
        last_page: payload?.last_page ?? 1,
      });
      setError(null);
    } catch (err) {
      setError(err.message);
      setCustomers([]);
    } finally {
      setLoading(false);
    }
  }, [searchTerm, filterType, filterStatus, showArchived]);

  useEffect(() => { setCurrentPage(1); }, [searchTerm, filterType, filterStatus, showArchived]);
  useEffect(() => { fetchCustomers(currentPage); }, [fetchCustomers, currentPage]);

  const openHistory = async (c) => {
    setHistoryCustomer(c);
    setHistoryData(null);
    setHistoryError(null);
    setHistoryLoading(true);
    setHistoryTab('transactions');
    setExpandedIssuances(new Set());
    try {
      const res = await customersAPI.getHistory(c.customer_id);
      setHistoryData(res?.data ?? res);
    } catch (err) {
      setHistoryError(err.message || 'Failed to load history.');
    } finally {
      setHistoryLoading(false);
    }
  };

  const toggleIssuance = (id) => {
    setExpandedIssuances((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id); else next.add(id);
      return next;
    });
  };

  const handleExport = () => {
    const params = {};
    if (filterType) params.customer_type = filterType;
    const path  = customersAPI.exportCsv(params);
    const base  = getApiBaseUrl();
    const token = getToken();
    const url   = `${base}${path}`;
    const a = document.createElement('a');
    a.href = token ? `${url}${path.includes('?') ? '&' : '?'}token=${token}` : url;
    a.download = '';
    a.click();
  };

  const handleArchive = async (id) => {
    try {
      await customersAPI.delete(id);
      fetchCustomers(currentPage);
    } catch (err) {
      alert(err.message || 'Failed to archive customer');
    }
  };

  const handleRestore = async (id) => {
    try {
      await customersAPI.restore(id);
      fetchCustomers(currentPage);
    } catch (err) {
      alert(err.message || 'Failed to restore customer');
    }
  };

  const openAddModal = () => {
    setFormError('');
    setFormData({ ...EMPTY_FORM });
    setAddModalOpen(true);
  };

  const openEditModal = (c) => {
    setFormError('');
    setFormData({
      customer_type:  c.customer_type  ?? 'registered',
      customer_name:  c.customer_name  ?? '',
      contact_number: c.contact_number ?? '',
      email:          c.email          ?? '',
      company_name:   c.company_name   ?? '',
      address:        c.address        ?? '',
      city:           c.city           ?? '',
      province:       c.province       ?? '',
      region:         c.region         ?? '',
      tin:            c.tin            ?? '',
      notes:          c.notes          ?? '',
      credit_limit:   c.credit_limit   ?? '',
      status_id:      c.status_id      ?? 36,
    });
    setEditingCustomer(c);
  };

  const handleFormSubmit = async (e) => {
    e.preventDefault();
    setSubmitting(true);
    setFormError('');
    try {
      const payload = {
        ...formData,
        credit_limit: formData.credit_limit !== '' ? parseFloat(formData.credit_limit) : null,
      };
      if (editingCustomer) {
        await customersAPI.update(editingCustomer.customer_id, payload);
        setEditingCustomer(null);
      } else {
        await customersAPI.create(payload);
        setAddModalOpen(false);
      }
      await fetchCustomers(currentPage);
    } catch (err) {
      const msg = err.errors ? Object.values(err.errors).flat()[0] : err.message;
      setFormError(msg || 'Failed to save customer');
    } finally {
      setSubmitting(false);
    }
  };

  const formatMoney = (val) => {
    const n = parseFloat(val);
    if (val === null || val === undefined || Number.isNaN(n)) return '—';
    return '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  };

  const fmtDate = (d) => {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric' });
  };

  const getTypeBadge = (type) => {
    const t = (type || '').toLowerCase();
    const cls = t === 'walk_in' ? 'cr-type-walk-in' : 'cr-type-registered';
    return (
      <span className={`cr-type-badge ${cls}`}>
        {TYPE_LABELS[t] ?? type ?? 'Registered'}
      </span>
    );
  };

  const getStatusBadge = (c) => {
    const name   = c.status?.status_name;
    const active = c.status_id === 36 || (!c.status_id && !c.deleted_at);
    return (
      <span className={active ? 'cr-status-active' : 'cr-status-inactive'}>
        {name ?? (active ? 'Active' : 'Inactive')}
      </span>
    );
  };

  const totalCustomers     = meta.total;
  const registeredCount    = customers.filter((c) => c.customer_type === 'registered').length;
  const walkInCount        = customers.filter((c) => c.customer_type === 'walk_in').length;
  const outstandingBalance = customers.reduce((s, c) => s + (parseFloat(c.outstanding_balance) || 0), 0);

  const CustomerForm = () => (
    <form onSubmit={handleFormSubmit} className="cr-form">
      {formError && <div className="cr-form-error" role="alert">{formError}</div>}

      <div className="cr-form-group">
        <label>Type</label>
        <select
          value={formData.customer_type}
          onChange={(e) => setFormData({ ...formData, customer_type: e.target.value })}
        >
          <option value="registered">Registered</option>
          <option value="walk_in">Walk-in</option>
        </select>
      </div>

      <div className="cr-form-group">
        <label>Customer Name *</label>
        <input
          value={formData.customer_name}
          onChange={(e) => setFormData({ ...formData, customer_name: e.target.value })}
          required
        />
      </div>

      <div className="cr-form-group">
        <label>Contact Number</label>
        <input
          value={formData.contact_number}
          onChange={(e) => setFormData({ ...formData, contact_number: e.target.value })}
        />
      </div>

      <div className="cr-form-group">
        <label>Email</label>
        <input
          type="email"
          value={formData.email}
          onChange={(e) => setFormData({ ...formData, email: e.target.value })}
        />
      </div>

      <div className="cr-form-group">
        <label>Company Name</label>
        <input
          value={formData.company_name}
          onChange={(e) => setFormData({ ...formData, company_name: e.target.value })}
        />
      </div>

      <div className="cr-form-group">
        <label>TIN</label>
        <input
          value={formData.tin}
          onChange={(e) => setFormData({ ...formData, tin: e.target.value })}
        />
      </div>

      <div className="cr-form-group">
        <label>Credit Limit</label>
        <input
          type="number"
          min="0"
          step="0.01"
          value={formData.credit_limit}
          onChange={(e) => setFormData({ ...formData, credit_limit: e.target.value })}
        />
      </div>

      <div className="cr-form-group">
        <label>Address</label>
        <input
          value={formData.address}
          onChange={(e) => setFormData({ ...formData, address: e.target.value })}
        />
      </div>

      <div className="cr-form-row">
        <div className="cr-form-group">
          <label>City</label>
          <input
            value={formData.city}
            onChange={(e) => setFormData({ ...formData, city: e.target.value })}
          />
        </div>
        <div className="cr-form-group">
          <label>Province</label>
          <input
            value={formData.province}
            onChange={(e) => setFormData({ ...formData, province: e.target.value })}
          />
        </div>
      </div>

      <div className="cr-form-group">
        <label>Region</label>
        <input
          value={formData.region}
          onChange={(e) => setFormData({ ...formData, region: e.target.value })}
        />
      </div>

      <div className="cr-form-group">
        <label>Notes</label>
        <textarea
          value={formData.notes}
          onChange={(e) => setFormData({ ...formData, notes: e.target.value })}
          rows={2}
        />
      </div>

      {editingCustomer && (
        <div className="cr-form-group">
          <label>Status</label>
          <select
            value={formData.status_id}
            onChange={(e) => setFormData({ ...formData, status_id: parseInt(e.target.value) })}
          >
            <option value={36}>Active</option>
            <option value={37}>Inactive</option>
          </select>
        </div>
      )}

      <div className="cr-form-actions">
        <button
          type="button"
          className="cr-btn-cancel"
          onClick={() => { setAddModalOpen(false); setEditingCustomer(null); }}
        >
          Cancel
        </button>
        <button type="submit" className="cr-btn-submit" disabled={submitting}>
          {submitting ? 'Saving...' : 'Save Customer'}
        </button>
      </div>
    </form>
  );

  return (
    <AdminLayout>
      <div className="cr-page">
        <div className="cr-header-row">
          <div className="cr-header">
            <h1 className="cr-title">Customer Management</h1>
            <p className="cr-subtitle">Manage customer records and account information.</p>
          </div>
          <div className="cr-header-actions">
            <button type="button" className="cr-btn-export" onClick={handleExport}>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="16" height="16">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                <polyline points="7 10 12 15 17 10" />
                <line x1="12" y1="15" x2="12" y2="3" />
              </svg>
              Export CSV
            </button>
            <button type="button" className="cr-btn-add" onClick={openAddModal}>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="16" height="16">
                <line x1="12" y1="5" x2="12" y2="19" />
                <line x1="5" y1="12" x2="19" y2="12" />
              </svg>
              Add Customer
            </button>
          </div>
        </div>

        <div className="cr-kpi-grid">
          <div className="cr-kpi-card">
            <div className="cr-kpi-icon cr-kpi-icon-blue">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
                <circle cx="9" cy="7" r="4" />
                <path d="M23 21v-2a4 4 0 0 0-3-3.87" /><path d="M16 3.13a4 4 0 0 1 0 7.75" />
              </svg>
            </div>
            <span className="cr-kpi-label">Total Customers</span>
            <span className="cr-kpi-value">{totalCustomers}</span>
          </div>
          <div className="cr-kpi-card">
            <div className="cr-kpi-icon cr-kpi-icon-green">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                <circle cx="12" cy="7" r="4" />
              </svg>
            </div>
            <span className="cr-kpi-label">Registered</span>
            <span className="cr-kpi-value">{registeredCount}</span>
          </div>
          <div className="cr-kpi-card">
            <div className="cr-kpi-icon cr-kpi-icon-purple">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
                <polyline points="9 22 9 12 15 12 15 22" />
              </svg>
            </div>
            <span className="cr-kpi-label">Walk-in</span>
            <span className="cr-kpi-value">{walkInCount}</span>
          </div>
          <div className="cr-kpi-card">
            <div className="cr-kpi-icon cr-kpi-icon-orange">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <line x1="12" y1="1" x2="12" y2="23" />
                <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
              </svg>
            </div>
            <span className="cr-kpi-label">Outstanding Balance</span>
            <span className="cr-kpi-value">{formatMoney(outstandingBalance)}</span>
          </div>
        </div>

        <section className="cr-section">
          <h2 className="cr-section-title">All Customers</h2>

          <div className="cr-toolbar">
            <input
              type="text"
              className="cr-search"
              placeholder="Search by name, code, company, contact, or email..."
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
            />
            <select
              className="cr-filter-select"
              value={filterType}
              onChange={(e) => setFilterType(e.target.value)}
            >
              <option value="">All Types</option>
              <option value="registered">Registered</option>
              <option value="walk_in">Walk-in</option>
            </select>
            <select
              className="cr-filter-select"
              value={filterStatus}
              onChange={(e) => setFilterStatus(e.target.value)}
            >
              <option value="">All Status</option>
              <option value="36">Active</option>
              <option value="37">Inactive</option>
            </select>
            <label className="cr-archived-toggle">
              <input
                type="checkbox"
                checked={showArchived}
                onChange={(e) => setShowArchived(e.target.checked)}
              />
              Show Archived
            </label>
          </div>

          {loading && <div className="cr-loading">Loading customers...</div>}
          {error   && <div className="cr-error">Error: {error}</div>}

          {!loading && !error && (
            <>
              <div className="cr-table-wrap">
                <table className="cr-table">
                  <thead>
                    <tr>
                      <th>CODE</th>
                      <th>NAME</th>
                      <th>TYPE</th>
                      <th>CONTACT</th>
                      <th>COMPANY</th>
                      <th>TRANSACTIONS</th>
                      <th>OUTSTANDING</th>
                      <th>STATUS</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    {customers.map((c) => (
                      <tr key={c.customer_id} className={c.deleted_at ? 'cr-row-archived' : ''}>
                        <td className="cr-td-id">{c.customer_code ?? '—'}</td>
                        <td className="cr-td-name">{c.customer_name ?? '—'}</td>
                        <td>{getTypeBadge(c.customer_type)}</td>
                        <td className="cr-td-contact">
                          <span>{c.contact_number ?? '—'}</span>
                          {c.email && <span>{c.email}</span>}
                        </td>
                        <td>{c.company_name ?? '—'}</td>
                        <td className="cr-td-num">{c.issuances_count ?? 0}</td>
                        <td className="cr-td-num">{formatMoney(c.outstanding_balance)}</td>
                        <td>
                          {c.deleted_at
                            ? <span className="cr-status-archived">Archived</span>
                            : getStatusBadge(c)
                          }
                        </td>
                        <td className="cr-td-actions">
                          <button type="button" className="cr-btn-view" onClick={() => setViewingCustomer(c)}>
                            View
                          </button>
                          <button type="button" className="cr-btn-history" onClick={() => openHistory(c)}>
                            History
                          </button>
                          {!c.deleted_at && (
                            <>
                              <button type="button" className="cr-btn-edit" onClick={() => openEditModal(c)}>
                                Edit
                              </button>
                              <ArchiveButton onArchive={() => handleArchive(c.customer_id)} />
                            </>
                          )}
                          {c.deleted_at && (
                            <button type="button" className="cr-btn-restore" onClick={() => handleRestore(c.customer_id)}>
                              Restore
                            </button>
                          )}
                        </td>
                      </tr>
                    ))}
                    {customers.length === 0 && (
                      <tr>
                        <td colSpan={9} className="cr-empty">No customers found.</td>
                      </tr>
                    )}
                  </tbody>
                </table>
              </div>
              <Pagination
                currentPage={currentPage}
                totalPages={meta.last_page}
                onPageChange={setCurrentPage}
                totalItems={meta.total}
                pageSize={PAGE_SIZE}
                label="customers"
              />
            </>
          )}
        </section>

        {/* View details modal */}
        {viewingCustomer && (
          <Modal isOpen={true} title="Customer Details" onClose={() => setViewingCustomer(null)}>
            <div className="cr-view-grid">
              <div className="cr-view-row"><span>Code</span><span>{viewingCustomer.customer_code ?? '—'}</span></div>
              <div className="cr-view-row"><span>Name</span><span>{viewingCustomer.customer_name}</span></div>
              <div className="cr-view-row"><span>Type</span><span>{getTypeBadge(viewingCustomer.customer_type)}</span></div>
              <div className="cr-view-row"><span>Company</span><span>{viewingCustomer.company_name ?? '—'}</span></div>
              <div className="cr-view-row"><span>Contact</span><span>{viewingCustomer.contact_number ?? '—'}</span></div>
              <div className="cr-view-row"><span>Email</span><span>{viewingCustomer.email ?? '—'}</span></div>
              <div className="cr-view-row"><span>Address</span><span>{viewingCustomer.address ?? '—'}</span></div>
              <div className="cr-view-row"><span>City</span><span>{viewingCustomer.city ?? '—'}</span></div>
              <div className="cr-view-row"><span>Province</span><span>{viewingCustomer.province ?? '—'}</span></div>
              <div className="cr-view-row"><span>Region</span><span>{viewingCustomer.region ?? '—'}</span></div>
              <div className="cr-view-row"><span>TIN</span><span>{viewingCustomer.tin ?? '—'}</span></div>
              <div className="cr-view-row"><span>Credit Limit</span><span>{formatMoney(viewingCustomer.credit_limit)}</span></div>
              <div className="cr-view-row"><span>Outstanding</span><span>{formatMoney(viewingCustomer.outstanding_balance)}</span></div>
              <div className="cr-view-row"><span>Transactions</span><span>{viewingCustomer.issuances_count ?? 0}</span></div>
              <div className="cr-view-row"><span>Status</span><span>{getStatusBadge(viewingCustomer)}</span></div>
              {viewingCustomer.notes && (
                <div className="cr-view-row cr-view-row-full"><span>Notes</span><span>{viewingCustomer.notes}</span></div>
              )}
            </div>
          </Modal>
        )}

        {/* History modal */}
        {historyCustomer && (
          <Modal
            isOpen={true}
            title={`Transaction History — ${historyCustomer.customer_name}`}
            onClose={() => { setHistoryCustomer(null); setHistoryData(null); }}
          >
            <div className="cr-history-modal">
              <div className="cr-history-tabs">
                <button
                  type="button"
                  className={`cr-history-tab ${historyTab === 'transactions' ? 'cr-history-tab-active' : ''}`}
                  onClick={() => setHistoryTab('transactions')}
                >
                  Transactions{historyData ? ` (${historyData.total_transactions ?? 0})` : ''}
                </button>
                <button
                  type="button"
                  className={`cr-history-tab ${historyTab === 'warranties' ? 'cr-history-tab-active' : ''}`}
                  onClick={() => setHistoryTab('warranties')}
                >
                  Warranties{historyData ? ` (${historyData.warranty_records?.length ?? 0})` : ''}
                </button>
              </div>

              {historyLoading && <div className="cr-history-loading">Loading history...</div>}
              {historyError  && <div className="cr-history-error">{historyError}</div>}

              {historyData && historyTab === 'transactions' && (
                <div className="cr-history-pane">
                  {(historyData.issuances ?? []).length === 0 ? (
                    <p className="cr-history-empty">No transactions found.</p>
                  ) : (
                    <table className="cr-history-table">
                      <thead>
                        <tr>
                          <th>DR</th><th>Date</th><th>Type</th><th>Items</th><th>Total</th><th>Status</th><th></th>
                        </tr>
                      </thead>
                      <tbody>
                        {(historyData.issuances ?? []).map((iss) => (
                          <React.Fragment key={iss.issuance_id}>
                            <tr
                              className="cr-history-row cr-history-row-click"
                              onClick={() => toggleIssuance(iss.issuance_id)}
                            >
                              <td className="cr-td-mono">{iss.dr_number ?? '—'}</td>
                              <td>{fmtDate(iss.issuance_date)}</td>
                              <td>
                                <span className="cr-type-badge cr-type-registered" style={{ textTransform: 'capitalize' }}>
                                  {iss.issuance_type ?? '—'}
                                </span>
                              </td>
                              <td>{iss.items?.length ?? 0}</td>
                              <td className="cr-td-num">{formatMoney(iss.total_amount)}</td>
                              <td>{iss.status_name ?? '—'}</td>
                              <td className="cr-expand-cell">
                                {expandedIssuances.has(iss.issuance_id) ? '▲' : '▼'}
                              </td>
                            </tr>
                            {expandedIssuances.has(iss.issuance_id) && (
                              <tr>
                                <td colSpan={7} className="cr-history-items-td">
                                  <table className="cr-history-items-table">
                                    <thead>
                                      <tr>
                                        <th>Product</th><th>Brand</th><th>Serial / Qty</th><th>Price</th><th>Warranty</th>
                                      </tr>
                                    </thead>
                                    <tbody>
                                      {(iss.items ?? []).map((it, idx) => (
                                        <tr key={idx}>
                                          <td>{it.product_name ?? '—'}</td>
                                          <td>{it.brand_name ?? '—'}</td>
                                          <td className="cr-td-mono">
                                            {it.serial_number
                                              ? it.serial_number
                                              : `${it.quantity_issued ?? ''} ${it.quantity_unit ?? ''}`.trim() || '—'
                                            }
                                          </td>
                                          <td className="cr-td-num">{formatMoney(it.subtotal)}</td>
                                          <td>
                                            {it.warranty_status ? (
                                              <span className={
                                                it.warranty_status.startsWith('Active') ? 'cr-war-active'
                                                : it.warranty_status === 'Expired'      ? 'cr-war-expired'
                                                : 'cr-war-claimed'
                                              }>{it.warranty_status}</span>
                                            ) : '—'}
                                          </td>
                                        </tr>
                                      ))}
                                    </tbody>
                                  </table>
                                </td>
                              </tr>
                            )}
                          </React.Fragment>
                        ))}
                      </tbody>
                    </table>
                  )}
                </div>
              )}

              {historyData && historyTab === 'warranties' && (
                <div className="cr-history-pane">
                  {(historyData.warranty_records ?? []).length === 0 ? (
                    <p className="cr-history-empty">No warranty records found.</p>
                  ) : (
                    <table className="cr-history-table">
                      <thead>
                        <tr>
                          <th>Serial</th><th>Product</th><th>Period</th><th>End Date</th><th>Days Left</th><th>Status</th>
                        </tr>
                      </thead>
                      <tbody>
                        {(historyData.warranty_records ?? []).map((w) => {
                          const isExpired = w.is_expired;
                          const days      = w.days_remaining;
                          return (
                            <tr key={w.warranty_id}>
                              <td className="cr-td-mono">{w.serial_number ?? '—'}</td>
                              <td>{w.product_name ?? '—'}</td>
                              <td>{w.warranty_duration_label ?? (w.warranty_period_months ? `${w.warranty_period_months} mo` : '—')}</td>
                              <td>{fmtDate(w.warranty_end_date)}</td>
                              <td>
                                {isExpired
                                  ? <span className="cr-war-expired">Expired</span>
                                  : days !== null
                                  ? <span className={days <= 30 ? 'cr-war-expiring' : 'cr-war-active'}>{days}d</span>
                                  : '—'
                                }
                              </td>
                              <td>
                                <span className={
                                  w.status_id === 33 ? 'cr-war-active'
                                  : w.status_id === 34 ? 'cr-war-expired'
                                  : 'cr-war-claimed'
                                }>{w.status_name ?? '—'}</span>
                              </td>
                            </tr>
                          );
                        })}
                      </tbody>
                    </table>
                  )}
                </div>
              )}
            </div>
          </Modal>
        )}

        {addModalOpen && (
          <Modal
            isOpen={true}
            title="Add New Customer"
            onClose={() => { setAddModalOpen(false); setFormError(''); }}
          >
            <div className="cr-modal-body">
              {CustomerForm()}
            </div>
          </Modal>
        )}

        {editingCustomer && (
          <Modal
            isOpen={true}
            title="Edit Customer"
            onClose={() => { setEditingCustomer(null); setFormError(''); }}
          >
            <div className="cr-modal-body">
              {CustomerForm()}
            </div>
          </Modal>
        )}
      </div>
    </AdminLayout>
  );
};

export default CustomerManagement;
