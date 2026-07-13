import React, { useState, useEffect, useCallback } from 'react';
import Modal from '../components/Modal';
import { permissionsAPI, navBundlesAPI } from '../services/api';
import { toast } from '../utils/toast';
import { ROLE_COLUMNS, ACTION_META, actionsForFeature, featureTitle } from '../utils/permissions';
import {
  configureFeatures,
  requiredFeatureOf,
  ORPHAN_FEATURES,
  OTHER_HOME,
  SECTION_ORDER,
} from '../utils/navConfigureMap';
import '../styles/rbac_console.css';

const ADMIN_ROLE_ID = 1;

const CheckGlyph = () => (
  <svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
    <polyline points="20 6 9 17 4 12" />
  </svg>
);

const RolePermissions = ({ tabSwitcher = null }) => {
  const [catalog, setCatalog]   = useState([]);
  const [granted, setGranted]   = useState({}); // granted[role_id][permission_id] = bool
  const [pending, setPending]   = useState({}); // pending[role_id][permission_id] = bool (diff only)
  const [loading, setLoading]   = useState(true);
  const [saving, setSaving]     = useState(false);
  const [error, setError]       = useState(null);

  const [bundles, setBundles]       = useState([]);
  const [modalHome, setModalHome]   = useState(null); // { title, blocks:[{feature, title, actions}] } | null

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

  useEffect(() => {
    navBundlesAPI.list()
      .then((res) => setBundles(Array.isArray(res?.data) ? res.data : []))
      .catch(() => setBundles([]));
  }, []);

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
    if (changes.length === 0) return false;

    setSaving(true);
    try {
      await permissionsAPI.updateRoleDefaults(changes);
      toast.success('Role permissions saved successfully.');
      await load();
      return true;
    } catch (err) {
      toast.error(err.message || 'Failed to save permissions.');
      return false;
    } finally {
      setSaving(false);
    }
  };

  // Build the ordered feature sub-blocks (feature title + its action rows) for a modal.
  const featureBlocks = (features) =>
    features
      .map((f) => ({ feature: f, title: featureTitle(f), actions: actionsForFeature(catalog, f) }))
      .filter((b) => b.actions.length > 0);

  const openBundleModal = (bundle) => {
    setModalHome({ title: `${bundle.name} — role access`, blocks: featureBlocks(configureFeatures(bundle)) });
  };

  const openOtherModal = () => {
    setModalHome({ title: `${OTHER_HOME.name} — role access`, blocks: featureBlocks(ORPHAN_FEATURES) });
  };

  // Group bundles under their sidebar-section headers, sections in SECTION_ORDER
  // (unknown sections trail alphabetically), rows by sort_order.
  const sections = (() => {
    const bySection = {};
    bundles.forEach((b) => {
      const key = b.sidebar_section || '—';
      (bySection[key] = bySection[key] || []).push(b);
    });
    const order = (s) => {
      const i = SECTION_ORDER.indexOf(s);
      return i === -1 ? SECTION_ORDER.length : i;
    };
    return Object.keys(bySection)
      .sort((a, b) => order(a) - order(b) || a.localeCompare(b))
      .map((section) => ({
        section,
        rows: bySection[section].slice().sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0)),
      }));
  })();

  // Orphan features that actually exist in the catalog → show the synthetic Other row.
  const otherFeatures = featureBlocks(ORPHAN_FEATURES);

  const pendingCount = Object.values(pending).reduce(
    (acc, m) => acc + Object.keys(m).length,
    0
  );

  return (
      <div className="rbac-console">
        {/* ── command header ── */}
        <header className="rcx-header">
          <div className="rcx-header-row">
            <div>
              <p className="rcx-eyebrow">Access Control · Role Matrix</p>
              <h1 className="rcx-title">Role&nbsp;&amp;&nbsp;Permissions</h1>
              <p className="rcx-sub">
                Configure default permission grants per role. The System Administrator column is
                always full-access and is locked.
              </p>
            </div>
            <div className="rcx-header-aside">
              <div style={{ display: 'flex', alignItems: 'center', gap: '0.7rem' }}>
                {pendingCount > 0 && (
                  <span className="rcx-pill">
                    {pendingCount} unsaved
                  </span>
                )}
                <button
                  type="button"
                  className="rcx-btn rcx-btn--primary"
                  onClick={handleSave}
                  disabled={!hasPendingChanges || saving}
                >
                  {saving ? 'Saving…' : 'Save Changes'}
                </button>
              </div>
              <div className="rcx-legend">
                {ROLE_COLUMNS.map((r) => (
                  <span key={r.id} className="rcx-legend-chip">
                    <span className="rcx-legend-code" style={{ '--rc-chip': r.accent, background: r.accent }}>{r.label}</span>
                    {r.name}
                  </span>
                ))}
              </div>
            </div>
          </div>
        </header>

        {tabSwitcher}

        {loading && <div className="rcx-state">Loading permission matrix<span className="rcx-loading-dots" /></div>}
        {error   && <div className="rcx-state rcx-state--error">Error: {error}</div>}

        {!loading && !error && (
          <div className="rcx-navlist">
            {bundles.length === 0 && <p className="rcx-muted">No navigation bundles found.</p>}

            {sections.map(({ section, rows }) => (
              <React.Fragment key={section}>
                <h3 className="rcx-navgroup">{section}</h3>
                {rows.map((bundle) => {
                  const extraCount = configureFeatures(bundle).length - 1;
                  return (
                    <div key={bundle.bundle_id} className="rcx-navrow">
                      <div className="rcx-navrow-main">
                        <span className="rcx-navrow-name">{bundle.name}</span>
                        <span className="rcx-navrow-section">
                          {featureTitle(requiredFeatureOf(bundle) || '—')}
                          {extraCount > 0 && ` · +${extraCount} more`}
                        </span>
                      </div>
                      <div className="rcx-navrow-right">
                        <button type="button" className="rcx-btn rcx-btn--ghost" onClick={() => openBundleModal(bundle)}>Configure</button>
                      </div>
                    </div>
                  );
                })}
              </React.Fragment>
            ))}

            {otherFeatures.length > 0 && (
              <React.Fragment>
                <h3 className="rcx-navgroup">{OTHER_HOME.sidebar_section}</h3>
                <div className="rcx-navrow">
                  <div className="rcx-navrow-main">
                    <span className="rcx-navrow-name">{OTHER_HOME.name}</span>
                    <span className="rcx-navrow-section">Permissions with no navigation page</span>
                  </div>
                  <div className="rcx-navrow-right">
                    <button type="button" className="rcx-btn rcx-btn--ghost" onClick={openOtherModal}>Configure</button>
                  </div>
                </div>
              </React.Fragment>
            )}
          </div>
        )}

        {/* ── Per-page permission modal (one sub-block per related feature) ── */}
        {modalHome && (
          <Modal isOpen={true} title={modalHome.title} onClose={() => setModalHome(null)} maxWidth={640}>
            <div className="rbac-console rbac-console--modal">
            {modalHome.blocks.length === 0 ? (
              <p className="rcx-muted">This page has no configurable permissions.</p>
            ) : (
              <div className="rcx-matrix-wrap" style={{ boxShadow: 'none', border: '1px solid var(--rc-line)', maxHeight: '60vh' }}>
                <table className="rcx-matrix" style={{ minWidth: 420 }}>
                  <thead>
                    <tr>
                      <th>Feature / Action</th>
                      {ROLE_COLUMNS.map((r) => (
                        <th key={r.id} className="rcx-rolehead">
                          <span className="rcx-rolecode" style={{ '--rc-chip': r.accent, background: r.accent }}>{r.label}</span>
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {modalHome.blocks.map((block) => (
                      <React.Fragment key={block.feature}>
                        <tr className="rcx-group">
                          <td colSpan={ROLE_COLUMNS.length + 1}>{block.title}</td>
                        </tr>
                        {block.actions.map(({ action, permission_id }) => (
                          <tr className="rcx-row" key={permission_id}>
                            <td className="rcx-feat"><span className="rcx-action-tag">{ACTION_META[action]?.label || action}</span></td>
                            {ROLE_COLUMNS.map((r) => {
                              const checked = isGranted(r.id, permission_id);
                              const isAdmin = r.id === ADMIN_ROLE_ID;
                              const isDirty = !isAdmin && pending[r.id] && permission_id in pending[r.id];
                              return (
                                <td key={r.id} className={`rcx-cell${isAdmin ? ' rcx-cell--admin' : ''}`}>
                                  <button
                                    type="button"
                                    className={`rcx-toggle${checked ? ' is-on' : ''}${isAdmin ? ' is-locked' : ''}${isDirty ? ' is-dirty' : ''}`}
                                    style={{ '--rc-chip': r.accent }}
                                    disabled={isAdmin}
                                    aria-pressed={checked}
                                    onClick={() => toggle(r.id, permission_id)}
                                    title={isAdmin ? 'System Administrator always has full access' : `${r.name}: ${ACTION_META[action]?.desc || action}`}
                                  >
                                    {(checked || isAdmin) && <CheckGlyph />}
                                  </button>
                                </td>
                              );
                            })}
                          </tr>
                        ))}
                      </React.Fragment>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
            <div className="rcx-modal-footer">
              <p className="rcx-modal-note" style={{ margin: '0 0 0.6rem' }}>Toggles set each role's <strong>default</strong> access for these permissions. The System Administrator column is always full and locked.</p>
              <div className="rcx-modal-actions">
                <button type="button" className="rcx-btn rcx-btn--ghost" onClick={() => setModalHome(null)} disabled={saving}>Close</button>
                <button
                  type="button"
                  className="rcx-btn rcx-btn--primary"
                  onClick={async () => { const ok = await handleSave(); if (ok) setModalHome(null); }}
                  disabled={!hasPendingChanges || saving}
                >
                  {saving ? 'Saving…' : 'Save Changes'}
                </button>
              </div>
            </div>
            </div>
          </Modal>
        )}
      </div>
  );
};

export default RolePermissions;
