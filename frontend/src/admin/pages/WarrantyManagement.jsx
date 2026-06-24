import React, { useState, useEffect, useCallback, useRef } from 'react';
import AdminLayout from '../components/AdminLayout';
import Loader from '../components/Loader';
import Modal from '../components/Modal';
import { warrantiesAPI, getApiBaseUrl, getToken } from '../services/api';
import { toast } from '../utils/toast';
import '../styles/dashboard_air.css';
import '../styles/warranty_management.css';

const fmtDate = (d) => {
  if (!d) return '—';
  return new Date(d).toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' });
};

const daysRemaining = (endDate) => {
  if (!endDate) return null;
  return Math.ceil((new Date(endDate) - new Date()) / 86400000);
};

const StatusBadge = ({ statusId }) => {
  if (statusId === 33) return <span className="war-status-badge war-status-active">Active</span>;
  if (statusId === 34) return <span className="war-status-badge war-status-expired">Expired</span>;
  if (statusId === 35) return <span className="war-status-badge war-status-claimed">Claimed</span>;
  return <span className="war-status-badge">{statusId}</span>;
};

const DaysChip = ({ endDate, statusId }) => {
  if (statusId === 35) return <span className="war-days war-days-claimed">Claimed</span>;
  if (statusId === 34) return <span className="war-days war-days-expired">Expired</span>;
  const days = daysRemaining(endDate);
  if (days === null) return null;
  if (days < 0)   return <span className="war-days war-days-expired">{Math.abs(days)}d ago</span>;
  if (days <= 30) return <span className="war-days war-days-danger">{days}d left</span>;
  if (days <= 90) return <span className="war-days war-days-warn">{days}d left</span>;
  return <span className="war-days war-days-ok">{days}d left</span>;
};

const WarrantyManagement = () => {
  const [warranties, setWarranties]     = useState([]);
  const [loading, setLoading]           = useState(true);
  const [error, setError]               = useState(null);
  const [searchTerm, setSearchTerm]     = useState('');
  const [filterStatus, setFilterStatus] = useState('');
  const [expiringSoon, setExpiringSoon] = useState(false);
  const [currentPage, setCurrentPage]   = useState(1);
  const [pagination, setPagination]     = useState({ total: 0, last_page: 1 });
  const [viewingWarranty, setViewingWarranty] = useState(null);

  // Serial lookup
  const [serialInput, setSerialInput]     = useState('');
  const [serialResult, setSerialResult]   = useState(null);
  const [serialLoading, setSerialLoading] = useState(false);
  const [serialError, setSerialError]     = useState('');

  // Claim notes modal
  const [claimTarget, setClaimTarget]   = useState(null);  // { id, name }
  const [claimNotes, setClaimNotes]     = useState('');
  const [claimError, setClaimError]     = useState('');
  const [claiming, setClaiming]         = useState(false);

  const rowsPerPage = 15;
  const debounceRef = useRef(null);

  const fetchWarranties = useCallback(async (page = 1) => {
    try {
      setLoading(true);
      const params = { page, per_page: rowsPerPage };
      if (searchTerm)   params.search        = searchTerm;
      if (filterStatus) params.status_id     = filterStatus;
      if (expiringSoon) params.expiring_soon = 1;
      const res  = await warrantiesAPI.getAll(params);
      const list = Array.isArray(res?.data) ? res.data : [];
      setWarranties(list);
      const meta = res?.meta ?? res?.pagination ?? {};
      setPagination({ total: meta.total ?? list.length, last_page: meta.last_page ?? 1 });
      setError(null);
    } catch (err) {
      setError(err.message || 'Failed to load warranties.');
    } finally {
      setLoading(false);
    }
  }, [searchTerm, filterStatus, expiringSoon]);

  useEffect(() => {
    clearTimeout(debounceRef.current);
    debounceRef.current = setTimeout(() => { setCurrentPage(1); fetchWarranties(1); }, 350);
    return () => clearTimeout(debounceRef.current);
  }, [searchTerm, filterStatus, expiringSoon, fetchWarranties]);

  useEffect(() => { fetchWarranties(currentPage); }, [currentPage]); // eslint-disable-line react-hooks/exhaustive-deps

  const handleSerialLookup = async () => {
    const sn = serialInput.trim();
    if (!sn) return;
    setSerialLoading(true);
    setSerialResult(null);
    setSerialError('');
    try {
      const res = await warrantiesAPI.lookupBySerial(sn);
      setSerialResult(res);
    } catch (err) {
      setSerialError(err.message || 'No warranty record found for this serial number.');
    } finally {
      setSerialLoading(false);
    }
  };

  const openClaimModal = (warrantyId, productName, serialNumber) => {
    const serial = encodeURIComponent(serialNumber || '');
    window.location.href = `/admin/warranty-claims${serial ? `?serial=${serial}` : ''}`;
  };

  const submitClaim = async () => {
    if (!claimNotes.trim() || claimNotes.trim().length < 10) {
      setClaimError('Claim notes are required (minimum 10 characters).');
      return;
    }
    setClaiming(true);
    setClaimError('');
    try {
      await warrantiesAPI.markClaimed(claimTarget.id, { claim_notes: claimNotes.trim() });
      toast.success('Warranty marked as Claimed.');
      setClaimTarget(null);
      setViewingWarranty(null);
      setSerialResult(null);
      fetchWarranties(currentPage);
    } catch (err) {
      setClaimError(err.message || 'Failed to mark as claimed.');
    } finally {
      setClaiming(false);
    }
  };

  const handleExport = () => {
    const params = {};
    if (filterStatus) params.status_id = filterStatus;
    const path  = warrantiesAPI.exportCsv(params);
    const base  = getApiBaseUrl();
    const token = getToken();
    const url   = `${base}${path}`;
    const a = document.createElement('a');
    a.href = token ? `${url}${path.includes('?') ? '&' : '?'}token=${token}` : url;
    a.download = '';
    a.click();
  };

  const totalPages    = pagination.last_page || 1;
  const countByStatus = (sid) => warranties.filter((w) => w.status_id === sid).length;
  const activeCount   = countByStatus(33);
  const expiredCount  = countByStatus(34);
  const claimedCount  = countByStatus(35);

  // Resolve serial result fields — API returns { warranty: {...}, is_claimable, is_expired, is_claimed }
  const sr       = serialResult?.warranty ?? null;
  const srClaimable = serialResult?.is_claimable ?? false;

  return (
    <AdminLayout searchPlaceholder="Search warranties..." onSearch={() => {}}>
     <div className="ak-page">
      <header className="ak-header">
       <div className="ak-header-row">
        <div className="war-page-header-left">
          <p className="ak-eyebrow">After-sales · Warranties</p>
          <h1 className="ak-title">Warranty Records</h1>
          <p className="ak-sub">View and manage product warranties created from issuances.</p>
        </div>
        <div className="ak-header-aside">
        <button type="button" className="ak-btn ak-btn--ghost war-btn-create" onClick={handleExport}>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14" style={{ marginRight: 6 }}>
            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
            <polyline points="7 10 12 15 17 10" /><line x1="12" y1="15" x2="12" y2="3" />
          </svg>
          Export CSV
        </button>
        </div>
       </div>
      </header>

      {/* Stats row */}
      <div className="war-stats-row">
        <div className="war-stat-card">
          <div className="war-stat-icon war-stat-icon-green">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#22c55e" strokeWidth="2">
              <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
              <path d="M9 12l2 2 4-4"/>
            </svg>
          </div>
          <div className="war-stat-info">
            <span className="war-stat-label">Active (page)</span>
            <span className="war-stat-number">{activeCount}</span>
          </div>
        </div>
        <div className="war-stat-card">
          <div className="war-stat-icon war-stat-icon-red">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#ef4444" strokeWidth="2">
              <circle cx="12" cy="12" r="10"/>
              <line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>
            </svg>
          </div>
          <div className="war-stat-info">
            <span className="war-stat-label">Expired (page)</span>
            <span className="war-stat-number">{expiredCount}</span>
          </div>
        </div>
        <div className="war-stat-card">
          <div className="war-stat-icon war-stat-icon-blue">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" strokeWidth="2">
              <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
              <polyline points="14 2 14 8 20 8"/>
            </svg>
          </div>
          <div className="war-stat-info">
            <span className="war-stat-label">Claimed (page)</span>
            <span className="war-stat-number">{claimedCount}</span>
          </div>
        </div>
        <div className="war-stat-card">
          <div className="war-stat-icon war-stat-icon-blue">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" strokeWidth="2">
              <rect x="2" y="3" width="20" height="18" rx="2"/>
              <line x1="8" y1="9" x2="16" y2="9"/><line x1="8" y1="13" x2="16" y2="13"/>
              <line x1="8" y1="17" x2="12" y2="17"/>
            </svg>
          </div>
          <div className="war-stat-info">
            <span className="war-stat-label">Total</span>
            <span className="war-stat-number">{pagination.total}</span>
          </div>
        </div>
      </div>

      {/* Serial lookup */}
      <div className="war-filters-card">
        <div className="war-filters-header">
          <h3>Serial Number Lookup</h3>
          <p>Check warranty status by scanning or entering a serial number</p>
        </div>
        <div style={{ display: 'flex', gap: '10px', alignItems: 'flex-start' }}>
          <input
            type="text"
            placeholder="Scan or enter serial number..."
            value={serialInput}
            onChange={(e) => { setSerialInput(e.target.value); setSerialResult(null); setSerialError(''); }}
            onKeyDown={(e) => e.key === 'Enter' && handleSerialLookup()}
            style={{ flex: 1, padding: '8px 12px', border: '1px solid #d1d5db', borderRadius: 6, fontSize: 14 }}
          />
          <button
            type="button"
            className="war-btn-create"
            onClick={handleSerialLookup}
            disabled={serialLoading || !serialInput.trim()}
            style={{ whiteSpace: 'nowrap' }}
          >
            {serialLoading ? 'Checking...' : 'Check Warranty'}
          </button>
        </div>
        {serialError && (
          <div style={{ marginTop: 10, padding: '10px 14px', background: '#fef2f2', border: '1px solid #fca5a5', borderRadius: 6, color: '#b91c1c', fontSize: 13 }}>
            {serialError}
          </div>
        )}
        {sr && (
          <div style={{ marginTop: 10, padding: '14px', background: '#f0fdf4', border: '1px solid #86efac', borderRadius: 8 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', flexWrap: 'wrap', gap: 12 }}>
              <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
                <span style={{ fontWeight: 700, fontSize: 15 }}>{sr.product?.product_name ?? '—'}</span>
                <span style={{ fontFamily: 'monospace', fontSize: 12, color: '#6b7280' }}>SN: {sr.serial_number}</span>
                <span style={{ fontSize: 13 }}>Customer: {sr.customer?.customer_name ?? '—'}</span>
                <span style={{ fontSize: 13 }}>
                  Period: {sr.warranty_duration_label ?? `${sr.warranty_period_months} mo`}
                  {' '}&mdash;{' '}{fmtDate(sr.warranty_start_date)} to {fmtDate(sr.warranty_end_date)}
                </span>
              </div>
              <div style={{ display: 'flex', flexDirection: 'column', gap: 8, alignItems: 'flex-end' }}>
                <StatusBadge statusId={sr.status_id} />
                <DaysChip endDate={sr.warranty_end_date} statusId={sr.status_id} />
                {srClaimable && (
                  <button
                    type="button"
                    className="war-btn-assign"
                    onClick={() => openClaimModal(sr.warranty_id, sr.product?.product_name ?? sr.serial_number, sr.serial_number)}
                  >
                    Mark as Claimed
                  </button>
                )}
              </div>
            </div>
          </div>
        )}
      </div>

      {/* Filters */}
      <div className="war-filters-card">
        <div className="war-filters-row">
          <div className="war-filter-group">
            <label>Search</label>
            <div className="war-search-input">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" strokeWidth="2">
                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
              </svg>
              <input
                type="text"
                placeholder="Serial number, product, customer..."
                value={searchTerm}
                onChange={(e) => setSearchTerm(e.target.value)}
              />
            </div>
          </div>
          <div className="war-filter-group">
            <label>Status</label>
            <select
              value={filterStatus}
              onChange={(e) => { setFilterStatus(e.target.value); setExpiringSoon(false); }}
            >
              <option value="">All Status</option>
              <option value="33">Active</option>
              <option value="34">Expired</option>
              <option value="35">Claimed</option>
            </select>
          </div>
          <div className="war-filter-group">
            <label>&nbsp;</label>
            <label className="war-toggle-label">
              <input
                type="checkbox"
                checked={expiringSoon}
                onChange={(e) => { setExpiringSoon(e.target.checked); if (e.target.checked) setFilterStatus(''); }}
              />
              Expiring in 30 days
            </label>
          </div>
        </div>
      </div>

      {/* Table */}
      <div className="war-table-card">
        <div className="war-table-header">
          <h3>Warranty Records</h3>
          <span className="war-table-count">{pagination.total} records total</span>
        </div>
        {loading && <Loader />}
        {error   && <div className="error-message">Error: {error}</div>}
        <div className="war-table-wrapper">
          <table className="war-table">
            <thead>
              <tr>
                <th>SERIAL NUMBER</th>
                <th>PRODUCT</th>
                <th>CUSTOMER</th>
                <th>SALE DATE</th>
                <th>WARRANTY END</th>
                <th>PERIOD</th>
                <th>STATUS</th>
                <th>DAYS LEFT</th>
                <th>ACTIONS</th>
              </tr>
            </thead>
            <tbody>
              {warranties.length === 0 ? (
                <tr>
                  <td colSpan="9" style={{ textAlign: 'center', color: '#9ca3af', padding: '40px' }}>
                    {loading ? <Loader /> : 'No warranty records found.'}
                  </td>
                </tr>
              ) : (
                warranties.map((w) => (
                  <tr key={w.warranty_id}>
                    <td className="war-cell-serial">
                      <span style={{ fontFamily: 'monospace', fontSize: 12 }}>{w.serial_number}</span>
                    </td>
                    <td className="war-cell-item">{w.product?.product_name ?? '—'}</td>
                    <td>
                      <div className="war-cell-customer">
                        <span className="war-customer-name">{w.customer?.customer_name ?? '—'}</span>
                        {w.customer?.contact_number && (
                          <span className="war-customer-contact">{w.customer.contact_number}</span>
                        )}
                      </div>
                    </td>
                    <td>{fmtDate(w.sale_date)}</td>
                    <td>{fmtDate(w.warranty_end_date)}</td>
                    <td>{w.warranty_duration_label ?? `${w.warranty_period_months} mo`}</td>
                    <td><StatusBadge statusId={w.status_id} /></td>
                    <td><DaysChip endDate={w.warranty_end_date} statusId={w.status_id} /></td>
                    <td>
                      <div className="war-cell-actions">
                        <button className="war-btn-view" onClick={() => setViewingWarranty(w)}>View</button>
                        {w.status_id === 33 && (
                          <button
                            className="war-btn-assign"
                            onClick={() => openClaimModal(w.warranty_id, w.product?.product_name ?? w.serial_number, w.serial_number)}
                          >
                            Claim
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>

        {totalPages > 1 && (
          <div className="pagination" style={{ padding: '16px', display: 'flex', justifyContent: 'center', gap: '4px' }}>
            <button disabled={currentPage === 1} onClick={() => setCurrentPage(currentPage - 1)}>Previous</button>
            {Array.from({ length: Math.min(totalPages, 10) }, (_, i) => i + 1).map((page) => (
              <button key={page} className={currentPage === page ? 'active' : ''} onClick={() => setCurrentPage(page)}>
                {page}
              </button>
            ))}
            {totalPages > 10 && <span style={{ padding: '4px 8px', color: '#9ca3af' }}>...</span>}
            <button disabled={currentPage === totalPages} onClick={() => setCurrentPage(currentPage + 1)}>Next</button>
          </div>
        )}
      </div>

      {/* View modal */}
      {viewingWarranty && (
        <Modal isOpen title="Warranty Details" onClose={() => setViewingWarranty(null)}>
          <div className="war-view-details">
            <div className="form-row">
              <div className="form-group">
                <label>Warranty ID</label>
                <p>#{viewingWarranty.warranty_id}</p>
              </div>
              <div className="form-group">
                <label>Status</label>
                <p><StatusBadge statusId={viewingWarranty.status_id} /></p>
              </div>
            </div>
            <div className="form-group">
              <label>Serial Number</label>
              <p style={{ fontFamily: 'monospace' }}>{viewingWarranty.serial_number}</p>
            </div>
            <div className="form-group">
              <label>Product</label>
              <p>{viewingWarranty.product?.product_name ?? '—'}</p>
            </div>
            <div className="form-row">
              <div className="form-group">
                <label>Customer</label>
                <p>{viewingWarranty.customer?.customer_name ?? '—'}</p>
              </div>
              <div className="form-group">
                <label>Contact</label>
                <p>{viewingWarranty.customer?.contact_number ?? '—'}</p>
              </div>
            </div>
            <div className="form-row">
              <div className="form-group">
                <label>Sale Date</label>
                <p>{fmtDate(viewingWarranty.sale_date)}</p>
              </div>
              <div className="form-group">
                <label>Period</label>
                <p>{viewingWarranty.warranty_duration_label ?? `${viewingWarranty.warranty_period_months} months`}</p>
              </div>
            </div>
            <div className="form-row">
              <div className="form-group">
                <label>Warranty Start</label>
                <p>{fmtDate(viewingWarranty.warranty_start_date)}</p>
              </div>
              <div className="form-group">
                <label>Warranty End</label>
                <p>{fmtDate(viewingWarranty.warranty_end_date)}</p>
              </div>
            </div>
            <div className="form-group">
              <label>Days Remaining</label>
              <p><DaysChip endDate={viewingWarranty.warranty_end_date} statusId={viewingWarranty.status_id} /></p>
            </div>
            {viewingWarranty.claim_date && (
              <div className="form-row">
                <div className="form-group">
                  <label>Claim Date</label>
                  <p>{fmtDate(viewingWarranty.claim_date)}</p>
                </div>
                <div className="form-group">
                  <label>Claimed By</label>
                  <p>{viewingWarranty.claimed_by_name ?? '—'}</p>
                </div>
              </div>
            )}
            {viewingWarranty.claim_notes && (
              <div className="form-group">
                <label>Claim Notes</label>
                <p>{viewingWarranty.claim_notes}</p>
              </div>
            )}
            {viewingWarranty.notes && (
              <div className="form-group">
                <label>Notes</label>
                <p>{viewingWarranty.notes}</p>
              </div>
            )}
            <div className="modal-footer">
              <button type="button" className="btn-cancel" onClick={() => setViewingWarranty(null)}>Close</button>
              {viewingWarranty.status_id === 33 && (
                <button
                  type="button"
                  className="btn-save"
                  onClick={() => openClaimModal(viewingWarranty.warranty_id, viewingWarranty.product?.product_name ?? viewingWarranty.serial_number, viewingWarranty.serial_number)}
                >
                  Mark as Claimed
                </button>
              )}
            </div>
          </div>
        </Modal>
      )}

      {/* Claim notes modal */}
      {claimTarget && (
        <Modal isOpen title="Mark Warranty as Claimed" onClose={() => setClaimTarget(null)}>
          <div style={{ padding: '4px 0' }}>
            <p style={{ marginBottom: 12, color: '#374151', fontSize: 14 }}>
              Record claim details for: <strong>{claimTarget.name}</strong>
            </p>
            <div style={{ marginBottom: 16 }}>
              <label style={{ display: 'block', marginBottom: 6, fontSize: 13, fontWeight: 600, color: '#374151' }}>
                Claim Notes *
              </label>
              <textarea
                value={claimNotes}
                onChange={(e) => { setClaimNotes(e.target.value); setClaimError(''); }}
                placeholder="Describe the claim reason, defect, or service performed (minimum 10 characters)..."
                rows={4}
                style={{ width: '100%', padding: '8px 12px', border: '1px solid #d1d5db', borderRadius: 6, fontSize: 13, resize: 'vertical', boxSizing: 'border-box' }}
              />
              {claimError && (
                <p style={{ marginTop: 4, color: '#dc2626', fontSize: 12 }}>{claimError}</p>
              )}
            </div>
            <div className="modal-footer">
              <button type="button" className="btn-cancel" onClick={() => setClaimTarget(null)}>Cancel</button>
              <button type="button" className="btn-save" onClick={submitClaim} disabled={claiming}>
                {claiming ? 'Processing...' : 'Confirm Claim'}
              </button>
            </div>
          </div>
        </Modal>
      )}
     </div>
    </AdminLayout>
  );
};

export default WarrantyManagement;
