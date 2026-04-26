import React, { useState, useEffect, useCallback, useRef } from 'react';
import AdminLayout from '../components/AdminLayout';
import Modal from '../components/Modal';
import { warrantyClaimsAPI, locationsAPI } from '../services/api';
import { toast } from '../utils/toast';
import '../styles/dashboard_air.css';
import '../styles/warranty_management.css';

const WarrantyManagement = () => {
  const [claims, setClaims] = useState([]);
  const [branches, setBranches] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [searchTerm, setSearchTerm] = useState('');
  const [filterStatus, setFilterStatus] = useState('');
  const [filterBranch, setFilterBranch] = useState('');
  const [currentPage, setCurrentPage] = useState(1);
  const [pagination, setPagination] = useState({ total: 0, last_page: 1 });
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [editingClaim, setEditingClaim] = useState(null);
  const [viewingClaim, setViewingClaim] = useState(null);
  const rowsPerPage = 10;
  const debounceRef = useRef(null);

  const [serialInput, setSerialInput] = useState('');
  const [serialVerified, setSerialVerified] = useState(false);
  const [verifyLoading, setVerifyLoading] = useState(false);

  const [formData, setFormData] = useState({
    customer_name: '', customer_contact: '', item_name: '', serial_number: '', issue: '', branch: '', priority: 'MEDIUM', status: 'Open', technician: '', estimated_date: '', purchase_date: ''
  });

  // Load locations once for dropdown
  useEffect(() => {
    locationsAPI.getAll({ per_page: 200 }).then(res => {
      setBranches(Array.isArray(res?.data) ? res.data : []);
    }).catch(() => {});
  }, []);

  const fetchClaims = useCallback(async (page = 1) => {
    try {
      setLoading(true);
      const params = { page, per_page: rowsPerPage };
      if (searchTerm) params.search = searchTerm;
      if (filterStatus) params.status = filterStatus;
      if (filterBranch) params.branch = filterBranch;
      const res = await warrantyClaimsAPI.getAll(params);
      setClaims(Array.isArray(res.data) ? res.data : []);
      setPagination(res.pagination || { total: 0, last_page: 1 });
      setError(null);
    } catch (err) {
      setError(err.message);
      console.error('Failed to fetch warranty claims:', err);
    } finally {
      setLoading(false);
    }
  }, [searchTerm, filterStatus, filterBranch, rowsPerPage]);

  useEffect(() => {
    fetchClaims(currentPage);
  }, [currentPage, fetchClaims]);

  // Debounce search/filter changes
  useEffect(() => {
    if (debounceRef.current) clearTimeout(debounceRef.current);
    debounceRef.current = setTimeout(() => {
      setCurrentPage(1);
    }, 400);
    return () => clearTimeout(debounceRef.current);
  }, [searchTerm, filterStatus, filterBranch]);

  const handleVerifySerial = async () => {
    if (!serialInput.trim()) return;
    setVerifyLoading(true);
    try {
      // Try to look up warranty/product info by serial number
      const res = await warrantyClaimsAPI.getAll({ search: serialInput.trim(), per_page: 1 });
      const found = Array.isArray(res.data) && res.data.length > 0 ? res.data[0] : null;
      if (found) {
        setFormData(prev => ({
          ...prev,
          serial_number: serialInput.trim(),
          customer_name: found.customer_name || '',
          purchase_date: found.purchase_date || found.created_at?.split('T')[0] || '',
        }));
      } else {
        setFormData(prev => ({ ...prev, serial_number: serialInput.trim() }));
      }
      setSerialVerified(true);
    } catch {
      setFormData(prev => ({ ...prev, serial_number: serialInput.trim() }));
      setSerialVerified(true);
    } finally {
      setVerifyLoading(false);
    }
  };

  const handleAdd = () => {
    setEditingClaim(null);
    setSerialInput('');
    setSerialVerified(false);
    setFormData({ customer_name: '', customer_contact: '', item_name: '', serial_number: '', issue: '', branch: '', priority: 'MEDIUM', status: 'Open', technician: '', estimated_date: '', purchase_date: '' });
    setIsModalOpen(true);
  };

  const handleEdit = (id) => {
    const claim = claims.find(c => c.id === id);
    if (claim) {
      setEditingClaim(claim);
      setFormData({
        customer_name: claim.customer_name || '', customer_contact: claim.customer_contact || '', item_name: claim.item_name || '', serial_number: claim.serial_number || '', issue: claim.issue || '', branch: claim.branch || '', priority: claim.priority || 'MEDIUM', status: claim.status || 'Open', technician: claim.technician || '', estimated_date: claim.estimated_date ? claim.estimated_date.split('T')[0] : ''
      });
      setIsModalOpen(true);
    }
  };

  const handleView = (id) => {
    const claim = claims.find(c => c.id === id);
    setViewingClaim(claim);
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    try {
      if (editingClaim) {
        await warrantyClaimsAPI.update(editingClaim.id, formData);
        toast.success('Warranty claim updated successfully!');
      } else {
        await warrantyClaimsAPI.create(formData);
        toast.success('Warranty claim created successfully!');
      }
      setIsModalOpen(false);
      fetchClaims(currentPage);
    } catch (err) {
      toast.error('Error: ' + err.message);
    }
  };

  const totalClaims = pagination.total || 0;
  const totalPages = pagination.last_page || 1;
  const openClaims = claims.filter(c => c.status === 'Open').length;
  const inRepair = claims.filter(c => c.status === 'In-Repair').length;
  const completed = claims.filter(c => c.status === 'Completed').length;
  const criticalPriority = claims.filter(c => c.priority === 'CRITICAL').length;
  const closedClaims = claims.filter(c => c.status === 'Closed').length;

  const criticalClaims = claims.filter(c => c.priority === 'CRITICAL');
  const recentActivity = [...claims].sort((a, b) => new Date(b.created_at || b.dateCreated) - new Date(a.created_at || a.dateCreated)).slice(0, 3);

  const branchNames = [...new Set(claims.map(c => c.branch).filter(Boolean))];

  const getPriorityClass = (priority) => {
    switch (priority) {
      case 'CRITICAL': return 'war-priority-critical';
      case 'HIGH': return 'war-priority-high';
      case 'MEDIUM': return 'war-priority-medium';
      case 'LOW': return 'war-priority-low';
      default: return '';
    }
  };

  const getStatusClass = (status) => {
    switch (status) {
      case 'Open': return 'war-status-open';
      case 'In-Repair': return 'war-status-repair';
      case 'Completed': return 'war-status-completed';
      case 'Closed': return 'war-status-closed';
      default: return '';
    }
  };

  const getStatusIcon = (status) => {
    switch (status) {
      case 'Open': return '○';
      case 'In-Repair': return '⟳';
      case 'Completed': return '✓';
      case 'Closed': return '✕';
      default: return '';
    }
  };

  const formatDate = (dateStr) => {
    if (!dateStr) return 'N/A';
    return dateStr.split('T')[0];
  };

  return (
    <AdminLayout searchPlaceholder="Search warranty claims..." onSearch={() => {}}>
      <div className="war-page-header">
        <div className="war-page-header-left">
          <h1>Warranty Management</h1>
          <p>Manage warranty claims, repairs, and customer service requests</p>
        </div>
        <button className="war-btn-create" onClick={handleAdd}>+ Create Warranty Claim</button>
      </div>

      {loading && <div className="loading-message">Loading warranty claims...</div>}
      {error && <div className="error-message">Error: {error}</div>}

      {/* Stats Cards */}
      <div className="war-stats-row">
        <div className="war-stat-card">
          <div className="war-stat-icon war-stat-icon-blue">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" strokeWidth="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
          </div>
          <div className="war-stat-info">
            <span className="war-stat-label">Total Claims</span>
            <span className="war-stat-number">{totalClaims}</span>
          </div>
        </div>
        <div className="war-stat-card">
          <div className="war-stat-icon war-stat-icon-blue">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" strokeWidth="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          </div>
          <div className="war-stat-info">
            <span className="war-stat-label">Open (this page)</span>
            <span className="war-stat-number">{openClaims}</span>
          </div>
        </div>
        <div className="war-stat-card">
          <div className="war-stat-icon war-stat-icon-orange">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" strokeWidth="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          </div>
          <div className="war-stat-info">
            <span className="war-stat-label">In Repair (this page)</span>
            <span className="war-stat-number">{inRepair}</span>
          </div>
        </div>
        <div className="war-stat-card">
          <div className="war-stat-icon war-stat-icon-green">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#22c55e" strokeWidth="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
          </div>
          <div className="war-stat-info">
            <span className="war-stat-label">Completed (this page)</span>
            <span className="war-stat-number">{completed}</span>
          </div>
        </div>
        <div className="war-stat-card">
          <div className="war-stat-icon war-stat-icon-red">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#ef4444" strokeWidth="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
          </div>
          <div className="war-stat-info">
            <span className="war-stat-label">Critical (this page)</span>
            <span className="war-stat-number">{criticalPriority}</span>
          </div>
        </div>
      </div>

      {/* Filters */}
      <div className="war-filters-card">
        <div className="war-filters-header">
          <h3>Filters</h3>
          <p>Filter warranty claims by status, branch, or search</p>
        </div>
        <div className="war-filters-row">
          <div className="war-filter-group">
            <label>Search</label>
            <div className="war-search-input">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" strokeWidth="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="text" placeholder="Search by warranty #, customer, serial..." value={searchTerm} onChange={(e) => setSearchTerm(e.target.value)} />
            </div>
          </div>
          <div className="war-filter-group">
            <label>Status</label>
            <select value={filterStatus} onChange={(e) => setFilterStatus(e.target.value)}>
              <option value="">All Status</option>
              <option value="Open">Open</option>
              <option value="In-Repair">In-Repair</option>
              <option value="Completed">Completed</option>
              <option value="Closed">Closed</option>
            </select>
          </div>
          <div className="war-filter-group">
            <label>Branch</label>
            <select value={filterBranch} onChange={(e) => setFilterBranch(e.target.value)}>
              <option value="">All Branches</option>
              {branchNames.map(name => (
                <option key={name} value={name}>{name}</option>
              ))}
            </select>
          </div>
        </div>
      </div>

      {/* Warranty Claims Table */}
      <div className="war-table-card">
        <div className="war-table-header">
          <h3>Warranty Claims</h3>
          <span className="war-table-count">{totalClaims} claims total</span>
        </div>
        <div className="war-table-wrapper">
          <table className="war-table">
            <thead>
              <tr>
                <th>WARRANTY</th>
                <th>CUSTOMER</th>
                <th>ITEM</th>
                <th>SERIAL</th>
                <th>ISSUE</th>
                <th>BRANCH</th>
                <th>PRIORITY</th>
                <th>STATUS</th>
                <th>TECHNICIAN</th>
                <th>ACTIONS</th>
              </tr>
            </thead>
            <tbody>
              {claims.length === 0 ? (
                <tr><td colSpan="10" style={{ textAlign: 'center', color: '#999', padding: '40px' }}>{loading ? 'Loading...' : 'No warranty claims found'}</td></tr>
              ) : (
                claims.map(claim => (
                  <tr key={claim.id}>
                    <td className="war-cell-warranty">{claim.warranty_code}</td>
                    <td>
                      <div className="war-cell-customer">
                        <span className="war-customer-name">{claim.customer_name}</span>
                        <span className="war-customer-contact">{claim.customer_contact}</span>
                      </div>
                    </td>
                    <td className="war-cell-item">{claim.item_name}</td>
                    <td className="war-cell-serial">{claim.serial_number}</td>
                    <td className="war-cell-issue">{claim.issue}</td>
                    <td>{claim.branch}</td>
                    <td><span className={`war-priority-badge ${getPriorityClass(claim.priority)}`}>{claim.priority}</span></td>
                    <td>
                      <span className={`war-status-badge ${getStatusClass(claim.status)}`}>
                        <span className="war-status-icon">{getStatusIcon(claim.status)}</span> {claim.status}
                      </span>
                    </td>
                    <td className="war-cell-technician">{claim.technician}</td>
                    <td>
                      <div className="war-cell-actions">
                        <button className="war-btn-view" onClick={() => handleView(claim.id)}>View</button>
                        {claim.status === 'Open' && <button className="war-btn-assign" onClick={() => handleEdit(claim.id)}>Assign</button>}
                      </div>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
        {/* Pagination */}
        {totalPages > 1 && (
          <div className="pagination" style={{ padding: '16px', display: 'flex', justifyContent: 'center', gap: '4px' }}>
            <button disabled={currentPage === 1} onClick={() => setCurrentPage(currentPage - 1)}>Previous</button>
            {Array.from({ length: totalPages }, (_, i) => i + 1).map(page => (
              <button key={page} className={currentPage === page ? 'active' : ''} onClick={() => setCurrentPage(page)}>{page}</button>
            ))}
            <button disabled={currentPage === totalPages} onClick={() => setCurrentPage(currentPage + 1)}>Next</button>
          </div>
        )}
      </div>

      {/* Bottom Section: Critical Priority + Recent Activity */}
      <div className="war-bottom-grid">
        <div className="war-bottom-card">
          <div className="war-bottom-card-header">
            <h3>Critical Priority Claims</h3>
            <p>{criticalClaims.length} claims need immediate attention</p>
          </div>
          <div className="war-critical-list">
            {criticalClaims.length === 0 ? (
              <p style={{ color: '#999', padding: '20px', textAlign: 'center' }}>No critical claims</p>
            ) : (
              criticalClaims.map(claim => (
                <div className="war-critical-item" key={claim.id}>
                  <div className="war-critical-bar"></div>
                  <div className="war-critical-content">
                    <div className="war-critical-top">
                      <span className="war-critical-id">{claim.warranty_code}</span>
                      <span className="war-critical-alert">⚠</span>
                    </div>
                    <span className="war-critical-detail">{claim.customer_name} • {claim.item_name}</span>
                    <span className="war-critical-issue">🔧 {claim.issue}</span>
                    <div className="war-critical-bottom">
                      <span className="war-critical-branch">{claim.branch}</span>
                      <span className="war-critical-date">📅 Est. {formatDate(claim.estimated_date)}</span>
                    </div>
                  </div>
                </div>
              ))
            )}
          </div>
        </div>

        <div className="war-bottom-card">
          <div className="war-bottom-card-header">
            <h3>Recent Warranty Activity</h3>
            <p>Latest warranty claims and updates</p>
          </div>
          <div className="war-activity-list">
            {recentActivity.length === 0 ? (
              <p style={{ color: '#999', padding: '20px', textAlign: 'center' }}>No recent activity</p>
            ) : (
              recentActivity.map(claim => (
                <div className="war-activity-item" key={claim.id}>
                  <div className="war-activity-content">
                    <div className="war-activity-top">
                      <span className="war-activity-id">{claim.warranty_code}</span>
                      <span className={`war-priority-badge ${getPriorityClass(claim.priority)}`}>{claim.priority}</span>
                    </div>
                    <span className="war-activity-customer">{claim.customer_name}</span>
                    <span className="war-activity-item-name">{claim.item_name}</span>
                    <div className="war-activity-bottom">
                      <span className="war-activity-date">{formatDate(claim.created_at)}</span>
                      <span className={`war-status-badge ${getStatusClass(claim.status)}`}>
                        <span className="war-status-icon">{getStatusIcon(claim.status)}</span> {claim.status}
                      </span>
                    </div>
                  </div>
                </div>
              ))
            )}
          </div>
        </div>
      </div>

      {/* Warranty Status Summary */}
      <div className="war-summary-card">
        <div className="war-summary-header">
          <h3>Warranty Status Summary</h3>
          <p>Overview by status (this page)</p>
        </div>
        <div className="war-summary-grid">
          <div className="war-summary-item war-summary-blue">
            <div className="war-summary-item-left">
              <div className="war-summary-icon">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" strokeWidth="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
              </div>
              <div>
                <span className="war-summary-label">Open Claims</span>
                <span className="war-summary-desc">Awaiting technician assignment</span>
              </div>
            </div>
            <span className="war-summary-number">{openClaims}</span>
          </div>
          <div className="war-summary-item war-summary-orange">
            <div className="war-summary-item-left">
              <div className="war-summary-icon">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" strokeWidth="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
              </div>
              <div>
                <span className="war-summary-label">In Repair</span>
                <span className="war-summary-desc">Currently being serviced</span>
              </div>
            </div>
            <span className="war-summary-number">{inRepair}</span>
          </div>
          <div className="war-summary-item war-summary-green">
            <div className="war-summary-item-left">
              <div className="war-summary-icon">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#22c55e" strokeWidth="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
              </div>
              <div>
                <span className="war-summary-label">Completed</span>
                <span className="war-summary-desc">Repairs finished this month</span>
              </div>
            </div>
            <span className="war-summary-number">{completed}</span>
          </div>
          <div className="war-summary-item war-summary-gray">
            <div className="war-summary-item-left">
              <div className="war-summary-icon">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#6b7280" strokeWidth="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
              </div>
              <div>
                <span className="war-summary-label">Closed</span>
                <span className="war-summary-desc">Cases resolved and closed</span>
              </div>
            </div>
            <span className="war-summary-number">{closedClaims}</span>
          </div>
        </div>
      </div>

      {/* View Modal */}
      {viewingClaim && (
        <Modal isOpen={!!viewingClaim} onClose={() => setViewingClaim(null)} title="Warranty Claim Details">
          <div className="war-view-details">
            <div className="form-row">
              <div className="form-group"><label>Warranty #</label><p>{viewingClaim.warranty_code}</p></div>
              <div className="form-group"><label>Status</label><p><span className={`war-status-badge ${getStatusClass(viewingClaim.status)}`}><span className="war-status-icon">{getStatusIcon(viewingClaim.status)}</span> {viewingClaim.status}</span></p></div>
            </div>
            <div className="form-row">
              <div className="form-group"><label>Customer</label><p>{viewingClaim.customer_name}</p></div>
              <div className="form-group"><label>Contact</label><p>{viewingClaim.customer_contact}</p></div>
            </div>
            <div className="form-group"><label>Item</label><p>{viewingClaim.item_name}</p></div>
            <div className="form-row">
              <div className="form-group"><label>Serial</label><p>{viewingClaim.serial_number}</p></div>
              <div className="form-group"><label>Branch</label><p>{viewingClaim.branch}</p></div>
            </div>
            <div className="form-group"><label>Issue</label><p>{viewingClaim.issue}</p></div>
            <div className="form-row">
              <div className="form-group"><label>Priority</label><p><span className={`war-priority-badge ${getPriorityClass(viewingClaim.priority)}`}>{viewingClaim.priority}</span></p></div>
              <div className="form-group"><label>Technician</label><p>{viewingClaim.technician || 'Unassigned'}</p></div>
            </div>
            <div className="form-row">
              <div className="form-group"><label>Date Created</label><p>{formatDate(viewingClaim.created_at)}</p></div>
              <div className="form-group"><label>Estimated Completion</label><p>{formatDate(viewingClaim.estimated_date)}</p></div>
            </div>
            <div className="modal-footer">
              <button type="button" className="btn-cancel" onClick={() => setViewingClaim(null)}>Close</button>
              <button type="button" className="btn-save" onClick={() => { setViewingClaim(null); handleEdit(viewingClaim.id); }}>Edit Claim</button>
            </div>
          </div>
        </Modal>
      )}

      {/* File Warranty Claim Modal */}
      <Modal isOpen={isModalOpen} onClose={() => setIsModalOpen(false)} title={editingClaim ? 'EDIT WARRANTY CLAIM' : 'FILE WARRANTY CLAIM'}>
        <div className="war-modal-body">
          {/* Warning Notice */}
          {!editingClaim && (
            <div className="war-modal-notice">
              <svg viewBox="0 0 24 24" fill="none" width="18" height="18">
                <circle cx="12" cy="12" r="10" fill="#dc2626"/>
                <line x1="12" y1="8" x2="12" y2="13" stroke="#fff" strokeWidth="2" strokeLinecap="round"/>
                <circle cx="12" cy="16.5" r="1" fill="#fff"/>
              </svg>
              <span>Verify product warranty status by serial number before proceeding with the claim filing.</span>
            </div>
          )}

          <form onSubmit={handleSubmit}>
            {/* Product Serial Number */}
            <div className="war-modal-field">
              <label className="war-modal-label">PRODUCT SERIAL NUMBER</label>
              <div className="war-serial-row">
                <input
                  type="text"
                  className="war-modal-input"
                  placeholder="Scan or enter SN..."
                  value={editingClaim ? formData.serial_number : serialInput}
                  onChange={(e) => {
                    if (editingClaim) {
                      setFormData({...formData, serial_number: e.target.value});
                    } else {
                      setSerialInput(e.target.value);
                      setSerialVerified(false);
                    }
                  }}
                />
                {!editingClaim && (
                  <button
                    type="button"
                    className="war-btn-verify"
                    onClick={handleVerifySerial}
                    disabled={verifyLoading || !serialInput.trim()}
                  >
                    {verifyLoading ? '...' : 'VERIFY'}
                  </button>
                )}
              </div>
            </div>

            {/* Customer Name & Purchase Date */}
            <div className="war-modal-row">
              <div className="war-modal-field">
                <label className="war-modal-label">CUSTOMER NAME</label>
                <input
                  type="text"
                  className="war-modal-input"
                  placeholder="Auto-populated..."
                  value={formData.customer_name}
                  onChange={(e) => setFormData({...formData, customer_name: e.target.value})}
                  required
                />
              </div>
              <div className="war-modal-field">
                <label className="war-modal-label">PURCHASE DATE</label>
                <input
                  type="text"
                  className="war-modal-input"
                  placeholder="Auto-populated..."
                  value={formData.purchase_date}
                  onChange={(e) => setFormData({...formData, purchase_date: e.target.value})}
                />
              </div>
            </div>

            {/* Issue Description */}
            <div className="war-modal-field">
              <label className="war-modal-label">ISSUE DESCRIPTION</label>
              <textarea
                className="war-modal-textarea"
                placeholder="Describe the defect or technical issue..."
                value={formData.issue}
                onChange={(e) => setFormData({...formData, issue: e.target.value})}
                rows={3}
                required
              />
            </div>

            {/* Footer */}
            <div className="war-modal-footer">
              <button type="button" className="war-modal-cancel" onClick={() => setIsModalOpen(false)}>CANCEL</button>
              <button type="submit" className="war-modal-submit">{editingClaim ? 'UPDATE CLAIM' : 'SUBMIT CLAIM'}</button>
            </div>
          </form>
        </div>
      </Modal>
    </AdminLayout>
  );
};

export default WarrantyManagement;
