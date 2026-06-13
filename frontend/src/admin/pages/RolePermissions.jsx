import React, { useState, useEffect, useCallback } from 'react';
import AdminLayout from '../components/AdminLayout';
import { permissionsAPI } from '../services/api';
import { toast } from '../utils/toast';
import { ROLE_COLUMNS, ACTIONS, ACTION_META, groupByFeatureGroup } from '../utils/permissions';
import '../styles/dashboard_air.css';

const ADMIN_ROLE_ID = 1;

const RolePermissions = () => {
  const [catalog, setCatalog]   = useState([]);
  const [granted, setGranted]   = useState({}); // granted[role_id][permission_id] = bool
  const [pending, setPending]   = useState({}); // pending[role_id][permission_id] = bool (diff only)
  const [loading, setLoading]   = useState(true);
  const [saving, setSaving]     = useState(false);
  const [error, setError]       = useState(null);

  const load = useCallback(async () => {
    try {
      setLoading(true);
      setError(null);
      const [catRes, roleRes] = await Promise.all([
        permissionsAPI.catalog(),
        permissionsAPI.getRoleDefaults(),
      ]);
      const cat  = Array.isArray(catRes?.data)  ? catRes.data  : [];
      const rows = Array.isArray(roleRes?.data) ? roleRes.data : [];

      // Build grant lookup: granted[role_id][permission_id] = bool
      const g = {};
      rows.forEach(({ role_id, permission_id, is_granted }) => {
        if (!g[role_id]) g[role_id] = {};
        g[role_id][permission_id] = Boolean(is_granted);
      });
      setCatalog(cat);
      setGranted(g);
      setPending({});
    } catch (err) {
      setError(err.message || 'Failed to load permissions.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { load(); }, [load]);

  const isGranted = (roleId, permId) => {
    if (pending[roleId] && permId in pending[roleId]) return pending[roleId][permId];
    return !!(granted[roleId] && granted[roleId][permId]);
  };

  const toggle = (roleId, permId) => {
    if (roleId === ADMIN_ROLE_ID) return; // admin is read-only
    const current = isGranted(roleId, permId);
    const original = !!(granted[roleId] && granted[roleId][permId]);
    const next = !current;

    setPending((prev) => {
      const roleMap = { ...(prev[roleId] || {}) };
      if (next === original) {
        // revert to original — remove from pending
        delete roleMap[permId];
      } else {
        roleMap[permId] = next;
      }
      const newPending = { ...prev, [roleId]: roleMap };
      if (Object.keys(newPending[roleId]).length === 0) delete newPending[roleId];
      return newPending;
    });
  };

  const hasPendingChanges = Object.values(pending).some((m) => Object.keys(m).length > 0);

  const handleSave = async () => {
    const changes = [];
    Object.entries(pending).forEach(([roleId, perms]) => {
      Object.entries(perms).forEach(([permId, isGrantedVal]) => {
        changes.push({
          role_id: Number(roleId),
          permission_id: Number(permId),
          is_granted: isGrantedVal,
        });
      });
    });
    if (changes.length === 0) return;

    setSaving(true);
    try {
      await permissionsAPI.updateRoleDefaults(changes);
      toast.success('Role permissions saved successfully.');
      await load();
    } catch (err) {
      toast.error(err.message || 'Failed to save permissions.');
    } finally {
      setSaving(false);
    }
  };

  const groups = groupByFeatureGroup(catalog);

  const pendingCount = Object.values(pending).reduce(
    (acc, m) => acc + Object.keys(m).length,
    0
  );

  return (
    <AdminLayout>
      <div className="cb-page">
        <div className="cb-page-header">
          <div>
            <h1>Role &amp; Permissions</h1>
            <p>Configure default permission grants per role. The System Administrator column is always full-access and cannot be changed.</p>
          </div>
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem' }}>
            {pendingCount > 0 && (
              <span style={{ fontSize: '0.8rem', color: 'var(--text-muted)' }}>
                {pendingCount} unsaved change{pendingCount !== 1 ? 's' : ''}
              </span>
            )}
            <button
              type="button"
              className="cb-btn-primary"
              onClick={handleSave}
              disabled={!hasPendingChanges || saving}
              style={{ minWidth: 130 }}
            >
              {saving ? 'Saving…' : 'Save Changes'}
            </button>
          </div>
        </div>

        {loading && <div className="cb-loading">Loading permission matrix…</div>}
        {error   && <div className="cb-error">Error: {error}</div>}

        {!loading && !error && (
          <div style={{ overflowX: 'auto' }}>
            <table className="cb-table" style={{ minWidth: 760 }}>
              <thead>
                <tr>
                  <th style={{ minWidth: 200 }}>Feature / Action</th>
                  {ROLE_COLUMNS.map((r) => (
                    <th key={r.id} style={{ textAlign: 'center', color: r.accent, minWidth: 70 }}>
                      <span title={r.name}>{r.label}</span>
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {groups.map((group) => (
                  <React.Fragment key={group.label}>
                    {/* Group header row */}
                    <tr>
                      <td
                        colSpan={ROLE_COLUMNS.length + 1}
                        style={{
                          background: 'var(--bg-surface, #f8fafc)',
                          fontWeight: 700,
                          fontSize: '0.78rem',
                          letterSpacing: '0.04em',
                          color: 'var(--text-muted)',
                          padding: '0.55rem 0.75rem',
                          textTransform: 'uppercase',
                        }}
                      >
                        {group.label}
                      </td>
                    </tr>

                    {group.features.map((feat) =>
                      ACTIONS.filter((a) => feat.actions[a] != null).map((action) => {
                        const permId = feat.actions[action];
                        return (
                          <tr key={`${feat.feature}.${action}`}>
                            <td>
                              <span style={{ fontWeight: 500 }}>{feat.title}</span>
                              <span
                                style={{
                                  marginLeft: '0.5rem',
                                  fontSize: '0.75rem',
                                  color: 'var(--text-muted)',
                                  background: 'var(--bg-surface, #f1f5f9)',
                                  borderRadius: 4,
                                  padding: '1px 6px',
                                }}
                              >
                                {ACTION_META[action]?.label || action}
                              </span>
                            </td>
                            {ROLE_COLUMNS.map((r) => {
                              const checked = isGranted(r.id, permId);
                              const isAdmin = r.id === ADMIN_ROLE_ID;
                              const isDirty =
                                !isAdmin &&
                                pending[r.id] &&
                                permId in pending[r.id];
                              return (
                                <td key={r.id} style={{ textAlign: 'center' }}>
                                  <input
                                    type="checkbox"
                                    checked={checked}
                                    disabled={isAdmin}
                                    onChange={() => toggle(r.id, permId)}
                                    style={{
                                      accentColor: isDirty ? '#f59e0b' : r.accent,
                                      width: 16,
                                      height: 16,
                                      cursor: isAdmin ? 'not-allowed' : 'pointer',
                                    }}
                                    title={isAdmin ? 'Admin always has full access' : `${r.name}: ${ACTION_META[action]?.desc || action}`}
                                  />
                                </td>
                              );
                            })}
                          </tr>
                        );
                      })
                    )}
                  </React.Fragment>
                ))}
              </tbody>
            </table>

            {/* Legend */}
            <div style={{ marginTop: '1rem', display: 'flex', gap: '1.5rem', flexWrap: 'wrap', fontSize: '0.8rem', color: 'var(--text-muted)' }}>
              {ROLE_COLUMNS.map((r) => (
                <span key={r.id} style={{ display: 'flex', alignItems: 'center', gap: 4 }}>
                  <span
                    style={{
                      width: 10, height: 10,
                      borderRadius: '50%',
                      background: r.accent,
                      display: 'inline-block',
                    }}
                  />
                  {r.label} — {r.name}
                </span>
              ))}
              <span style={{ display: 'flex', alignItems: 'center', gap: 4 }}>
                <span style={{ width: 10, height: 10, borderRadius: '50%', background: '#f59e0b', display: 'inline-block' }} />
                Unsaved change
              </span>
            </div>
          </div>
        )}
      </div>
    </AdminLayout>
  );
};

export default RolePermissions;
