import React, { useState, useEffect, useCallback } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import Loader from '../components/Loader';
import { istAPI } from '../services/api';
import { useAuth, useUserLocation } from '../context/AuthContext';
import { getRoleKey, ROLES } from '../utils/roles';
import '../styles/dashboard_air.css';
import '../styles/po_recommendation.css';

const fmtDate = (v) => {
  if (!v) return '—';
  const d = new Date(v);
  if (Number.isNaN(d.getTime())) return String(v).slice(0, 10);
  return d.toLocaleDateString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric' });
};
const fmtDateTime = (v) => {
  if (!v) return '—';
  try { return new Date(v).toLocaleString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }); }
  catch { return String(v).slice(0, 16).replace('T', ' '); }
};
const userName = (u) => u ? ((`${u.first_name || ''} ${u.last_name || ''}`).trim() || u.username || '—') : '—';

const STATUS_CFG = {
  'Approved':           { label: 'APPROVED',           color: 'approved' },
  'In Transit':         { label: 'IN TRANSIT',         color: 'intransit' },
  'Partially Received': { label: 'PARTIALLY RECEIVED', color: 'pending'  },
  'Received':           { label: 'RECEIVED',           color: 'received' },
  'Rejected':           { label: 'REJECTED',           color: 'rejected' },
};

const statusBg  = { approved: '#dcfce7', intransit: '#dbeafe', pending: '#fef3c7', received: '#d1fae5', rejected: '#fee2e2', draft: '#f1f5f9' };
const statusClr = { approved: '#16a34a', intransit: '#2563eb', pending: '#d97706', received: '#059669', rejected: '#dc2626', draft: '#64748b' };

const StatusBadge = ({ name }) => {
  const cfg = STATUS_CFG[name] || { label: name || 'UNKNOWN', color: 'draft' };
  return (
    <span style={{
      display: 'inline-block', padding: '3px 10px', borderRadius: 20, fontSize: 11,
      fontWeight: 700, letterSpacing: 0.4,
      color: statusClr[cfg.color] || '#64748b',
      background: statusBg[cfg.color] || '#f1f5f9',
    }}>
      {cfg.label}
    </span>
  );
};

const STATUS_TABS = [
  { key: 'all',        label: 'All' },
  { key: 'Approved',   label: 'Approved' },
  { key: 'In Transit', label: 'In Transit' },
  { key: 'Received',   label: 'Received' },
  { key: 'Rejected',   label: 'Rejected' },
];

const VIEW_TABS = ['outgoing', 'incoming'];

const getDispatch = (ist) => {
  const entries = ist?.transfer_tracking || [];
  return entries
    .filter((t) => (t.status_note || '').toLowerCase().includes('dispatched'))
    .sort((a, b) => new Date(b.recorded_at) - new Date(a.recorded_at))[0] || null;
};

const ISTManagementPage = () => {
  const navigate = useNavigate();
  const loc      = useLocation();
  const { user } = useAuth();
  const { locationId } = useUserLocation();

  const roleKey    = getRoleKey(user);
  const isAdmin    = roleKey === ROLES.ADMIN || roleKey === ROLES.INVENTORY_ANALYST;

  const [ists,       setIsts]       = useState([]);
  const [loading,    setLoading]    = useState(true);
  const [error,      setError]      = useState('');
  const [success,    setSuccess]    = useState(loc.state?.success || '');
  const [statusTab,  setStatusTab]  = useState('all');
  const [viewTab,    setViewTab]    = useState('outgoing');

  const fetchIsts = useCallback(() => {
    setLoading(true);
    setError('');
    istAPI.getAll({ per_page: 100 })
      .then((r) => setIsts(Array.isArray(r?.data) ? r.data : []))
      .catch((e) => setError(e?.message || 'Failed to load ISTs.'))
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => { fetchIsts(); }, [fetchIsts]);

  // Incoming = ISTs where current user's location is the destination and status is In Transit
  const myLocId = String(locationId || '');
  const incomingIsts = ists.filter((t) =>
    t.status?.status_name === 'In Transit' &&
    (isAdmin || String(t.to_location_id) === myLocId)
  );
  const hasIncoming = incomingIsts.length > 0;

  // Filter by view tab first, then by status tab
  const baseList = viewTab === 'incoming'
    ? incomingIsts
    : ists.filter((t) =>
        isAdmin ||
        !myLocId ||
        String(t.from_location_id) === myLocId
      );

  const filtered = statusTab === 'all'
    ? baseList
    : baseList.filter((t) => t.status?.status_name === statusTab);

  // Count per status tab
  const countFor = (key) => key === 'all'
    ? baseList.length
    : baseList.filter((t) => t.status?.status_name === key).length;

  // Preset counts (always from full list, regardless of current viewTab)
  const toFulfillCount = ists.filter((t) =>
    t.status?.status_name === 'Approved' &&
    (isAdmin || !myLocId || String(t.from_location_id) === myLocId)
  ).length;
  const toReceiveCount = ists.filter((t) =>
    t.status?.status_name === 'In Transit' &&
    (isAdmin || String(t.to_location_id) === myLocId)
  ).length;

  const applyPreset = (preset) => {
    if (preset === 'fulfill') {
      setViewTab('outgoing');
      setStatusTab('Approved');
    } else if (preset === 'receive') {
      setViewTab('incoming');
      setStatusTab('In Transit');
    }
  };

  const activePreset =
    viewTab === 'outgoing' && statusTab === 'Approved' ? 'fulfill' :
    viewTab === 'incoming' && statusTab === 'In Transit' ? 'receive' :
    null;

  return (
    <AdminLayout>
      <div className="ak-page po-recommendation-page">

        {/* Header */}
        <div className="po-creator-header-card">
          <div className="po-creator-header-grid">
            <div className="po-creator-header-main">
              <div className="po-creator-cart-wrap po-creator-cart-wrap--doc" aria-hidden>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"
                  strokeLinecap="round" strokeLinejoin="round" width="28" height="28">
                  <path d="M17 3l4 4-4 4"/><path d="M3 7h18"/>
                  <path d="M7 21l-4-4 4-4"/><path d="M21 17H3"/>
                </svg>
              </div>
              <div className="po-creator-header-copy">
                <h1 className="po-page-title">INTERBRANCH STOCK TRANSFERS</h1>
                <p className="po-page-subtitle">IST documents created from approved transfer requests</p>
              </div>
            </div>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
              <button
                type="button"
                className="po-btn"
                style={{ fontSize: 12, padding: '8px 14px' }}
                onClick={fetchIsts}
              >
                Refresh
              </button>
              <button
                type="button"
                className="po-btn"
                style={{ fontSize: 12, padding: '8px 14px' }}
                onClick={() => navigate('/admin/transfer-requests')}
              >
                Transfer Requests
              </button>
            </div>
          </div>
        </div>

        {/* Success banner */}
        {success && (
          <div className="po-success-banner" style={{ marginBottom: 12 }}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="18" height="18">
              <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
            </svg>
            {success}
            <button type="button" className="po-success-close" onClick={() => setSuccess('')} aria-label="Close">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" width="14" height="14">
                <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
              </svg>
            </button>
          </div>
        )}

        {/* Quick-filter presets */}
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 16 }}>
          <span style={{ fontSize: 11, fontWeight: 600, color: '#94a3b8', alignSelf: 'center', textTransform: 'uppercase', letterSpacing: 0.5 }}>
            Worklist:
          </span>
          <button
            type="button"
            onClick={() => applyPreset('fulfill')}
            style={{
              padding: '6px 16px', borderRadius: 20, cursor: 'pointer', fontSize: 12, fontWeight: 700,
              border: activePreset === 'fulfill' ? '2px solid #16a34a' : '1.5px solid #bbf7d0',
              background: activePreset === 'fulfill' ? '#16a34a' : '#f0fdf4',
              color: activePreset === 'fulfill' ? '#fff' : '#16a34a',
              display: 'flex', alignItems: 'center', gap: 6,
            }}
          >
            To Fulfill
            {toFulfillCount > 0 && (
              <span style={{
                fontSize: 10, fontWeight: 700, padding: '1px 5px', borderRadius: 10,
                background: activePreset === 'fulfill' ? 'rgba(255,255,255,0.25)' : '#dcfce7',
                color: activePreset === 'fulfill' ? '#fff' : '#16a34a',
              }}>
                {toFulfillCount}
              </span>
            )}
          </button>
          <button
            type="button"
            onClick={() => applyPreset('receive')}
            style={{
              padding: '6px 16px', borderRadius: 20, cursor: 'pointer', fontSize: 12, fontWeight: 700,
              border: activePreset === 'receive' ? '2px solid #2563eb' : '1.5px solid #bfdbfe',
              background: activePreset === 'receive' ? '#2563eb' : '#eff6ff',
              color: activePreset === 'receive' ? '#fff' : '#2563eb',
              display: 'flex', alignItems: 'center', gap: 6,
            }}
          >
            To Receive
            {toReceiveCount > 0 && (
              <span style={{
                fontSize: 10, fontWeight: 700, padding: '1px 5px', borderRadius: 10,
                background: activePreset === 'receive' ? 'rgba(255,255,255,0.25)' : '#dbeafe',
                color: activePreset === 'receive' ? '#fff' : '#2563eb',
              }}>
                {toReceiveCount}
              </span>
            )}
          </button>
        </div>

        {/* View tabs: Outgoing / Incoming */}
        <div style={{ display: 'flex', gap: 2, marginBottom: 12 }}>
          <button
            type="button"
            onClick={() => setViewTab('outgoing')}
            style={{
              padding: '8px 20px', borderRadius: '8px 8px 0 0', border: 'none', cursor: 'pointer', fontSize: 13, fontWeight: 600,
              background: viewTab === 'outgoing' ? '#1e293b' : '#f1f5f9',
              color:      viewTab === 'outgoing' ? '#fff'    : '#64748b',
            }}
          >
            Outgoing
          </button>
          <button
            type="button"
            onClick={() => setViewTab('incoming')}
            style={{
              padding: '8px 20px', borderRadius: '8px 8px 0 0', border: 'none', cursor: 'pointer', fontSize: 13, fontWeight: 600,
              background: viewTab === 'incoming' ? '#1e293b' : '#f1f5f9',
              color:      viewTab === 'incoming' ? '#fff'    : '#64748b',
              display:    'flex', alignItems: 'center', gap: 6,
            }}
          >
            Incoming
            {hasIncoming && (
              <span style={{
                display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
                minWidth: 18, height: 18, borderRadius: 9, fontSize: 10, fontWeight: 700,
                background: '#2563eb', color: '#fff', padding: '0 4px',
              }}>
                {incomingIsts.length}
              </span>
            )}
          </button>
        </div>

        {/* Status filter tabs */}
        <div style={{ display: 'flex', gap: 4, flexWrap: 'wrap', marginBottom: 12 }}>
          {STATUS_TABS.map((tab) => {
            const cnt = countFor(tab.key);
            const active = statusTab === tab.key;
            return (
              <button
                key={tab.key}
                type="button"
                onClick={() => setStatusTab(tab.key)}
                style={{
                  padding: '5px 14px', border: active ? '2px solid #1e293b' : '1px solid #e2e8f0',
                  borderRadius: 20, cursor: 'pointer', fontSize: 12, fontWeight: active ? 700 : 500,
                  background: active ? '#1e293b' : '#fff', color: active ? '#fff' : '#64748b',
                  display: 'flex', alignItems: 'center', gap: 5,
                }}
              >
                {tab.label}
                {cnt > 0 && (
                  <span style={{
                    fontSize: 10, fontWeight: 700, padding: '1px 5px', borderRadius: 10,
                    background: active ? 'rgba(255,255,255,0.2)' : '#f1f5f9', color: active ? '#fff' : '#64748b',
                  }}>
                    {cnt}
                  </span>
                )}
              </button>
            );
          })}
        </div>

        {loading && <Loader label="Loading ISTs" />}
        {error   && <div className="po-submit-error">{error}</div>}

        {!loading && filtered.length === 0 && (
          <div className="po-status-empty">
            <svg viewBox="0 0 24 24" fill="none" stroke="#cbd5e1" strokeWidth="1.5" width="40" height="40">
              <path d="M17 3l4 4-4 4"/><path d="M3 7h18"/>
              <path d="M7 21l-4-4 4-4"/><path d="M21 17H3"/>
            </svg>
            <p>
              {viewTab === 'incoming'
                ? 'No incoming ISTs for your location.'
                : 'No ISTs match the selected filter.'}
            </p>
            <span>ISTs are created from approved transfer requests.</span>
          </div>
        )}

        {!loading && filtered.length > 0 && (
          <div className="po-line-items-card">
            <div className="po-table-wrapper">
              <table className="po-table">
                <thead>
                  <tr>
                    <th>IST NUMBER</th>
                    <th>FROM</th>
                    <th>TO</th>
                    <th>DATE</th>
                    <th>PREPARED BY</th>
                    <th>DISPATCHED DATE</th>
                    <th>DISPATCHED BY</th>
                    <th style={{ textAlign: 'center' }}>ITEMS</th>
                    <th style={{ textAlign: 'center' }}>DISPATCHED</th>
                    <th>STATUS</th>
                    <th>ACTIONS</th>
                  </tr>
                </thead>
                <tbody>
                  {filtered.map((ist) => {
                    const from = ist.fromLocation?.location_name || ist.from_location?.location_name || '—';
                    const to   = ist.toLocation?.location_name   || ist.to_location?.location_name   || '—';
                    const dispatch = getDispatch(ist);
                    const totalReq = (ist.details || []).reduce((s, d) => s + (parseInt(d.quantity_requested) || 0), 0);
                    return (
                      <tr
                        key={ist.transfer_id}
                        style={{ cursor: 'pointer' }}
                        onClick={() => navigate(`/admin/ist/${ist.transfer_id}`)}
                      >
                        <td>
                          <span style={{ fontWeight: 600, color: '#1e293b' }}>{ist.transfer_number}</span>
                          {(ist.transfer_request?.transfer_number || ist.transferRequest?.transfer_number) && (
                            <div style={{ fontSize: 11, color: '#94a3b8', marginTop: 2 }}>
                              from {ist.transfer_request?.transfer_number || ist.transferRequest?.transfer_number}
                            </div>
                          )}
                        </td>
                        <td>{from}</td>
                        <td>{to}</td>
                        <td>{fmtDate(ist.transfer_date)}</td>
                        <td>{userName(ist.prepared_by || ist.preparedBy)}</td>
                        <td style={{ fontSize: 12 }}>{dispatch ? fmtDateTime(dispatch.recorded_at) : '—'}</td>
                        <td>{dispatch ? userName(dispatch.recorded_by || dispatch.recordedBy) : '—'}</td>
                        <td style={{ textAlign: 'center' }}>{(ist.details || []).length} line(s)</td>
                        <td style={{ textAlign: 'center' }}>
                          {(ist.total_quantity_transferred || 0)} / {totalReq}
                        </td>
                        <td>
                          <StatusBadge name={ist.status?.status_name} />
                        </td>
                        <td style={{ whiteSpace: 'nowrap' }}>
                          {ist.status?.status_name === 'Received' ? (
                            <div style={{ display: 'flex', gap: 4 }}>
                              <button
                                type="button"
                                className="po-btn"
                                style={{ fontSize: 11, padding: '4px 10px', background: '#1e293b', color: '#fff' }}
                                onClick={(e) => { e.stopPropagation(); navigate(`/admin/ist/${ist.transfer_id}/receipt`); }}
                              >
                                Print Receipt
                              </button>
                              <a
                                href={istAPI.receiptPdfUrl(ist.transfer_id)}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="po-btn"
                                style={{ fontSize: 11, padding: '4px 10px', background: '#f59e0b', color: '#fff', textDecoration: 'none' }}
                                onClick={(e) => e.stopPropagation()}
                              >
                                PDF
                              </a>
                            </div>
                          ) : viewTab === 'incoming' ? (
                            <div style={{ display: 'flex', gap: 4 }}>
                              <button
                                type="button"
                                className="po-btn"
                                style={{ fontSize: 11, padding: '4px 10px' }}
                                onClick={(e) => { e.stopPropagation(); navigate(`/admin/ist/${ist.transfer_id}`); }}
                              >
                                View
                              </button>
                              <button
                                type="button"
                                className="po-btn"
                                style={{ fontSize: 11, padding: '4px 10px', background: '#1e293b', color: '#fff' }}
                                onClick={(e) => { e.stopPropagation(); navigate(`/admin/ist/${ist.transfer_id}/receive`); }}
                              >
                                Confirm Receipt
                              </button>
                            </div>
                          ) : ist.status?.status_name === 'Approved' ? (
                            <div style={{ display: 'flex', gap: 4 }}>
                              <button
                                type="button"
                                className="po-btn"
                                style={{ fontSize: 11, padding: '4px 10px' }}
                                onClick={(e) => { e.stopPropagation(); navigate(`/admin/ist/${ist.transfer_id}`); }}
                              >
                                View
                              </button>
                              <button
                                type="button"
                                className="po-btn"
                                style={{ fontSize: 11, padding: '4px 10px', background: '#1e293b', color: '#fff' }}
                                onClick={(e) => { e.stopPropagation(); navigate(`/admin/ist/${ist.transfer_id}/dispatch`); }}
                              >
                                Scan / Dispatch
                              </button>
                            </div>
                          ) : (
                            <button
                              type="button"
                              className="po-btn"
                              style={{ fontSize: 11, padding: '4px 10px' }}
                              onClick={(e) => { e.stopPropagation(); navigate(`/admin/ist/${ist.transfer_id}`); }}
                            >
                              View
                            </button>
                          )}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          </div>
        )}

      </div>
    </AdminLayout>
  );
};

export default ISTManagementPage;
