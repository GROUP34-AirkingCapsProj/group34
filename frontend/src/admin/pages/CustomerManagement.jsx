import React, { useState, useEffect } from 'react';
import AdminLayout from '../components/AdminLayout';
import Modal from '../components/Modal';
import { customersAPI, branchesAPI } from '../services/api';
import '../styles/dashboard_air.css';
import '../styles/customer_registry.css';

const formatCustomerId = (id) => {
  if (id == null) return '—';
  const year = new Date().getFullYear();
  return `CUST-${year}-${String(id).padStart(3, '0')}`;
};

const CustomerManagement = () => {
  const [customers, setCustomers] = useState([]);
  const [branches, setBranches] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [searchTerm, setSearchTerm] = useState('');
  const [filterType, setFilterType] = useState('');
  const [filterStatus, setFilterStatus] = useState('');
  const [viewingCustomer, setViewingCustomer] = useState(null);
  const [addModalOpen, setAddModalOpen] = useState(false);
  const [formData, setFormData] = useState({
    customer_type: 'Ordinary',
    customer_name: '',
    contact_number: '',
    email: '',
    company_name: '',
    address: '',
    city: '',
    region: '',
    credit_limit: '',
  });
  const [submitting, setSubmitting] = useState(false);
  const [addError, setAddError] = useState('');

  const fetchCustomers = async () => {
    try {
      setLoading(true);
      const params = { per_page: 200 };
      if (filterType) params.customer_type = filterType;
      if (filterStatus === 'active') params.is_active = 'true';
      else if (filterStatus === 'inactive') params.is_active = 'false';
      const res = await customersAPI.getAll(params);
      const list = Array.isArray(res?.data) ? res.data : res?.data?.data ?? [];
      setCustomers(list);
      setError(null);
    } catch (err) {
      setError(err.message);
      setCustomers([]);
    } finally {
      setLoading(false);
    }
  };

  const fetchBranches = async () => {
    try {
      const res = await branchesAPI.getAll({ per_page: 100 });
      const list = Array.isArray(res?.data) ? res.data : res?.data?.data ?? [];
      setBranches(list);
    } catch {
      setBranches([]);
    }
  };

  useEffect(() => {
    fetchCustomers();
    fetchBranches();
  }, [filterType, filterStatus]);

  const filteredCustomers = customers.filter((c) => {
    const term = (searchTerm || '').toLowerCase();
    if (!term) return true;
    const name = (c.customer_name || '').toLowerCase();
    const email = (c.email || '').toLowerCase();
    const phone = (c.contact_number || '').replace(/\s/g, '');
    const searchPhone = term.replace(/\s/g, '');
    return name.includes(term) || email.includes(term) || phone.includes(searchPhone);
  });

  const totalCustomers = filteredCustomers.length;
  const individualCount = filteredCustomers.filter((c) => (c.customer_type || '').toLowerCase() === 'ordinary' || (c.customer_type || '') === 'Individual').length;
  const businessCount = filteredCustomers.filter((c) => (c.customer_type || '').toLowerCase() === 'business').length;
  const activeCount = filteredCustomers.filter((c) => c.is_active !== false).length;
  const outstandingBalance = filteredCustomers.reduce(
    (sum, c) => sum + (parseFloat(c.outstanding_balance) || 0),
    0
  );

  const businessCustomers = filteredCustomers.filter((c) => (c.customer_type || '').toLowerCase() === 'business');

  const handleAddSubmit = async (e) => {
    e.preventDefault();
    if (!formData.customer_name?.trim() || !formData.contact_number?.trim()) return;
    setSubmitting(true);
    setAddError('');
    try {
      const payload = {
        ...formData,
        address: formData.address?.trim() || '—',
        credit_limit: formData.customer_type === 'Business' && formData.credit_limit !== '' ? parseFloat(formData.credit_limit) || null : null,
      };
      await customersAPI.create(payload);
      setAddModalOpen(false);
      setFormData({ customer_type: 'Ordinary', customer_name: '', contact_number: '', email: '', company_name: '', address: '', city: '', region: '', credit_limit: '' });
      await fetchCustomers();
    } catch (err) {
      const msg = err.errors ? (Object.values(err.errors).flat()[0] || err.message) : err.message;
      setAddError(msg || 'Failed to add customer');
    } finally {
      setSubmitting(false);
    }
  };

  const openAddModal = () => {
    setAddError('');
    setFormData({ customer_type: 'Ordinary', customer_name: '', contact_number: '', email: '', company_name: '', address: '', city: '', region: '', credit_limit: '' });
    setAddModalOpen(true);
  };

  const formatMoney = (val) => {
    const n = parseFloat(val);
    if (val === null || val === undefined || Number.isNaN(n)) return '—';
    return '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  };

  const getTypeBadge = (type) => {
    const t = (type || '').toLowerCase();
    const isBusiness = t === 'business';
    return (
      <span className={`cr-type-badge ${isBusiness ? 'cr-type-business' : 'cr-type-individual'}`}>
        {isBusiness ? 'Business' : 'Individual'}
      </span>
    );
  };

  const getBranchDisplay = (c) => {
    return c.region || c.city || (branches[0]?.location_name || branches[0]?.name) || '—';
  };

  return (
    <AdminLayout>
      <div className="cr-page">
        <div className="cr-header-row">
          <div className="cr-header">
            <h1 className="cr-title">Customer Management</h1>
            <p className="cr-subtitle">Manage customer records and account information.</p>
          </div>
          <button type="button" className="cr-btn-add" onClick={openAddModal}>
            + Add New Customer
          </button>
        </div>

        <div className="cr-kpi-grid">
          <div className="cr-kpi-card">
            <div className="cr-kpi-icon cr-kpi-icon-blue">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
                <circle cx="9" cy="7" r="4" />
                <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
                <path d="M16 3.13a4 4 0 0 1 0 7.75" />
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
            <span className="cr-kpi-label">Individual Customers</span>
            <span className="cr-kpi-value">{individualCount}</span>
          </div>
          <div className="cr-kpi-card">
            <div className="cr-kpi-icon cr-kpi-icon-purple">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
                <polyline points="9 22 9 12 15 12 15 22" />
              </svg>
            </div>
            <span className="cr-kpi-label">Business Customers</span>
            <span className="cr-kpi-value">{businessCount}</span>
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
          <p className="cr-section-meta">{totalCustomers} total customers • {activeCount} active</p>

          <div className="cr-toolbar">
            <input
              type="text"
              className="cr-search"
              placeholder="Search customers by name, email, or phone..."
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
            />
            <select
              className="cr-filter-select"
              value={filterType}
              onChange={(e) => setFilterType(e.target.value)}
            >
              <option value="">All Types</option>
              <option value="Ordinary">Individual</option>
              <option value="Business">Business</option>
            </select>
            <select
              className="cr-filter-select"
              value={filterStatus}
              onChange={(e) => setFilterStatus(e.target.value)}
            >
              <option value="">All Status</option>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>

          {loading && <div className="cr-loading">Loading customers...</div>}
          {error && <div className="cr-error">Error: {error}</div>}

          {!loading && !error && (
            <div className="cr-table-wrap">
              <table className="cr-table">
                <thead>
                  <tr>
                    <th>CUSTOMER ID</th>
                    <th>NAME</th>
                    <th>TYPE</th>
                    <th>BRANCH</th>
                    <th>CONTACT</th>
                    <th>TOTAL PURCHASES</th>
                    <th>OUTSTANDING</th>
                    <th>STATUS</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  {filteredCustomers.map((c) => (
                    <tr key={c.id}>
                      <td className="cr-td-id">{formatCustomerId(c.id)}</td>
                      <td className="cr-td-name">{c.customer_name || '—'}</td>
                      <td>{getTypeBadge(c.customer_type)}</td>
                      <td>{getBranchDisplay(c)}</td>
                      <td className="cr-td-contact">
                        <span>{c.contact_number || '—'}</span>
                        {c.email && <span>{c.email}</span>}
                      </td>
                      <td className="cr-td-num">{c.pos_delivery_receipts_count ?? 0}</td>
                      <td className="cr-td-num">{formatMoney(c.outstanding_balance)}</td>
                      <td>
                        <span className={c.is_active !== false ? 'cr-status-active' : 'cr-status-inactive'}>
                          {c.is_active !== false ? 'Active' : 'Inactive'}
                        </span>
                      </td>
                      <td>
                        <button
                          type="button"
                          className="cr-btn-view"
                          onClick={() => setViewingCustomer(c)}
                        >
                          View
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>

        {businessCustomers.length > 0 && (
          <section className="cr-business-section">
            <h2 className="cr-business-title">Business Customers</h2>
            <p className="cr-business-subtitle">Customers with credit terms</p>
            <div className="cr-business-grid">
              {businessCustomers.map((c) => (
                <div key={c.id} className="cr-business-card">
                  <div className="cr-business-card-head">
                    <div>
                      <h3 className="cr-business-name">{c.customer_name || '—'}</h3>
                      <p className="cr-business-company">{c.company_name || '—'}</p>
                    </div>
                    <span className="cr-business-badge">Active</span>
                  </div>
                  <div className="cr-business-details">
                    <div className="cr-business-row">
                      <span>Branch:</span>
                      <span>{getBranchDisplay(c)}</span>
                    </div>
                    <div className="cr-business-row">
                      <span>Credit Limit:</span>
                      <span>{formatMoney(c.credit_limit)}</span>
                    </div>
                    <div className="cr-business-row cr-outstanding">
                      <span>Outstanding:</span>
                      <span>{formatMoney(c.outstanding_balance)}</span>
                    </div>
                    <div className="cr-business-row">
                      <span>Purchases:</span>
                      <span>{c.pos_delivery_receipts_count ?? 0}</span>
                    </div>
                  </div>
                  <button
                    type="button"
                    className="cr-btn-full-details"
                    onClick={() => setViewingCustomer(c)}
                  >
                    View Full Details
                  </button>
                </div>
              ))}
            </div>
          </section>
        )}

        {viewingCustomer && (
          <Modal isOpen={true} title="Customer Details" onClose={() => setViewingCustomer(null)}>
            <div className="cr-view-grid">
              <div className="cr-view-row"><span>Customer ID</span><span>{formatCustomerId(viewingCustomer.id)}</span></div>
              <div className="cr-view-row"><span>Name</span><span>{viewingCustomer.customer_name}</span></div>
              <div className="cr-view-row"><span>Type</span><span>{getTypeBadge(viewingCustomer.customer_type)}</span></div>
              <div className="cr-view-row"><span>Company</span><span>{viewingCustomer.company_name || '—'}</span></div>
              <div className="cr-view-row"><span>Contact</span><span>{viewingCustomer.contact_number}</span></div>
              <div className="cr-view-row"><span>Email</span><span>{viewingCustomer.email || '—'}</span></div>
              <div className="cr-view-row"><span>Address</span><span>{viewingCustomer.address || '—'}</span></div>
              <div className="cr-view-row"><span>Purchases</span><span>{viewingCustomer.pos_delivery_receipts_count ?? 0}</span></div>
            </div>
          </Modal>
        )}

        {addModalOpen && (
          <Modal
            isOpen={true}
            title="Add New Customer"
            onClose={() => { setAddModalOpen(false); setAddError(''); }}
          >
            <div className="cr-modal-body">
              {addError && (
                <div className="cr-form-error" role="alert">
                  {addError}
                </div>
              )}
              <form onSubmit={handleAddSubmit} className="cr-form">
                <div className="cr-form-group">
                <label>Type</label>
                <select
                  value={formData.customer_type}
                  onChange={(e) => setFormData({ ...formData, customer_type: e.target.value })}
                >
                  <option value="Ordinary">Individual</option>
                  <option value="Business">Business</option>
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
              {formData.customer_type === 'Business' && (
                <>
                  <div className="cr-form-group">
                    <label>Company Name</label>
                    <input
                      value={formData.company_name}
                      onChange={(e) => setFormData({ ...formData, company_name: e.target.value })}
                    />
                  </div>
                  <div className="cr-form-group">
                    <label>Credit Limit (₱)</label>
                    <input
                      type="number"
                      min="0"
                      step="0.01"
                      placeholder="e.g. 500000"
                      value={formData.credit_limit}
                      onChange={(e) => setFormData({ ...formData, credit_limit: e.target.value })}
                    />
                  </div>
                </>
              )}
              <div className="cr-form-group">
                <label>Contact Number *</label>
                <input
                  value={formData.contact_number}
                  onChange={(e) => setFormData({ ...formData, contact_number: e.target.value })}
                  required
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
                <label>Address *</label>
                <input
                  value={formData.address}
                  onChange={(e) => setFormData({ ...formData, address: e.target.value })}
                />
              </div>
              <div className="cr-form-group">
                <label>City / Region</label>
                <input
                  value={formData.region}
                  onChange={(e) => setFormData({ ...formData, region: e.target.value })}
                  placeholder="City or Region"
                />
              </div>
                <div className="cr-form-actions">
                  <button type="button" className="cr-btn-cancel" onClick={() => setAddModalOpen(false)}>Cancel</button>
                  <button type="submit" className="cr-btn-submit" disabled={submitting}>
                    {submitting ? 'Saving...' : 'Save Customer'}
                  </button>
                </div>
              </form>
            </div>
          </Modal>
        )}
      </div>
    </AdminLayout>
  );
};

export default CustomerManagement;
