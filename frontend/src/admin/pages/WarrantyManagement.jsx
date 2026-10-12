import React, { useState, useEffect, useCallback, useRef } from 'react';
import AdminLayout from '../components/AdminLayout';
import Loader from '../components/Loader';
import Modal from '../components/Modal';
import { warrantiesAPI, getApiBaseUrl, getToken } from '../services/api';
import { useAuth } from '../context/AuthContext';
import '../styles/airking_ds.css';
import '../styles/warranty_management.css';

const ACTIVE = 33;
const EXPIRED = 34;
const CLAIMED = 35;

const fmtDate = (d) => {
  if (!d) return '—';
  return new Date(d).toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' });
};

const daysRemaining = (endDate) => {
  if (!endDate) return null;
  return Math.ceil((new Date(endDate) - new Date()) / 86400000);
};

/**
 * The status the API reports is already DERIVED from the warranty's end date
 * (WarrantyRecord::effective_status_id), so this renders what it is handed and
 * never second-guesses it. A page that recalculated expiry itself would
 * disagree with the filters, which are evaluated in SQL.
 */
const StatusBadge = ({ statusId }) => {
  if (statusId === ACTIVE)  return <span className="ak-badge ak-badge--green">Active</span>;
  if (statusId === EXPIRED) return <span className="ak-badge ak-badge--red">Expired</span>;
  if (statusId === CLAIMED) return <span className="ak-badge ak-badge--blue">Claimed</span>;
  return <span className="ak-badge ak-badge--gray">{statusId}</span>;
};

const DaysChip = ({ endDate, statusId }) => {
  if (statusId === CLAIMED) return <span className="ak-badge ak-badge--gray">Claimed</span>;
  if (statusId === EXPIRED) return <span className="ak-badge ak-badge--red">Expired</span>;
  const days = daysRemaining(endDate);
  if (days === null) return null;
  if (days < 0)  return <span className="ak-badge ak-badge--red">{Math.abs(days)}d ago</span>;
  if (days <= 30) return <span className="ak-badge ak-badge--red">{days}d left</span>;
  if (days <= 90) return <span className="ak-badge ak-badge--amber">{days}d left</span>;
  return <span className="ak-badge ak-badge--green">{days}d left</span>;
};

/**
 * Where the unit is, in one badge.
 *
 * The lookup answers about the UNIT now, not only about a warranty record, so
 * "no warranty" is no longer the same statement as "we have never seen this".
 */
const UNIT_STATES = {
  in_stock:         { label: 'In inventory', badge: 'ak-badge--green' },
  returned:         { label: 'Returned by customer', badge: 'ak-badge--amber' },
  sold:             { label: 'Sold', badge: 'ak-badge--blue' },
  sold_no_warranty: { label: 'Sold — no warranty on record', badge: 'ak-badge--amber' },
  unknown:          { label: 'Not recognised', badge: 'ak-badge--gray' },
};

const WARRANTY_STATES = {
  in_warranty: { label: 'Active', badge: 'ak-badge--green' },
  expired:     { label: 'Expired', badge: 'ak-badge--red' },
  claimed:     { label: 'Claimed', badge: 'ak-badge--blue' },
  none:        { label: 'None', badge: 'ak-badge--gray' },
};

const WarrantyLine = ({ title, cover, emptyNote }) => {
  const meta = WARRANTY_STATES[cover?.state] ?? WARRANTY_STATES.none;
  const covered = cover?.state === 'in_warranty' || cover?.state === 'expired';

  return (
    <div className="war-cover-line">
      <span className="war-cover-title">{title}</span>
      <span className={`ak-badge ${meta.badge}`}>{meta.label}</span>
      {covered && cover?.end_date && (
        <span className="war-result-line">
          until {fmtDate(cover.end_date)}
          {cover.supplier_name ? ` · ${cover.supplier_name}` : ''}
        </span>
      )}
      {!covered && emptyNote && <span className="war-result-line">{emptyNote}</span>}
    </div>
  );
};

const StatCard = ({ tone, label, value, children }) => (
  <div className={`ak-stat war-stat--${tone}`}>
    <div className="ak-stat-icon">{children}</div>
    <div>
      <div className="ak-stat-label">{label}</div>
      <div className="ak-stat-value">{value}</div>
    </div>
  </div>
);

const WarrantyManagement = () => {
  const { can } = useAuth();
  const canClaim = can('warranty_claims', 'create');
  const [warranties, setWarranties]     = useState([]);
  const [loading, setLoading]           = useState(true);
  const [error, setError]               = useState(null);
  const [searchTerm, setSearchTerm]     = useState('');
  const [filterStatus, setFilterStatus] = useState('');
  const [expiringSoon, setExpiringSoon] = useState(false);
  const [currentPage, setCurrentPage]   = useState(1);
  const [pagination, setPagination]     = useState({ total: 0, last_page: 1 });
  // Counted by the API across every matching record. The page used to tally the
  // rows it had been handed and label them "Active (page)", so the headline
  // numbers changed as you paged and described nothing.
  const [summary, setSummary] = useState({ active: 0, expired: 0, claimed: 0, total: 0 });
  const [viewingWarranty, setViewingWarranty] = useState(null);

  // Serial lookup
  const [serialInput, setSerialInput]     = useState('');
  const [serialResult, setSerialResult]   = useState(null);
  const [serialLoading, setSerialLoading] = useState(false);
  const [serialError, setSerialError]     = useState('');

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
      if (res?.summary) setSummary(res.summary);
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

  const goToClaim = (serialNumber) => {
    const serial = encodeURIComponent(serialNumber || '');
    window.location.href = `/warranty-claims${serial ? `?serial=${serial}` : ''}`;
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

  const totalPages = pagination.last_page || 1;

  // The lookup answers about the unit: { state, unit, sale, incident,
  // customer_warranty, supplier_warranty }. An unknown serial comes back 200
  // with state 'unknown' rather than a 404, so it renders as an answer.
  const sr          = serialResult ?? null;
  const srState     = UNIT_STATES[sr?.state] ?? null;
  const srClaimable = sr?.is_claimable ?? false;

  return (
    <AdminLayout searchPlaceholder="Search warranties..." onSearch={() => {}}>
      <div className="ak-page">
        <header className="ak-header">
          <div className="ak-header-row">
            <div>
              <p className="ak-eyebrow">After-sales · Warranties</p>
              <h1 className="ak-title">Warranty Records</h1>
              <p className="ak-sub">Product warranties created from customer sales.</p>
            </div>
            <div className="ak-header-aside">
              <button type="button" className="ak-btn ak-btn--ghost" onClick={handleExport}>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                  <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                  <polyline points="7 10 12 15 17 10" /><line x1="12" y1="15" x2="12" y2="3" />
                </svg>
                Export CSV
              </button>
            </div>
          </div>
        </header>

        <div className="ak-stat-grid war-stats">
          <StatCard tone="green" label="Active" value={summary.active}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" /><path d="M9 12l2 2 4-4" />
            </svg>
          </StatCard>
          <StatCard tone="red" label="Expired" value={summary.expired}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <circle cx="12" cy="12" r="10" />
              <line x1="15" y1="9" x2="9" y2="15" /><line x1="9" y1="9" x2="15" y2="15" />
            </svg>
          </StatCard>
          <StatCard tone="blue" label="Claimed" value={summary.claimed}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
              <polyline points="14 2 14 8 20 8" />
            </svg>
          </StatCard>
          <StatCard tone="blue" label="Total" value={summary.total}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <rect x="2" y="3" width="20" height="18" rx="2" />
              <line x1="8" y1="9" x2="16" y2="9" /><line x1="8" y1="13" x2="16" y2="13" />
            </svg>
          </StatCard>
        </div>

        {/* Serial lookup — kept as its own panel because this is the scanner
            workflow, and a scanner needs one target that is always in the same
            place rather than a search box that also does other things. */}
        <section className="ak-card">
          <div className="ak-card-head">
            <div>
              <h3 className="ak-card-title">Serial Number Lookup</h3>
              <p className="ak-card-meta">Scan or type a serial number to check its warranty.</p>
            </div>
          </div>

          <div className="war-lookup-row">
            <input
              type="text"
              className="ak-input"
              placeholder="Scan or enter serial number…"
              value={serialInput}
              onChange={(e) => { setSerialInput(e.target.value); setSerialResult(null); setSerialError(''); }}
              onKeyDown={(e) => e.key === 'Enter' && handleSerialLookup()}
            />
            <button
              type="button"
              className="ak-btn ak-btn--primary"
              onClick={handleSerialLookup}
              disabled={serialLoading || !serialInput.trim()}
            >
              {serialLoading ? 'Checking…' : 'Check Warranty'}
            </button>
          </div>

          {serialError && <div className="ak-state ak-state--error war-result">{serialError}</div>}

          {sr && srState && (
            <div className={`war-result war-result--${sr.state}`}>
              <div className="war-result-main">
                <span className="war-result-title">{sr.unit?.product_name ?? sr.sale?.product_name ?? 'Unit'}</span>
                <span className="war-result-line ak-mono">SN: {sr.serial_number}</span>

                {/* Where it is — the question the old panel could not answer. */}
                {sr.state === 'in_stock' && (
                  <span className="war-result-line">
                    {sr.unit?.location_name ?? 'Location unknown'}
                    {sr.unit?.status_name ? ` · ${sr.unit.status_name}` : ''}
                  </span>
                )}
                {sr.sale && (
                  <span className="war-result-line">
                    {sr.sale.customer_name ?? 'Customer unknown'}
                    {sr.sale.sale_date ? ` · sold ${fmtDate(sr.sale.sale_date)}` : ''}
                    {sr.sale.dr_number ? ` · ${sr.sale.dr_number}` : ''}
                  </span>
                )}
                {sr.incident && (
                  <span className="war-result-line">
                    Held by {sr.incident.incident_number} ({sr.incident.incident_status})
                  </span>
                )}
                {sr.state === 'unknown' && (
                  <span className="war-result-line">
                    This serial is not in inventory, not on a sale, and has no warranty record.
                  </span>
                )}

                {sr.state !== 'unknown' && (
                  <div className="war-covers">
                    <WarrantyLine
                      title="Customer warranty"
                      cover={sr.customer_warranty}
                      emptyNote={sr.customer_warranty?.reason === 'not_sold'
                        ? 'not sold yet'
                        : 'no record for this sale'}
                    />
                    <WarrantyLine
                      title="Supplier warranty"
                      cover={sr.supplier_warranty}
                      emptyNote="no cover traced to a receiving"
                    />
                  </div>
                )}
              </div>

              <div className="war-result-side">
                <span className={`ak-badge ${srState.badge}`}>{srState.label}</span>
                {srClaimable && canClaim && (
                  <button
                    type="button"
                    className="ak-btn ak-btn--primary ak-btn--sm"
                    onClick={() => goToClaim(sr.serial_number)}
                  >
                    Claim Warranty
                  </button>
                )}
              </div>
            </div>
          )}
        </section>

        <section className="ak-card">
          <div className="ak-toolbar">
            <div className="ak-search">
              <input
                type="text"
                className="ak-input"
                placeholder="Serial number, product, customer…"
                value={searchTerm}
                onChange={(e) => setSearchTerm(e.target.value)}
              />
            </div>
            <select
              className="ak-select"
              value={filterStatus}
              onChange={(e) => { setFilterStatus(e.target.value); setExpiringSoon(false); }}
            >
              <option value="">All statuses</option>
              <option value={ACTIVE}>Active</option>
              <option value={EXPIRED}>Expired</option>
              <option value={CLAIMED}>Claimed</option>
            </select>
            <label className="ak-label war-toggle">
              <input
                type="checkbox"
                checked={expiringSoon}
                onChange={(e) => { setExpiringSoon(e.target.checked); if (e.target.checked) setFilterStatus(''); }}
              />
              {' '}Expiring in 30 days
            </label>
          </div>
        </section>

        <section className="ak-card ak-card--flush">
          <div className="ak-card-head">
            <div>
              <h3 className="ak-card-title">Warranty Records</h3>
              <p className="ak-card-meta">{pagination.total} record{pagination.total === 1 ? '' : 's'} matching</p>
            </div>
          </div>

          {error && <div className="ak-state ak-state--error">{error}</div>}

          {loading ? (
            <div className="ak-state"><Loader /></div>
          ) : warranties.length === 0 ? (
            <div className="ak-state">No warranty records found.</div>
          ) : (
            <div className="ak-table-wrap">
              <table className="ak-table">
                <thead>
                  <tr>
                    <th>Serial number</th>
                    <th>Product</th>
                    <th>Customer</th>
                    <th>Sale date</th>
                    <th>Warranty end</th>
                    <th>Period</th>
                    <th>Status</th>
                    <th>Days left</th>
                    <th />
                  </tr>
                </thead>
                <tbody>
                  {warranties.map((w) => (
                    <tr key={w.warranty_id}>
                      <td className="ak-mono">{w.serial_number}</td>
                      <td>{w.product?.product_name ?? '—'}</td>
                      <td>
                        <div className="war-cell-stack">
                          <span>{w.customer?.customer_name ?? '—'}</span>
                          {w.customer?.contact_number && (
                            <span className="war-cell-sub">{w.customer.contact_number}</span>
                          )}
                        </div>
                      </td>
                      <td>{fmtDate(w.sale_date)}</td>
                      <td>{fmtDate(w.warranty_end_date)}</td>
                      <td>{w.warranty_duration_label ?? `${w.warranty_period_months} mo`}</td>
                      <td><StatusBadge statusId={w.status_id} /></td>
                      <td><DaysChip endDate={w.warranty_end_date} statusId={w.status_id} /></td>
                      <td>
                        <div className="war-row-actions">
                          <button
                            type="button"
                            className="ak-btn ak-btn--ghost ak-btn--sm"
                            onClick={() => setViewingWarranty(w)}
                          >
                            View
                          </button>
                          {w.status_id === ACTIVE && canClaim && (
                            <button
                              type="button"
                              className="ak-btn ak-btn--primary ak-btn--sm"
                              onClick={() => goToClaim(w.serial_number)}
                            >
                              Claim
                            </button>
                          )}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {totalPages > 1 && (
            <div className="war-pagination">
              <button
                type="button"
                className="ak-btn ak-btn--ghost ak-btn--sm"
                disabled={currentPage === 1}
                onClick={() => setCurrentPage(currentPage - 1)}
              >
                Previous
              </button>
              {Array.from({ length: Math.min(totalPages, 10) }, (_, i) => i + 1).map((page) => (
                <button
                  type="button"
                  key={page}
                  className={`ak-btn ak-btn--ghost ak-btn--sm ${currentPage === page ? 'is-active' : ''}`}
                  onClick={() => setCurrentPage(page)}
                >
                  {page}
                </button>
              ))}
              {totalPages > 10 && <span className="war-pagination-gap">…</span>}
              <button
                type="button"
                className="ak-btn ak-btn--ghost ak-btn--sm"
                disabled={currentPage === totalPages}
                onClick={() => setCurrentPage(currentPage + 1)}
              >
                Next
              </button>
            </div>
          )}
        </section>

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
                <p className="ak-mono">{viewingWarranty.serial_number}</p>
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
                {viewingWarranty.status_id === ACTIVE && canClaim && (
                  <button
                    type="button"
                    className="btn-save"
                    onClick={() => goToClaim(viewingWarranty.serial_number)}
                  >
                    Claim Warranty
                  </button>
                )}
              </div>
            </div>
          </Modal>
        )}
      </div>
    </AdminLayout>
  );
};

export default WarrantyManagement;
