import React, { useState } from 'react';
import { ROLE_COLUMNS, ACTIONS, ACTION_META } from '../utils/permissions';

/**
 * Collapsible legend explaining the role codes (SA/IA/BM/WP/AU) and the five
 * actions used across the RBAC admin pages. Pass `showOverrideKey` on the User
 * Overrides page to also explain the cell symbols and click behaviour.
 */
const PermissionGuide = ({ showOverrideKey = false }) => {
  const [open, setOpen] = useState(false);

  return (
    <div className="perm-guide">
      <button type="button" className="perm-guide-toggle" onClick={() => setOpen((o) => !o)}>
        <span className="perm-guide-caret">{open ? '▾' : '▸'}</span>
        Legend &amp; guide — what the role codes and actions mean
      </button>

      {open && (
        <div className="perm-guide-body">
          <div className="perm-guide-section">
            <h4>Roles</h4>
            <ul className="perm-guide-roles">
              {ROLE_COLUMNS.map((r) => (
                <li key={r.id}>
                  <span className="perm-role-chip" style={{ backgroundColor: r.accent }}>{r.label}</span>
                  <span className="perm-role-text">
                    <strong>{r.name}</strong>
                    <span className="perm-role-blurb">{r.blurb}</span>
                  </span>
                </li>
              ))}
            </ul>
          </div>

          <div className="perm-guide-section">
            <h4>Actions</h4>
            <ul className="perm-guide-actions">
              {ACTIONS.map((a) => (
                <li key={a}>
                  <strong>{ACTION_META[a].label}</strong> — {ACTION_META[a].desc}
                </li>
              ))}
            </ul>
          </div>

          {showOverrideKey && (
            <div className="perm-guide-section">
              <h4>How to read this page</h4>
              <ul className="perm-guide-key">
                <li><span className="perm-key-box">✓</span> allowed &nbsp;·&nbsp; <span className="perm-key-box">✗</span> not allowed (the role default).</li>
                <li><span className="perm-key-box perm-override-on">✓∗</span> a green starred cell is an <strong>override that grants</strong> — it beats the role default for this user.</li>
                <li><span className="perm-key-box perm-override-off">✗∗</span> a red starred cell is an <strong>override that denies</strong>.</li>
                <li>Click a cell to cycle: <em>role default → grant → deny → back to default</em>. A reason is required before saving.</li>
              </ul>
            </div>
          )}
        </div>
      )}
    </div>
  );
};

export default PermissionGuide;
