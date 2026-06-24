import React, { useState, useEffect, useCallback } from 'react';
import AdminLayout from '../components/AdminLayout';
import Loader from '../components/Loader';
import { barcodeAdjustmentAPI } from '../services/api';
import '../styles/dashboard_air.css';
import '../styles/compliance_review.css';
import DateInput from '../components/DateInput';

const ACTION_LABELS = {
  barcode_correction:    { label: 'Correction',  color: '#dc2626', bg: '#fef2f2' },
  receiving:             { label: 'Receiving',   color: '#059669', bg: '#ecfdf5' },
  inventory_adjustment:  { label: 'Adjustment',  color: '#ea580c', bg: '#fff7ed' },
  stock_transfer:        { label: 'Transfer',    color: '#2563eb', bg: '#eff6ff' },
  stock_issuance:        { label: 'Issuance',    color: '#7c3aed', bg: '#f5f3ff' },
};

const ADJ_TYPE_LABELS = {
  barcode_correction: 'Barcode Fix',
  'Stock Count':      'Stock Count',
  'Found Stock':      'Found Stock',
  'Damage Write-off': 'Write-off',
  'Theft Loss':       'Theft Loss',
  Expiry:             'Expiry',
  Other:              'Other',
};

const fmt = (ts) => {
  if (!ts) return '—';
  return new Date(ts).toLocaleString('en-US', {
    month: '2-digit', day: '2-digit', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  });
};

const fmtDate = (d) => {
  if (!d) return '—';
  return new Date(d).toLocaleDateString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric' });
};

const JsonPreview = ({ values }) => {
  if (!values || typeof values !== 'object') return <span style={{ color: '#9ca3af' }}>—</span>;
  const entries = Object.entries(values).filter(([k]) => !['user_agent'].includes(k));
  if (entries.length === 0) return <span style={{ color: '#9ca3af' }}>—</span>;
  return (
    <span style={{ fontSize: 11, color: '#374151' }}>
      {entries.slice(0, 3).map(([k, v]) => (
        <span key={k} style={{ display: 'inline-block', marginRight: 6 }}>
          <span style={{ color: '#9ca3af' }}>{k}:</span> <strong>{String(v ?? '').slice(0, 30)}</strong>
        </span>
      ))}
      {entries.length > 3 && <span style={{ color: '#9ca3af' }}>+{entries.length - 3} more</span>}
    </span>
  );
};

const ActionBadge = ({ action }) => {
  const meta = ACTION_LABELS[action] || { label: action, color: '#6b7280', bg: '#f3f4f6' };
  return (
    <span style={{
      display: 'inline-block', padding: '2px 8px', borderRadius: 4, fontSize: 10,
      fontWeight: 700, letterSpacing: 0.3, background: meta.bg, color: meta.color,
    }}>
      {meta.label}
    </span>
  );
};

const StatusBadge = ({ name }) => {
  const color = name === 'Approved' ? '#059669' : name === 'Rejected' ? '#dc2626' : '#b45309';
  const bg    = name === 'Approved' ? '#ecfdf5' : name === 'Rejected' ? '#fef2f2' : '#fff7ed';
  return (
    <span style={{
      display: 'inline-block', padding: '2px 8px', borderRadius: 4, fontSize: 10,
      fontWeight: 700, background: bg, color,
    }}>
      {name || 'Pending'}
    </span>
  );
};

const ComplianceReview = () => {
  const [activeTab, setActiveTab] = useState('logs');

  // Logs tab state
  const [logs, setLogs]           = useState([]);
  const [logsLoading, setLogsLoading] = useState(false);
  const [logsPage, setLogsPage]   = useState(1);
  const [logsTotalPages, setLogsTotalPages] = useState(1);
  const [logsTotal, setLogsTotal] = useState(0);

  // Adjustments tab state
  const [adjustments, setAdjustments]       = useState([]);
  const [adjLoading, setAdjLoading]         = useState(false);
  const [adjPage, setAdjPage]               = useState(1);
  const [adjTotalPages, setAdjTotalPages]   = useState(1);
  const [adjTotal, setAdjTotal]             = useState(0);
  const [expandedAdj, setExpandedAdj]       = useState(null);

  // Shared filters
  const [dateFrom, setDateFrom]         = useState('');
  const [dateTo, setDateTo]             = useState('');
  const [actionFilter, setActionFilter] = useState('');
  const [error, setError]               = useState(null);

  const fetchLogs = useCallback(async () => {
    setLogsLoading(true);
    setError(null);
    try {
      const params = { page: logsPage };
      if (dateFrom) params.date_from = dateFrom;
      if (dateTo)   params.date_to   = dateTo;
      if (actionFilter) params.action = actionFilter;
      const res = await barcodeAdjustmentAPI.getComplianceLogs(params);
      if (res.success) {
        setLogs(res.data?.data ?? []);
        setLogsTotalPages(res.data?.last_page ?? 1);
        setLogsTotal(res.data?.total ?? 0);
      }
    } catch (e) {
      setError('Failed to load compliance logs: ' + e.message);
    } finally {
      setLogsLoading(false);
    }
  }, [logsPage, dateFrom, dateTo, actionFilter]);

  const fetchAdjustments = useCallback(async () => {
    setAdjLoading(true);
    setError(null);
    try {
      const params = { page: adjPage };
      if (dateFrom) params.date_from = dateFrom;
      if (dateTo)   params.date_to   = dateTo;
      const res = await barcodeAdjustmentAPI.getCorrectionHistory(params);
      if (res.success) {
        setAdjustments(res.data?.data ?? []);
        setAdjTotalPages(res.data?.last_page ?? 1);
        setAdjTotal(res.data?.total ?? 0);
      }
    } catch (e) {
      setError('Failed to load adjustment history: ' + e.message);
    } finally {
      setAdjLoading(false);
    }
  }, [adjPage, dateFrom, dateTo]);

  useEffect(() => {
    if (activeTab === 'logs') fetchLogs();
    else fetchAdjustments();
  }, [activeTab, fetchLogs, fetchAdjustments]);

  const clearFilters = () => {
    setDateFrom(''); setDateTo(''); setActionFilter('');
    setLogsPage(1); setAdjPage(1);
  };

  // Scorecard metrics derived from loaded data
  const correctionCount = adjustments.filter(a => a.adjustment_type === 'barcode_correction').length;
  const pendingCount    = adjustments.filter(a => a.status?.status_name === 'Pending').length;

  return (
    <AdminLayout>
      <div className="ak-page cr-page">
        {/* Header */}
        <header className="ak-header">
          <div className="ak-header-row">
            <div className="cr-page-header-left">
              <p className="ak-eyebrow">Governance · Compliance</p>
              <h1 className="ak-title">Compliance Review</h1>
              <p className="ak-sub">Audit internal controls and operational adherence.</p>
            </div>
            <div className="ak-header-aside">
            <button className="ak-btn ak-btn--ghost cr-btn-export" onClick={() => window.print()}>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                <polyline points="7 10 12 15 17 10"></polyline>
                <line x1="12" y1="15" x2="12" y2="3"></line>
              </svg>
              EXPORT AUDIT FINDINGS
            </button>
            </div>
          </div>
        </header>

        {/* Tabs */}
        <div className="cr-tabs">
          <button className={`cr-tab ${activeTab === 'logs' ? 'active' : ''}`} onClick={() => { setActiveTab('logs'); setLogsPage(1); }}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14">
              <circle cx="11" cy="11" r="8"></circle><path d="m21 21-4.35-4.35"></path>
            </svg>
            Compliance Logs
          </button>
          <button className={`cr-tab ${activeTab === 'adjustments' ? 'active' : ''}`} onClick={() => { setActiveTab('adjustments'); setAdjPage(1); }}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14">
              <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
              <polyline points="14 2 14 8 20 8"></polyline>
            </svg>
            Adjustment History
          </button>
        </div>

        {/* Filters */}
        <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', marginBottom: 18, alignItems: 'center' }}>
          <DateInput value={dateFrom} onChange={e => { setDateFrom(e.target.value); setLogsPage(1); setAdjPage(1); }}
            style={{ padding: '8px 12px', border: '1px solid #d1d5db', borderRadius: 8, fontSize: 13 }} />
          <span style={{ color: '#9ca3af', fontSize: 12 }}>to</span>
          <DateInput value={dateTo} onChange={e => { setDateTo(e.target.value); setLogsPage(1); setAdjPage(1); }}
            style={{ padding: '8px 12px', border: '1px solid #d1d5db', borderRadius: 8, fontSize: 13 }} />
          {activeTab === 'logs' && (
            <select value={actionFilter} onChange={e => { setActionFilter(e.target.value); setLogsPage(1); }}
              style={{ padding: '8px 12px', border: '1px solid #d1d5db', borderRadius: 8, fontSize: 13, background: '#fff' }}>
              <option value="">All Actions</option>
              <option value="barcode_correction">Barcode Correction</option>
              <option value="receiving">Receiving</option>
              <option value="inventory_adjustment">Adjustment</option>
              <option value="stock_transfer">Transfer</option>
              <option value="stock_issuance">Issuance</option>
            </select>
          )}
          {(dateFrom || dateTo || actionFilter) && (
            <button onClick={clearFilters}
              style={{ padding: '8px 14px', border: '1px solid #d1d5db', borderRadius: 8, fontSize: 12, background: '#fff', cursor: 'pointer', color: '#374151' }}>
              Clear Filters
            </button>
          )}
          <span style={{ marginLeft: 'auto', fontSize: 12, color: '#9ca3af' }}>
            {activeTab === 'logs' ? `${logsTotal} log(s)` : `${adjTotal} adjustment(s)`}
          </span>
        </div>

        {error && (
          <div style={{ background: '#fef2f2', border: '1px solid #fecaca', borderRadius: 8, padding: '10px 14px', marginBottom: 16, color: '#dc2626', fontSize: 13 }}>
            {error}
          </div>
        )}

        {/* Content Layout */}
        <div className="cr-content-layout">
          <div className="cr-main-content">

            {/* === COMPLIANCE LOGS TAB === */}
            {activeTab === 'logs' && (
              <>
                <div className="cr-section-header">
                  <h3>Compliance Logs</h3>
                  <p>Live audit trail — inventory and scan events</p>
                </div>
                {logsLoading ? (
                  <Loader label="Loading logs" />
                ) : logs.length === 0 ? (
                  <div style={{ textAlign: 'center', padding: 40, color: '#9ca3af', fontSize: 13 }}>No compliance logs found for the selected filters.</div>
                ) : (
                  <div style={{ overflowX: 'auto' }}>
                    <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
                      <thead>
                        <tr style={{ borderBottom: '1px solid #f3f4f6' }}>
                          {['Date', 'User', 'Action', 'Table', 'Before', 'After'].map(h => (
                            <th key={h} style={{ textAlign: 'left', padding: '8px 10px', fontSize: 10, fontWeight: 700, color: '#9ca3af', letterSpacing: 0.5, textTransform: 'uppercase', whiteSpace: 'nowrap' }}>{h}</th>
                          ))}
                        </tr>
                      </thead>
                      <tbody>
                        {logs.map(log => (
                          <tr key={log.audit_id} style={{ borderBottom: '1px solid #f9fafb' }}>
                            <td style={{ padding: '10px 10px', whiteSpace: 'nowrap', color: '#374151' }}>{fmt(log.created_at)}</td>
                            <td style={{ padding: '10px 10px', color: '#374151' }}>
                              {log.user?.first_name
                                ? `${log.user.first_name} ${log.user.last_name ?? ''}`.trim()
                                : log.user?.username ?? <span style={{ color: '#9ca3af' }}>System</span>}
                            </td>
                            <td style={{ padding: '10px 10px' }}><ActionBadge action={log.action} /></td>
                            <td style={{ padding: '10px 10px', color: '#6b7280', fontSize: 11 }}>{log.table_name ?? '—'}</td>
                            <td style={{ padding: '10px 10px', maxWidth: 200 }}><JsonPreview values={log.old_values} /></td>
                            <td style={{ padding: '10px 10px', maxWidth: 200 }}><JsonPreview values={log.new_values} /></td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
                {/* Pagination */}
                {logsTotalPages > 1 && (
                  <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginTop: 16, justifyContent: 'center' }}>
                    <button onClick={() => setLogsPage(p => Math.max(1, p - 1))} disabled={logsPage === 1}
                      style={{ padding: '6px 14px', border: '1px solid #d1d5db', borderRadius: 6, fontSize: 12, cursor: logsPage === 1 ? 'not-allowed' : 'pointer', background: '#fff', color: logsPage === 1 ? '#d1d5db' : '#374151' }}>
                      Previous
                    </button>
                    <span style={{ fontSize: 12, color: '#6b7280' }}>Page {logsPage} of {logsTotalPages}</span>
                    <button onClick={() => setLogsPage(p => Math.min(logsTotalPages, p + 1))} disabled={logsPage === logsTotalPages}
                      style={{ padding: '6px 14px', border: '1px solid #d1d5db', borderRadius: 6, fontSize: 12, cursor: logsPage === logsTotalPages ? 'not-allowed' : 'pointer', background: '#fff', color: logsPage === logsTotalPages ? '#d1d5db' : '#374151' }}>
                      Next
                    </button>
                  </div>
                )}
              </>
            )}

            {/* === ADJUSTMENT HISTORY TAB === */}
            {activeTab === 'adjustments' && (
              <>
                <div className="cr-section-header">
                  <h3>Adjustment History</h3>
                  <p>All barcode corrections and inventory adjustments</p>
                </div>
                {adjLoading ? (
                  <Loader label="Loading adjustments" />
                ) : adjustments.length === 0 ? (
                  <div style={{ textAlign: 'center', padding: 40, color: '#9ca3af', fontSize: 13 }}>No adjustments found for the selected filters.</div>
                ) : (
                  <div className="cr-exceptions-list">
                    {adjustments.map(adj => {
                      const isExpanded = expandedAdj === adj.adjustment_id;
                      const typeMeta = adj.adjustment_type === 'barcode_correction'
                        ? { color: '#dc2626', bg: '#fef2f2' }
                        : { color: '#ea580c', bg: '#fff7ed' };
                      return (
                        <div key={adj.adjustment_id} className="cr-exception-card" style={{ cursor: 'default' }}>
                          <div className="cr-exception-top">
                            <span style={{ fontSize: 9, fontWeight: 800, padding: '3px 10px', borderRadius: 4, letterSpacing: 0.5, background: typeMeta.bg, color: typeMeta.color }}>
                              {ADJ_TYPE_LABELS[adj.adjustment_type] ?? adj.adjustment_type ?? 'ADJUSTMENT'}
                            </span>
                            <span className="cr-exception-location">{adj.location?.location_name ?? '—'}</span>
                          </div>
                          <h4 className="cr-exception-title">{adj.adjustment_number}</h4>
                          <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 8, display: 'flex', gap: 16, flexWrap: 'wrap' }}>
                            <span><strong>Date:</strong> {fmtDate(adj.adjustment_date)}</span>
                            <span><strong>PO:</strong> {adj.purchase_order?.po_number ?? '—'}</span>
                            <span><strong>By:</strong> {adj.created_by_user?.first_name ? `${adj.created_by_user.first_name} ${adj.created_by_user.last_name ?? ''}`.trim() : adj.created_by_user?.username ?? '—'}</span>
                            <span><strong style={{ color: '#10b981' }}>+{adj.total_variance_positive ?? 0}</strong> / <strong style={{ color: '#ef4444' }}>−{adj.total_variance_negative ?? 0}</strong></span>
                            <span><StatusBadge name={adj.status?.status_name} /></span>
                          </div>
                          {adj.corrections?.length > 0 && (
                            <div className="cr-exception-actions">
                              <button className="cr-btn-investigate" onClick={() => setExpandedAdj(isExpanded ? null : adj.adjustment_id)}>
                                {isExpanded ? 'HIDE' : `VIEW ${adj.corrections.length} CORRECTION(S)`}
                              </button>
                            </div>
                          )}
                          {isExpanded && adj.corrections?.map(c => (
                            <div key={c.correction_id} style={{ marginTop: 12, background: '#f9fafb', borderRadius: 8, padding: '12px 14px', fontSize: 12 }}>
                              <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', alignItems: 'center', marginBottom: 6 }}>
                                <span style={{ fontWeight: 700, color: '#374151' }}>{c.original_product?.product_name ?? '—'}</span>
                                <svg viewBox="0 0 24 24" fill="none" stroke="#9ca3af" strokeWidth="2" width="14" height="14"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                                <span style={{ fontWeight: 700, color: '#059669' }}>{c.corrected_product?.product_name ?? '—'}</span>
                                <span style={{ fontSize: 10, fontWeight: 700, padding: '2px 7px', borderRadius: 4, background: '#eff6ff', color: '#2563eb' }}>
                                  {c.correction_type?.replace(/_/g, ' ').toUpperCase() ?? '—'}
                                </span>
                              </div>
                              <div style={{ color: '#6b7280', display: 'flex', gap: 14, flexWrap: 'wrap' }}>
                                <span><strong>Barcode:</strong> {c.original_barcode ?? '—'} → {c.corrected_barcode ?? '—'}</span>
                                <span><strong>By:</strong> {c.corrected_by_user?.first_name ? `${c.corrected_by_user.first_name} ${c.corrected_by_user.last_name ?? ''}`.trim() : '—'}</span>
                              </div>
                              {c.reason && <div style={{ marginTop: 4, color: '#374151', fontStyle: 'italic' }}>"{c.reason}"</div>}
                            </div>
                          ))}
                        </div>
                      );
                    })}
                  </div>
                )}
                {/* Pagination */}
                {adjTotalPages > 1 && (
                  <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginTop: 16, justifyContent: 'center' }}>
                    <button onClick={() => setAdjPage(p => Math.max(1, p - 1))} disabled={adjPage === 1}
                      style={{ padding: '6px 14px', border: '1px solid #d1d5db', borderRadius: 6, fontSize: 12, cursor: adjPage === 1 ? 'not-allowed' : 'pointer', background: '#fff', color: adjPage === 1 ? '#d1d5db' : '#374151' }}>
                      Previous
                    </button>
                    <span style={{ fontSize: 12, color: '#6b7280' }}>Page {adjPage} of {adjTotalPages}</span>
                    <button onClick={() => setAdjPage(p => Math.min(adjTotalPages, p + 1))} disabled={adjPage === adjTotalPages}
                      style={{ padding: '6px 14px', border: '1px solid #d1d5db', borderRadius: 6, fontSize: 12, cursor: adjPage === adjTotalPages ? 'not-allowed' : 'pointer', background: '#fff', color: adjPage === adjTotalPages ? '#d1d5db' : '#374151' }}>
                      Next
                    </button>
                  </div>
                )}
              </>
            )}
          </div>

          {/* Sidebar */}
          <div className="cr-sidebar">
            <div className="cr-scorecard">
              <h3 className="cr-scorecard-title">Session Summary</h3>
              <div className="cr-score-metrics">
                <div className="cr-score-metric">
                  <span>Total Logs Loaded</span>
                  <span className="cr-metric-val">{logsTotal}</span>
                </div>
                <div className="cr-score-metric">
                  <span>Adjustments Loaded</span>
                  <span className="cr-metric-val">{adjTotal}</span>
                </div>
                <div className="cr-score-metric">
                  <span>Barcode Corrections</span>
                  <span className="cr-metric-val cr-metric-red">{correctionCount}</span>
                </div>
                <div className="cr-score-metric">
                  <span>Pending Approvals</span>
                  <span className="cr-metric-val" style={{ color: '#b45309' }}>{pendingCount}</span>
                </div>
              </div>
            </div>
            <div className="cr-security-card">
              <h3 className="cr-security-title">Action Types</h3>
              {Object.entries(ACTION_LABELS).map(([key, meta]) => (
                <div key={key} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
                  <span style={{ fontSize: 12, color: '#6b7280' }}>{meta.label}</span>
                  <span style={{ fontSize: 10, fontWeight: 700, padding: '2px 8px', borderRadius: 4, background: meta.bg, color: meta.color }}>
                    {logs.filter(l => l.action === key).length}
                  </span>
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>
    </AdminLayout>
  );
};

export default ComplianceReview;
