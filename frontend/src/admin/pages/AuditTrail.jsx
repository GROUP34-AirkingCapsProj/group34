import React, { useEffect, useMemo, useState } from 'react';
import AdminLayout from '../components/AdminLayout';
import { auditAPI } from '../services/api';
import { toast } from '../utils/toast';
import '../styles/dashboard_air.css';
import '../styles/audit_trail.css';

const AuditTrail = () => {
  const [searchTerm, setSearchTerm] = useState('');
  const [logs, setLogs] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    let mounted = true;
    const loadAuditLogs = async () => {
      setLoading(true);
      setError('');
      try {
        const res = await auditAPI.getTrail({ per_page: 100 });
        const payload = Array.isArray(res?.data?.data)
          ? res.data.data
          : Array.isArray(res?.data)
            ? res.data
            : [];
        if (mounted) setLogs(payload);
      } catch (e) {
        if (!mounted) return;
        const message = e.message || 'Failed to load audit logs.';
        setError(message);
        toast.error(message);
      } finally {
        if (mounted) setLoading(false);
      }
    };
    loadAuditLogs();
    return () => { mounted = false; };
  }, []);

  const deriveModule = (tableName = '', action = '') => {
    const haystack = `${tableName} ${action}`.toLowerCase();
    if (haystack.includes('sale')) return 'SALES';
    if (haystack.includes('transfer')) return 'TRANSFER';
    if (haystack.includes('inventory') || haystack.includes('adjustment') || haystack.includes('receiving') || haystack.includes('issuance')) return 'INVENTORY';
    if (haystack.includes('audit')) return 'AUDIT TRAIL';
    return 'SYSTEM';
  };

  const getModuleClass = (module) => {
    if (module === 'SALES') return 'at-module-sales';
    if (module === 'INVENTORY') return 'at-module-inventory';
    if (module === 'TRANSFER') return 'at-module-transfer';
    if (module === 'AUDIT TRAIL') return 'at-module-audit';
    return '';
  };

  const normalizedLogs = useMemo(() => logs.map((log, index) => {
    const operatorName = `${log?.user?.first_name || ''} ${log?.user?.last_name || ''}`.trim() || log?.user?.username || 'System';
    const roleName = log?.user?.role?.role_name || 'N/A';
    const module = deriveModule(log?.table_name, log?.action);
    return {
      id: log?.audit_id || log?.id || index,
      timestamp: log?.created_at ? new Date(log.created_at).toLocaleString() : 'N/A',
      operator: operatorName,
      operatorRole: roleName,
      module,
      action: log?.action || 'N/A',
      location: log?.table_name || 'N/A',
      ip: log?.ip_address || 'N/A',
      verified: true,
    };
  }), [logs]);

  const filteredLogs = useMemo(() => normalizedLogs.filter((log) => {
    if (!searchTerm) return true;
    const q = searchTerm.toLowerCase();
    return log.operator.toLowerCase().includes(q)
      || log.action.toLowerCase().includes(q)
      || log.module.toLowerCase().includes(q)
      || log.location.toLowerCase().includes(q);
  }), [normalizedLogs, searchTerm]);

  return (
    <AdminLayout>
      <div className="at-page">
        {/* Header */}
        <div className="at-page-header">
          <h1 className="at-page-title">Audit Trail</h1>
          <p className="at-page-subtitle">Comprehensive historical record of all system interactions</p>
        </div>

        {/* Search */}
        <div className="at-search-row">
          <div className="at-search-input">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="16" height="16">
              <circle cx="11" cy="11" r="8"></circle>
              <path d="m21 21-4.35-4.35"></path>
            </svg>
            <input
              type="text"
              placeholder="Search by user, action, or details..."
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
            />
          </div>
        </div>

        {/* Logs Table */}
        <div className="at-table-card">
          <div className="at-table-top">
            <h3>Activity Logs ({filteredLogs.length})</h3>
          </div>

          <div className="at-table-wrapper">
            <table className="at-table">
              <thead>
                <tr>
                  <th>TIMESTAMP</th>
                  <th>OPERATOR</th>
                  <th>MODULE / ACTION</th>
                  <th>LOCATION / IP</th>
                  <th>VERIFICATION</th>
                </tr>
              </thead>
              <tbody>
                {loading && (
                  <tr>
                    <td colSpan="5" className="at-cell-timestamp">Loading audit logs...</td>
                  </tr>
                )}
                {!loading && error && (
                  <tr>
                    <td colSpan="5" className="at-cell-timestamp" style={{ color: '#ef4444' }}>
                      {error}
                    </td>
                  </tr>
                )}
                {!loading && !error && filteredLogs.length === 0 && (
                  <tr>
                    <td colSpan="5" className="at-cell-timestamp">No audit records found.</td>
                  </tr>
                )}
                {filteredLogs.map((log) => (
                  <tr key={log.id}>
                    <td className="at-cell-timestamp">{log.timestamp}</td>
                    <td>
                      <div className="at-operator-cell">
                        <span className="at-operator-name">{log.operator}</span>
                        <span className="at-operator-role">{log.operatorRole}</span>
                      </div>
                    </td>
                    <td>
                      <div className="at-module-cell">
                        <span className={`at-module-badge ${getModuleClass(log.module)}`}>{log.module}</span>
                        <span className="at-action-text">{log.action}</span>
                      </div>
                    </td>
                    <td className="at-cell-location">
                      {log.location} &bull; {log.ip}
                    </td>
                    <td>
                      {log.verified && (
                        <div className="at-verified-icon">
                          <svg viewBox="0 0 24 24" fill="none" stroke="#059669" strokeWidth="2.5" width="20" height="20">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                            <polyline points="22 4 12 14.01 9 11.01"></polyline>
                          </svg>
                        </div>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </AdminLayout>
  );
};

export default AuditTrail;
