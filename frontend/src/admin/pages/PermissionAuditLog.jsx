import React, { useEffect, useState } from 'react';
import AdminLayout from '../components/AdminLayout';
import { permissionsAPI } from '../services/api';
import { toast } from '../utils/toast';
import '../styles/permission_admin.css';

const PermissionAuditLog = () => {
  const [rows, setRows] = useState([]);
  const [filters, setFilters] = useState({ change_type: '', feature: '', action: '', date_from: '', date_to: '' });
  const [loading, setLoading] = useState(true);

  const load = async () => {
    setLoading(true);
    try {
      const clean = Object.fromEntries(Object.entries(filters).filter(([, v]) => v));
      const res = await permissionsAPI.getAuditLogs(clean);
      // controller returns a paginator under data; rows are at data.data
      setRows(res?.data?.data ?? res?.data ?? []);
    } catch (e) {
      toast.error(e.message || 'Failed to load audit log');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { load(); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, []);

  const set = (k) => (e) => setFilters((f) => ({ ...f, [k]: e.target.value }));

  return (
    <AdminLayout>
      <div className="perm-admin">
        <h1>Permission Audit Log</h1>

        <div className="perm-toolbar">
          <select value={filters.change_type} onChange={set('change_type')}>
            <option value="">All types</option>
            <option value="role_default">Role default</option>
            <option value="user_override">User override</option>
            <option value="user_override_clear">User override cleared</option>
          </select>
          <input placeholder="feature" value={filters.feature} onChange={set('feature')} />
          <input placeholder="action" value={filters.action} onChange={set('action')} />
          <input type="date" value={filters.date_from} onChange={set('date_from')} />
          <input type="date" value={filters.date_to} onChange={set('date_to')} />
          <button onClick={load} disabled={loading}>Apply</button>
        </div>

        <table className="perm-table">
          <thead>
            <tr>
              <th>When</th><th>Changed by</th><th>Type</th><th>Target</th>
              <th>Feature</th><th>Action</th><th>Old→New</th><th>Reason</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.log_id}>
                <td>{r.changed_at ? new Date(r.changed_at).toLocaleString() : ''}</td>
                <td>{r.changed_by?.first_name ? `${r.changed_by.first_name} ${r.changed_by.last_name}` : (r.changed_by ?? '')}</td>
                <td>{r.change_type}</td>
                <td>{r.target_user_id
                  ? (r.target_user ? `${r.target_user.first_name} ${r.target_user.last_name}` : `User #${r.target_user_id}`)
                  : `Role #${r.target_role_id}`}</td>
                <td>{r.permission?.feature}</td>
                <td>{r.permission?.action}</td>
                <td>{String(!!r.old_value)} → {String(!!r.new_value)}</td>
                <td>{r.reason}</td>
              </tr>
            ))}
            {!loading && rows.length === 0 && (
              <tr><td colSpan="8" style={{ textAlign: 'center' }}>No entries</td></tr>
            )}
          </tbody>
        </table>
      </div>
    </AdminLayout>
  );
};

export default PermissionAuditLog;
