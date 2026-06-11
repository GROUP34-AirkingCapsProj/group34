import React, { useEffect, useState } from 'react';
import AdminLayout from '../components/AdminLayout';
import { permissionsAPI } from '../services/api';
import { ROLE_COLUMNS } from '../utils/permissions';
import { toast } from '../utils/toast';
import '../styles/permission_admin.css';

const CHANGE_TYPE_LABEL = {
  role_default: 'Role default',
  user_override: 'User override',
  user_override_clear: 'Override cleared',
};

const roleName = (roleId) => {
  const r = ROLE_COLUMNS.find((x) => x.id === Number(roleId));
  return r ? r.name : `Role #${roleId}`;
};

const boolLabel = (v) => (v ? <span className="on">Allowed</span> : <span className="off">Denied</span>);

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

  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => { load(); }, []);

  const set = (k) => (e) => setFilters((f) => ({ ...f, [k]: e.target.value }));

  return (
    <AdminLayout>
      <div className="perm-admin">
        <div className="perm-header">
          <h1>Permission Audit Log</h1>
          <p>Every change to a role default or a user override, with who made it, when, and why.</p>
        </div>

        <div className="perm-card">
          <div className="perm-toolbar">
            <select value={filters.change_type} onChange={set('change_type')}>
              <option value="">All change types</option>
              <option value="role_default">Role default</option>
              <option value="user_override">User override</option>
              <option value="user_override_clear">Override cleared</option>
            </select>
            <input placeholder="Feature (e.g. inventory_transfers)" value={filters.feature} onChange={set('feature')} />
            <input placeholder="Action (e.g. approve)" value={filters.action} onChange={set('action')} />
            <input type="date" value={filters.date_from} onChange={set('date_from')} title="From date" />
            <input type="date" value={filters.date_to} onChange={set('date_to')} title="To date" />
            <button className="perm-btn-primary" onClick={load} disabled={loading}>Apply filters</button>
          </div>

          <div style={{ overflowX: 'auto' }}>
            <table className="perm-table perm-table--left">
              <thead>
                <tr>
                  <th>When</th><th>Changed by</th><th>Type</th><th>Target</th>
                  <th>Feature</th><th>Action</th><th>Old &rarr; New</th><th>Reason</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((r) => (
                  <tr key={r.log_id}>
                    <td>{r.changed_at ? new Date(r.changed_at).toLocaleString() : ''}</td>
                    <td>{r.changed_by?.first_name ? `${r.changed_by.first_name} ${r.changed_by.last_name}` : (r.changed_by ?? '—')}</td>
                    <td><span className={`perm-badge perm-badge--${r.change_type}`}>{CHANGE_TYPE_LABEL[r.change_type] ?? r.change_type}</span></td>
                    <td>{r.target_user_id
                      ? (r.target_user ? `${r.target_user.first_name} ${r.target_user.last_name}` : `User #${r.target_user_id}`)
                      : roleName(r.target_role_id)}</td>
                    <td>{r.permission?.feature}</td>
                    <td style={{ textTransform: 'capitalize' }}>{r.permission?.action}</td>
                    <td className="perm-oldnew">
                      {r.old_value === null || r.old_value === undefined ? <span className="off">—</span> : boolLabel(!!r.old_value)}
                      {' → '}
                      {r.change_type === 'user_override_clear' ? <span className="off">role default</span> : boolLabel(!!r.new_value)}
                    </td>
                    <td>{r.reason}</td>
                  </tr>
                ))}
                {!loading && rows.length === 0 && (
                  <tr><td colSpan="8" style={{ textAlign: 'center' }}>No entries</td></tr>
                )}
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </AdminLayout>
  );
};

export default PermissionAuditLog;
