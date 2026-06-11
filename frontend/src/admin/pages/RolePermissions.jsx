import React, { useEffect, useMemo, useState } from 'react';
import AdminLayout from '../components/AdminLayout';
import { permissionsAPI } from '../services/api';
import { groupByFeatureGroup, ROLE_COLUMNS, ACTIONS, ACTION_META } from '../utils/permissions';
import { toast } from '../utils/toast';
import PermissionGuide from '../components/PermissionGuide';
import '../styles/permission_admin.css';

const RolePermissions = () => {
  const [catalog, setCatalog] = useState([]);
  const [grants, setGrants] = useState({}); // `${roleId}:${permId}` => bool
  const [dirty, setDirty] = useState({});   // same key => bool (pending value)
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [activeTab, setActiveTab] = useState(0);

  const load = async () => {
    setLoading(true);
    try {
      const [cat, defs] = await Promise.all([
        permissionsAPI.catalog(),
        permissionsAPI.getRoleDefaults(),
      ]);
      setCatalog(cat?.data ?? []);
      const map = {};
      (defs?.data ?? []).forEach((d) => { map[`${d.role_id}:${d.permission_id}`] = !!d.is_granted; });
      setGrants(map);
      setDirty({});
    } catch (e) {
      toast.error(e.message || 'Failed to load permissions');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { load(); }, []);

  const groups = useMemo(() => groupByFeatureGroup(catalog), [catalog]);

  const valueFor = (roleId, permId) => {
    const k = `${roleId}:${permId}`;
    return k in dirty ? dirty[k] : !!grants[k];
  };

  const toggle = (roleId, permId) => {
    const k = `${roleId}:${permId}`;
    const next = !valueFor(roleId, permId);
    setDirty((d) => {
      const copy = { ...d };
      if (next === !!grants[k]) delete copy[k]; else copy[k] = next;
      return copy;
    });
  };

  const save = async () => {
    const changes = Object.entries(dirty).map(([k, v]) => {
      const [role_id, permission_id] = k.split(':').map(Number);
      return { role_id, permission_id, is_granted: v };
    });
    if (changes.length === 0) { toast.info('No changes to save'); return; }
    setSaving(true);
    try {
      await permissionsAPI.updateRoleDefaults(changes);
      toast.success(`Saved ${changes.length} change(s)`);
      await load();
    } catch (e) {
      toast.error(e.message || 'Save failed');
    } finally {
      setSaving(false);
    }
  };

  const dirtyCount = Object.keys(dirty).length;

  const dirtyPermIds = useMemo(
    () => new Set(Object.keys(dirty).map((k) => Number(k.split(':')[1]))),
    [dirty]
  );
  const groupHasDirty = (g) => g.features.some((f) => Object.values(f.actions).some((pid) => dirtyPermIds.has(pid)));
  const activeIndex = groups.length ? Math.min(activeTab, groups.length - 1) : 0;
  const active = groups[activeIndex];

  return (
    <AdminLayout>
      <div className="perm-admin">
        <div className="perm-header">
          <h1>Role Permissions</h1>
          <p>Default access for each role. Columns are grouped by action; each action shows the five role codes (SA, IA, BM, WP, AU). Per-user exceptions are managed in User Overrides.</p>
        </div>

        <PermissionGuide />

        <div className="perm-toolbar">
          <button className="perm-btn-primary" onClick={save} disabled={saving || dirtyCount === 0}>
            {saving ? 'Saving…' : 'Save changes'}
          </button>
          {dirtyCount > 0
            ? <span className="perm-dirty">{dirtyCount} unsaved change{dirtyCount === 1 ? '' : 's'}</span>
            : <span className="perm-muted">Click a checkbox to grant or remove a default, then Save.</span>}
        </div>

        {loading ? <div className="perm-muted">Loading…</div> : (
          <>
            <div className="perm-tabs" role="tablist">
              {groups.map((g, i) => (
                <button
                  type="button"
                  key={g.label}
                  className={`perm-tab ${i === activeIndex ? 'active' : ''}`}
                  onClick={() => setActiveTab(i)}
                >
                  {g.label}
                  {groupHasDirty(g) && <span className="perm-tab-dot" title="Unsaved changes in this category" />}
                </button>
              ))}
            </div>

            {active && (
              <div className="perm-card" key={active.label}>
                <h2>{active.label}</h2>
                <div style={{ overflowX: 'auto' }}>
                  <table className="perm-table">
                    <thead>
                      <tr>
                        <th rowSpan={2} className="perm-feature-cell">Feature</th>
                        {ACTIONS.map((a) => (
                          <th key={a} colSpan={ROLE_COLUMNS.length} className="perm-action-group perm-group-start">
                            {ACTION_META[a].label}
                          </th>
                        ))}
                      </tr>
                      <tr>
                        {ACTIONS.map((a) => ROLE_COLUMNS.map((r, idx) => (
                          <th
                            key={`${a}-${r.id}`}
                            className={`perm-role-col ${idx === 0 ? 'perm-group-start' : ''}`}
                            style={{ '--role-accent': r.accent }}
                            title={`${r.name} — ${ACTION_META[a].label}`}
                          >
                            {r.label}
                          </th>
                        )))}
                      </tr>
                    </thead>
                    <tbody>
                      {active.features.map((f) => (
                        <tr key={f.feature}>
                          <td className="perm-feature-cell">{f.title}</td>
                          {ACTIONS.map((a) => ROLE_COLUMNS.map((r, idx) => {
                            const permId = f.actions[a];
                            const divider = idx === 0 ? 'perm-group-start' : '';
                            if (!permId) return <td key={`${f.feature}-${a}-${r.id}`} className={`perm-cell-na ${divider}`}>&mdash;</td>;
                            return (
                              <td key={`${f.feature}-${a}-${r.id}`} className={`perm-cell-toggle ${divider}`}
                                  title={`${r.name} can ${ACTION_META[a].label.toLowerCase()} ${f.title}`}
                                  onClick={() => toggle(r.id, permId)}>
                                <input type="checkbox" readOnly checked={valueFor(r.id, permId)} />
                              </td>
                            );
                          }))}
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            )}
          </>
        )}
      </div>
    </AdminLayout>
  );
};

export default RolePermissions;
