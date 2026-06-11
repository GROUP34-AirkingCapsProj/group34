import React, { useEffect, useMemo, useState } from 'react';
import AdminLayout from '../components/AdminLayout';
import { permissionsAPI, batchAPI } from '../services/api';
import { groupByFeatureGroup, ACTIONS, ACTION_META } from '../utils/permissions';
import { toast } from '../utils/toast';
import PermissionGuide from '../components/PermissionGuide';
import '../styles/permission_admin.css';

const UserPermissionOverrides = () => {
  const [users, setUsers] = useState([]);
  const [catalog, setCatalog] = useState([]);
  const [userId, setUserId] = useState('');
  const [effective, setEffective] = useState({}); // 'feature.action' => bool
  const [overrides, setOverrides] = useState({}); // permId => bool (active override)
  const [pending, setPending] = useState({});     // permId => bool|null (null = clear)
  const [reason, setReason] = useState('');
  const [saving, setSaving] = useState(false);
  const [activeTab, setActiveTab] = useState(0);

  useEffect(() => {
    (async () => {
      const [cat, batch] = await Promise.all([
        permissionsAPI.catalog(),
        batchAPI.get({ include: ['users'] }),
      ]);
      setCatalog(cat?.data ?? []);
      setUsers(batch?.data?.users?.data ?? []);
    })().catch((e) => toast.error(e.message));
  }, []);

  const loadUser = async (id) => {
    setUserId(id);
    setPending({});
    setReason('');
    if (!id) { setEffective({}); setOverrides({}); return; }
    try {
      const res = await permissionsAPI.getUserPermissions(id);
      setEffective(res?.data?.effective ?? {});
      const ov = {};
      (res?.data?.overrides ?? []).forEach((o) => { ov[o.permission_id] = !!o.is_granted; });
      setOverrides(ov);
    } catch (e) { toast.error(e.message); }
  };

  const groups = useMemo(() => groupByFeatureGroup(catalog), [catalog]);
  const permFeatureAction = useMemo(() => {
    const m = {};
    catalog.forEach((p) => { m[p.permission_id] = `${p.feature}.${p.action}`; });
    return m;
  }, [catalog]);

  const isOverridden = (permId) => (permId in pending ? pending[permId] !== null : permId in overrides);
  const effectiveValue = (permId) => {
    if (permId in pending) {
      if (pending[permId] !== null) return pending[permId];
    } else if (permId in overrides) {
      return overrides[permId];
    }
    return !!effective[permFeatureAction[permId]];
  };

  // Cycle: default → grant → deny → default
  const cycle = (permId) => {
    setPending((p) => {
      const copy = { ...p };
      const current = permId in copy ? copy[permId] : (permId in overrides ? overrides[permId] : 'default');
      let next;
      if (current === 'default' || current === null) next = true;
      else if (current === true) next = false;
      else next = null; // clear back to default
      copy[permId] = next;
      return copy;
    });
  };

  const save = async () => {
    const changes = Object.entries(pending).map(([permission_id, v]) => ({
      permission_id: Number(permission_id),
      is_granted: v, // true | false | null(clear)
    }));
    if (changes.length === 0) { toast.info('No changes'); return; }
    if (!reason.trim()) { toast.error('A reason is required'); return; }
    setSaving(true);
    try {
      await permissionsAPI.updateUserPermissions(userId, reason.trim(), changes);
      toast.success('Overrides saved');
      await loadUser(userId);
    } catch (e) {
      toast.error(e.message || 'Save failed');
    } finally {
      setSaving(false);
    }
  };

  const pendingPermIds = useMemo(() => new Set(Object.keys(pending).map(Number)), [pending]);
  const groupHasPending = (g) => g.features.some((f) => Object.values(f.actions).some((pid) => pendingPermIds.has(pid)));
  const activeIndex = groups.length ? Math.min(activeTab, groups.length - 1) : 0;
  const active = groups[activeIndex];

  return (
    <AdminLayout>
      <div className="perm-admin">
        <div className="perm-header">
          <h1>User Permission Overrides</h1>
          <p>Grant or deny specific permissions for one user. An override always wins over that user&rsquo;s role default.</p>
        </div>

        <PermissionGuide showOverrideKey />

        <div className="perm-toolbar">
          <select value={userId} onChange={(e) => loadUser(e.target.value)}>
            <option value="">Select a user…</option>
            {users.map((u) => (
              <option key={u.user_id} value={u.user_id}>
                {u.first_name} {u.last_name} ({u.role?.role_name})
              </option>
            ))}
          </select>
          <input placeholder="Reason (required to save)" value={reason}
                 onChange={(e) => setReason(e.target.value)} style={{ flex: 1, minWidth: 200 }} />
          <button className="perm-btn-primary" onClick={save} disabled={saving || !userId}>
            {saving ? 'Saving…' : 'Save overrides'}
          </button>
        </div>

        {!userId && <p className="perm-muted">Select a user above to view and edit their overrides.</p>}

        {userId && (
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
                  {groupHasPending(g) && <span className="perm-tab-dot" title="Unsaved changes in this category" />}
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
                        <th className="perm-feature-cell">Feature</th>
                        {ACTIONS.map((a) => <th key={a} title={ACTION_META[a].desc}>{ACTION_META[a].label}</th>)}
                      </tr>
                    </thead>
                    <tbody>
                      {active.features.map((f) => (
                        <tr key={f.feature}>
                          <td className="perm-feature-cell">{f.title}</td>
                          {ACTIONS.map((a) => {
                            const permId = f.actions[a];
                            if (!permId) return <td key={a} className="perm-cell-na">&mdash;</td>;
                            const val = effectiveValue(permId);
                            const overridden = isOverridden(permId);
                            const cls = overridden ? (val ? 'perm-override-on' : 'perm-override-off') : (val ? 'perm-allow' : 'perm-deny');
                            return (
                              <td key={a} className={`perm-cell-toggle ${cls}`}
                                  title={`${ACTION_META[a].label}: ${val ? 'allowed' : 'not allowed'}${overridden ? ' (override)' : ' (role default)'} — click to change`}
                                  onClick={() => cycle(permId)}>
                                {val ? '✓' : '✗'}{overridden ? '∗' : ''}
                              </td>
                            );
                          })}
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

export default UserPermissionOverrides;
