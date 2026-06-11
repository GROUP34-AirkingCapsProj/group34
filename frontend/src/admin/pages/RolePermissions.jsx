import React, { useEffect, useMemo, useState } from 'react';
import AdminLayout from '../components/AdminLayout';
import { permissionsAPI } from '../services/api';
import { groupByFeatureGroup, ROLE_COLUMNS, ACTIONS } from '../utils/permissions';
import { toast } from '../utils/toast';
import '../styles/permission_admin.css';

const RolePermissions = () => {
  const [catalog, setCatalog] = useState([]);
  const [grants, setGrants] = useState({}); // `${roleId}:${permId}` => bool
  const [dirty, setDirty] = useState({});   // same key => bool (pending value)
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);

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

  return (
    <AdminLayout>
      <div className="perm-admin">
        <h1>Role Permissions</h1>
        <p>Default access per role. User-specific exceptions are managed in User Overrides.</p>

        <div className="perm-toolbar">
          <button onClick={save} disabled={saving || dirtyCount === 0}>
            {saving ? 'Saving…' : 'Save changes'}
          </button>
          {dirtyCount > 0 && <span className="perm-dirty">{dirtyCount} unsaved</span>}
        </div>

        {loading ? <div>Loading…</div> : groups.map((g) => (
          <div className="perm-group" key={g.label}>
            <h2>{g.label}</h2>
            <table className="perm-table">
              <thead>
                <tr>
                  <th>Feature</th>
                  {ACTIONS.map((a) => ROLE_COLUMNS.map((r) => (
                    <th key={`${a}-${r.id}`}>{a[0].toUpperCase()}·{r.label}</th>
                  )))}
                </tr>
              </thead>
              <tbody>
                {g.features.map((f) => (
                  <tr key={f.feature}>
                    <td>{f.title}</td>
                    {ACTIONS.map((a) => ROLE_COLUMNS.map((r) => {
                      const permId = f.actions[a];
                      if (!permId) return <td key={`${f.feature}-${a}-${r.id}`} className="perm-cell-na">&mdash;</td>;
                      return (
                        <td key={`${f.feature}-${a}-${r.id}`} className="perm-cell-toggle"
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
        ))}
      </div>
    </AdminLayout>
  );
};

export default RolePermissions;
