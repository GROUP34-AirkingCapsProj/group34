import React, { useState, useEffect, useCallback } from 'react';
import { Link } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import { archiveSettingsAPI } from '../services/api';
import { confirmAction } from '../utils/confirm';
import { toast } from '../utils/toast';
import '../styles/archive_settings.css';

const MODULE_LABELS = {
  purchase_orders:   'Purchase Orders',
  purchase_requests: 'Purchase Requests',
  transfers:         'Transfers',
  adjustments:       'Adjustments',
  issuances:         'Issuances',
  products:          'Products',
  customers:         'Customers',
  suppliers:         'Suppliers',
  users:             'Users',
  locations:         'Locations',
};

const NO_AUTO_MODULES = new Set(['products', 'customers', 'users', 'locations']);

const MODULE_ORDER = [
  'purchase_orders', 'purchase_requests', 'transfers',
  'adjustments', 'issuances', 'products', 'customers',
  'suppliers', 'users', 'locations',
];

// ── Small Components ──────────────────────────────────────────────

function Toggle({ checked, onChange, disabled }) {
  return (
    <label className="as-toggle">
      <input
        type="checkbox"
        checked={checked}
        onChange={e => onChange(e.target.checked)}
        disabled={disabled}
      />
      <span className="as-toggle-slider" />
    </label>
  );
}

function ResultModal({ title, isDryRun, results, consoleOutput, totalArchived, totalDeleted, durationMs, onClose }) {
  const modules = results ? Object.entries(results) : [];
  return (
    <div className="as-modal-overlay" onClick={onClose}>
      <div className="as-modal" onClick={e => e.stopPropagation()}>
        <div className="as-modal-header">
          <h3>{title}</h3>
          <button type="button" className="as-modal-close" onClick={onClose} aria-label="Close">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="18" height="18">
              <line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" />
            </svg>
          </button>
        </div>
        <div className="as-modal-body">
          <div className={`as-modal-banner ${isDryRun ? 'as-modal-banner--info' : 'as-modal-banner--success'}`}>
            {isDryRun ? 'No changes were made — this was a preview only.' : 'Archive operation completed successfully.'}
          </div>
          {modules.length > 0 && (
            <table className="as-modal-table">
              <thead>
                <tr>
                  <th>Module</th>
                  <th>{isDryRun ? 'Would Archive' : 'Archived'}</th>
                  <th>Cutoff Date</th>
                  <th>Statuses</th>
                  <th>Reason</th>
                </tr>
              </thead>
              <tbody>
                {modules.map(([mod, data]) => (
                  <tr key={mod}>
                    <td>{MODULE_LABELS[mod] ?? mod}</td>
                    <td>{data.would_archive ?? data.archived ?? 0}</td>
                    <td>{data.cutoff_date ?? '—'}</td>
                    <td>{data.statuses ? data.statuses.join(', ') || 'Any' : '—'}</td>
                    <td>{data.reason ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
          {modules.length === 0 && (
            <p className="as-log-no-data">No module data returned.</p>
          )}
        </div>
        <div className="as-modal-footer">
          <span className="as-modal-total">
            Total: {totalArchived ?? 0} records {isDryRun ? 'would be archived' : 'archived'}
            {totalDeleted ? ` / ${totalDeleted} deleted` : ''}
            {durationMs ? ` — ${durationMs}ms` : ''}
          </span>
          <button type="button" className="as-modal-close-btn" onClick={onClose}>Close</button>
        </div>
      </div>
    </div>
  );
}

// ── Module Row ────────────────────────────────────────────────────

function ModuleRow({ moduleKey, config: initialConfig, onSaved }) {
  const [cfg, setCfg]         = useState({ ...initialConfig });
  const [saving, setSaving]   = useState(false);
  const [saved, setSaved]     = useState(false);
  const [foreverRet, setForeverRet] = useState(initialConfig.retention_days == null);

  const disabled = NO_AUTO_MODULES.has(moduleKey);

  const update = (field, value) => setCfg(prev => ({ ...prev, [field]: value }));

  const handleSave = async () => {
    setSaving(true);
    setSaved(false);
    try {
      const payload = {
        auto_archive_enabled: cfg.auto_archive_enabled,
        archive_after_days:   cfg.auto_archive_enabled ? (cfg.archive_after_days || null) : null,
        retention_days:       foreverRet ? null : (cfg.retention_days || null),
        require_reason:       cfg.require_reason,
        pinnable:             cfg.pinnable,
      };
      await archiveSettingsAPI.updateModule(moduleKey, payload);
      setSaved(true);
      if (onSaved) onSaved(moduleKey, payload);
      setTimeout(() => setSaved(false), 3000);
    } catch {
      alert('Failed to save settings for ' + (MODULE_LABELS[moduleKey] ?? moduleKey));
    } finally {
      setSaving(false);
    }
  };

  return (
    <tr>
      <td><span className="as-module-name">{MODULE_LABELS[moduleKey] ?? moduleKey}</span></td>

      {/* Auto-Archive toggle */}
      <td>
        <div className="as-toggle-wrap">
          <Toggle
            checked={!!cfg.auto_archive_enabled}
            onChange={v => update('auto_archive_enabled', v)}
            disabled={disabled}
          />
          {disabled && (
            <span className="as-toggle-tooltip">Auto-archive disabled — archive manually from module page.</span>
          )}
        </div>
      </td>

      {/* Archive After */}
      <td>
        {cfg.auto_archive_enabled && !disabled ? (
          <div className="as-days-input-wrap">
            <input
              type="number"
              min="1"
              max="3650"
              className="as-days-input"
              value={cfg.archive_after_days ?? ''}
              onChange={e => update('archive_after_days', e.target.value ? parseInt(e.target.value, 10) : null)}
              placeholder="days"
            />
            <span className="as-days-label">days</span>
          </div>
        ) : (
          <span className="as-manual-only">Manual only</span>
        )}
      </td>

      {/* Retention Period */}
      <td>
        <div className="as-forever-wrap">
          <label className="as-forever-check">
            <input
              type="checkbox"
              checked={foreverRet}
              onChange={e => {
                setForeverRet(e.target.checked);
                if (e.target.checked) update('retention_days', null);
              }}
            />
            Keep forever
          </label>
          {!foreverRet && (
            <div className="as-days-input-wrap">
              <input
                type="number"
                min="1"
                max="3650"
                className="as-days-input"
                value={cfg.retention_days ?? ''}
                onChange={e => update('retention_days', e.target.value ? parseInt(e.target.value, 10) : null)}
                placeholder="days"
              />
              <span className="as-days-label">days in archive</span>
            </div>
          )}
        </div>
      </td>

      {/* Require Reason */}
      <td>
        <input
          type="checkbox"
          checked={!!cfg.require_reason}
          onChange={e => update('require_reason', e.target.checked)}
          style={{ width: 15, height: 15, cursor: 'pointer', accentColor: '#3b82f6' }}
        />
      </td>

      {/* Status badge */}
      <td>
        {cfg.auto_archive_enabled && !disabled
          ? <span className="as-badge as-badge--blue">Auto</span>
          : <span className="as-badge as-badge--gray">Manual</span>
        }
      </td>

      {/* Actions */}
      <td>
        {saved ? (
          <span className="as-saved-tick">Saved</span>
        ) : (
          <button
            type="button"
            className={`as-save-row-btn${saving ? ' as-save-row-btn--saving' : ''}`}
            onClick={handleSave}
            disabled={saving}
          >
            {saving ? 'Saving…' : 'Save'}
          </button>
        )}
      </td>
    </tr>
  );
}

// ── Main Page ─────────────────────────────────────────────────────

const ArchiveSettingsPage = () => {
  const [settings, setSettings]         = useState(null);
  const [loading, setLoading]           = useState(true);
  const [scheduleForm, setScheduleForm] = useState(null);
  const [savingSched, setSavingSched]   = useState(false);
  const [savedSched, setSavedSched]     = useState(false);

  const [modal, setModal]     = useState(null); // { title, isDryRun, results, ... }
  const [running, setRunning] = useState('');   // 'preview'|'run'|'cleanup-preview'|'cleanup'

  const [logs, setLogs]           = useState([]);
  const [logsPage, setLogsPage]   = useState(1);
  const [logsTotalPages, setLogsTotalPages] = useState(1);
  const [logsLoading, setLogsLoading]       = useState(false);
  const [expandedLog, setExpandedLog]       = useState(null);

  const loadSettings = useCallback(async () => {
    setLoading(true);
    try {
      const res = await archiveSettingsAPI.get();
      setSettings(res.data ?? res);
      const sched = res.data?.archive_schedule ?? res.archive_schedule ?? {};
      setScheduleForm({
        auto_archive_enabled:   sched.auto_archive_enabled   ?? true,
        auto_archive_time:      sched.auto_archive_time       ?? '02:00',
        auto_archive_frequency: sched.auto_archive_frequency  ?? 'daily',
        retention_enabled:      sched.retention_enabled        ?? true,
        retention_frequency:    sched.retention_frequency      ?? 'weekly',
        retention_time:         sched.retention_time            ?? '03:00',
      });
    } catch {
      // silent — page shows empty
    } finally {
      setLoading(false);
    }
  }, []);

  const loadLogs = useCallback(async (page = 1) => {
    setLogsLoading(true);
    try {
      const res = await archiveSettingsAPI.getLogs({ page, per_page: 20 });
      const data = res.data ?? [];
      setLogs(Array.isArray(data) ? data : []);
      setLogsTotalPages(res.pagination?.last_page ?? 1);
    } catch {
      setLogs([]);
    } finally {
      setLogsLoading(false);
    }
  }, []);

  useEffect(() => { loadSettings(); }, [loadSettings]);
  useEffect(() => { loadLogs(logsPage); }, [loadLogs, logsPage]);

  const handleSaveSchedule = async () => {
    setSavingSched(true);
    setSavedSched(false);
    try {
      await archiveSettingsAPI.updateSchedule(scheduleForm);
      setSavedSched(true);
      setTimeout(() => setSavedSched(false), 3000);
    } catch {
      alert('Failed to save schedule settings.');
    } finally {
      setSavingSched(false);
    }
  };

  const handlePreview = async () => {
    setRunning('preview');
    try {
      const res = await archiveSettingsAPI.preview();
      const d = res.data ?? res;
      setModal({
        title:          'Preview Results',
        isDryRun:       true,
        results:        d.preview_results ?? {},
        totalArchived:  0,
        totalDeleted:   0,
        durationMs:     null,
      });
      loadLogs(logsPage);
    } catch {
      alert('Preview failed. Check console for details.');
    } finally {
      setRunning('');
    }
  };

  const handleRunNow = async () => {
    if (!(await confirmAction({ title: 'Archive records now?', text: 'This will archive records based on current settings.', confirmText: 'Archive', tone: 'danger' }))) return;
    setRunning('run');
    try {
      const res = await archiveSettingsAPI.runNow();
      const d = res.data ?? res;
      setModal({
        title:         'Auto-Archive Complete',
        isDryRun:      false,
        results:       d.modules ?? {},
        totalArchived: d.total_archived ?? 0,
        totalDeleted:  0,
        durationMs:    null,
      });
      loadSettings();
      loadLogs(logsPage);
    } catch {
      toast.error("Couldn't run auto-archive", 'Auto-archive run failed.');
    } finally {
      setRunning('');
    }
  };

  const handleCleanupPreview = async () => {
    setRunning('cleanup-preview');
    try {
      const res = await archiveSettingsAPI.preview();
      const d = res.data ?? res;
      setModal({
        title:         'Cleanup Preview',
        isDryRun:      true,
        results:       d.preview_results ?? {},
        totalArchived: 0,
        totalDeleted:  0,
        durationMs:    null,
      });
      loadLogs(logsPage);
    } catch {
      alert('Cleanup preview failed.');
    } finally {
      setRunning('');
    }
  };

  const handleRunCleanup = async () => {
    if (!(await confirmAction({ title: 'Permanently delete expired records?', text: 'This will permanently delete archived records past their retention period.', confirmText: 'Continue', tone: 'danger' }))) return;
    if (!(await confirmAction({ title: 'Are you sure?', text: 'This action is irreversible.', confirmText: 'Delete permanently', tone: 'danger' }))) return;
    setRunning('cleanup');
    try {
      const res = await archiveSettingsAPI.runCleanup();
      const d = res.data ?? res;
      setModal({
        title:         'Retention Cleanup Complete',
        isDryRun:      false,
        results:       d.modules ?? {},
        totalArchived: 0,
        totalDeleted:  d.total_deleted ?? 0,
        durationMs:    null,
      });
      loadLogs(logsPage);
    } catch {
      toast.error("Couldn't run cleanup", 'Cleanup run failed.');
    } finally {
      setRunning('');
    }
  };

  const archiveConfig = settings?.archive_config ?? {};
  const lastRun       = settings?.archive_last_run ?? null;

  const runTypeBadge = (type) => {
    if (type === 'auto_archive')      return <span className="as-badge as-badge--blue">Auto Archive</span>;
    if (type === 'retention_cleanup') return <span className="as-badge as-badge--orange">Retention Cleanup</span>;
    return <span className="as-badge as-badge--gray">Preview</span>;
  };

  const dryRunBadge = (isDryRun) => isDryRun
    ? <span className="as-badge as-badge--gray">Dry Run</span>
    : <span className="as-badge as-badge--green">Live</span>;

  return (
    <AdminLayout>
      <div className="ak-page as-page">

        {/* ── Page Header ─────────────────────────────────────── */}
        <div className="as-page-header">
          <header className="ak-header">
            <div className="ak-header-row as-page-header-row">
              <div>
                <p className="ak-eyebrow">System · Archive</p>
                <h1 className="ak-title">Archive Settings</h1>
                <p className="ak-sub">Configure automatic archiving rules, retention policies, and schedule for each module.</p>
              </div>
            </div>
          </header>

          {/* Page-level navigation tabs */}
          <div className="am-page-tabs">
            <Link to="/admin/archive" className="am-page-tab">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14">
                <polyline points="21 8 21 21 3 21 3 8" /><rect x="1" y="3" width="22" height="5" />
                <line x1="10" y1="12" x2="14" y2="12" />
              </svg>
              Archive Records
            </Link>
            <Link to="/admin/archive-settings" className="am-page-tab am-page-tab--active">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14">
                <circle cx="12" cy="12" r="3" />
                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z" />
              </svg>
              Archive Settings
            </Link>
          </div>

          {lastRun && (
            <div className="as-last-run-bar">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14">
                <circle cx="12" cy="12" r="10" /><polyline points="12 6 12 12 16 14" />
              </svg>
              Last auto-archive ran on{' '}
              <strong>
                {new Date(lastRun.ran_at).toLocaleString('en-US', {
                  month: '2-digit', day: '2-digit', year: 'numeric',
                  hour: '2-digit', minute: '2-digit',
                })}
              </strong>
              &nbsp;— {lastRun.total_archived} record{lastRun.total_archived !== 1 ? 's' : ''} archived&nbsp;
              {lastRun.is_dry_run
                ? <span className="as-badge as-badge--gray">Dry Run</span>
                : <span className="as-badge as-badge--green">Live</span>
              }
            </div>
          )}
        </div>

        {/* ── Section 2: Module Configuration Table ───────────── */}
        <div className="as-section">
          <div className="as-section-header">
            <h2>Module Archive Rules</h2>
            <p>Configure how each module is automatically archived. Each row saves independently.</p>
          </div>
          <div className="as-section-body" style={{ padding: 0 }}>
            {loading ? (
              <div className="as-empty">Loading settings…</div>
            ) : (
              <div className="as-module-table-wrap">
                <table className="as-module-table">
                  <thead>
                    <tr>
                      <th>Module</th>
                      <th>Auto-Archive</th>
                      <th>Archive After</th>
                      <th>Retention Period</th>
                      <th>Require Reason</th>
                      <th>Status</th>
                      <th>Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    {MODULE_ORDER.filter(k => archiveConfig[k]).map(k => (
                      <ModuleRow
                        key={k}
                        moduleKey={k}
                        config={archiveConfig[k]}
                        onSaved={() => {}}
                      />
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </div>

        {/* ── Section 3: Schedule Settings ────────────────────── */}
        <div className="as-section">
          <div className="as-section-header">
            <h2>Auto-Archive Schedule</h2>
            <p>When the automatic archive command runs on the server.</p>
          </div>
          <div className="as-section-body">
            {scheduleForm && (
              <>
                <div className="as-schedule-grid">
                  <div className="as-schedule-card">
                    <h3>Auto-Archive</h3>
                    <div className="as-field-row">
                      <span className="as-field-label">Enabled</span>
                      <div className="as-toggle-wrap">
                        <Toggle
                          checked={!!scheduleForm.auto_archive_enabled}
                          onChange={v => setScheduleForm(p => ({ ...p, auto_archive_enabled: v }))}
                        />
                      </div>
                    </div>
                    <div className="as-field-row">
                      <label className="as-field-label">Run Time</label>
                      <input
                        type="time"
                        className="as-field-input"
                        value={scheduleForm.auto_archive_time}
                        onChange={e => setScheduleForm(p => ({ ...p, auto_archive_time: e.target.value }))}
                      />
                    </div>
                    <div className="as-field-row">
                      <label className="as-field-label">Frequency</label>
                      <select
                        className="as-field-input"
                        value={scheduleForm.auto_archive_frequency}
                        onChange={e => setScheduleForm(p => ({ ...p, auto_archive_frequency: e.target.value }))}
                      >
                        <option value="daily">Daily</option>
                        <option value="weekly">Weekly</option>
                        <option value="monthly">Monthly</option>
                      </select>
                    </div>
                  </div>

                  <div className="as-schedule-card">
                    <h3>Retention Cleanup</h3>
                    <div className="as-field-row">
                      <span className="as-field-label">Enabled</span>
                      <div className="as-toggle-wrap">
                        <Toggle
                          checked={!!scheduleForm.retention_enabled}
                          onChange={v => setScheduleForm(p => ({ ...p, retention_enabled: v }))}
                        />
                      </div>
                    </div>
                    <div className="as-field-row">
                      <label className="as-field-label">Run Time</label>
                      <input
                        type="time"
                        className="as-field-input"
                        value={scheduleForm.retention_time}
                        onChange={e => setScheduleForm(p => ({ ...p, retention_time: e.target.value }))}
                      />
                    </div>
                    <div className="as-field-row">
                      <label className="as-field-label">Frequency</label>
                      <select
                        className="as-field-input"
                        value={scheduleForm.retention_frequency}
                        onChange={e => setScheduleForm(p => ({ ...p, retention_frequency: e.target.value }))}
                      >
                        <option value="daily">Daily</option>
                        <option value="weekly">Weekly</option>
                        <option value="monthly">Monthly</option>
                      </select>
                    </div>
                  </div>
                </div>

                <p className="as-schedule-note">
                  Frequency and time changes are saved to the database. The actual scheduled command in
                  app/Console/Kernel.php uses these stored values. Changes take effect on the next cron cycle.
                </p>

                <button
                  type="button"
                  className="as-save-schedule-btn"
                  onClick={handleSaveSchedule}
                  disabled={savingSched}
                >
                  {savedSched ? 'Schedule Saved' : savingSched ? 'Saving…' : 'Save Schedule'}
                </button>
              </>
            )}
          </div>
        </div>

        {/* ── Section 4: Manual Run Controls ──────────────────── */}
        <div className="as-section">
          <div className="as-section-header">
            <h2>Manual Run</h2>
            <p>Run the archive or cleanup process immediately.</p>
          </div>
          <div className="as-section-body">
            <div className="as-run-grid">
              <div className="as-run-card">
                <h3>Auto-Archive</h3>
                <p>Archive qualifying records now based on the current module rules above.</p>
                <div className="as-run-buttons">
                  <button
                    type="button"
                    className="as-btn-preview"
                    onClick={handlePreview}
                    disabled={!!running}
                  >
                    {running === 'preview' ? 'Running preview…' : 'Preview First'}
                  </button>
                  <button
                    type="button"
                    className="as-btn-run"
                    onClick={handleRunNow}
                    disabled={!!running}
                  >
                    {running === 'run' ? 'Archiving…' : 'Run Auto-Archive Now'}
                  </button>
                </div>
              </div>

              <div className="as-run-card">
                <h3>Retention Cleanup</h3>
                <p>Permanently delete archived records that have exceeded their retention period. This cannot be undone.</p>
                <div className="as-run-buttons">
                  <button
                    type="button"
                    className="as-btn-preview"
                    onClick={handleCleanupPreview}
                    disabled={!!running}
                  >
                    {running === 'cleanup-preview' ? 'Running preview…' : 'Preview Cleanup'}
                  </button>
                  <button
                    type="button"
                    className="as-btn-run-danger"
                    onClick={handleRunCleanup}
                    disabled={!!running}
                  >
                    {running === 'cleanup' ? 'Running cleanup…' : 'Run Cleanup Now'}
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>

        {/* ── Section 5: Run History Log ───────────────────────── */}
        <div className="as-section">
          <div className="as-section-header">
            <h2>Archive Run History</h2>
            <p>Log of all auto-archive and retention cleanup operations.</p>
          </div>
          <div className="as-section-body" style={{ padding: 0 }}>
            {logsLoading ? (
              <div className="as-empty">Loading history…</div>
            ) : logs.length === 0 ? (
              <div className="as-empty">No archive runs recorded yet.</div>
            ) : (
              <div className="as-log-table-wrap">
                <table className="as-log-table">
                  <thead>
                    <tr>
                      <th>#</th>
                      <th>Run Type</th>
                      <th>Triggered By</th>
                      <th>Date</th>
                      <th>Archived</th>
                      <th>Deleted</th>
                      <th>Duration</th>
                      <th>Type</th>
                    </tr>
                  </thead>
                  <tbody>
                    {logs.map((log, idx) => {
                      const isExpanded = expandedLog === log.log_id;
                      const modules    = log.modules ?? {};
                      const modEntries = Object.entries(modules);
                      return (
                        <React.Fragment key={log.log_id}>
                          <tr
                            className={isExpanded ? 'as-log-row--expanded' : ''}
                            onClick={() => setExpandedLog(isExpanded ? null : log.log_id)}
                          >
                            <td>{(logsPage - 1) * 20 + idx + 1}</td>
                            <td>{runTypeBadge(log.run_type)}</td>
                            <td>{log.triggered_by_name}</td>
                            <td>{log.ran_at}</td>
                            <td>{log.total_archived}</td>
                            <td>{log.total_deleted}</td>
                            <td>{log.duration_ms != null ? `${log.duration_ms}ms` : '—'}</td>
                            <td>{dryRunBadge(log.is_dry_run)}</td>
                          </tr>
                          {isExpanded && (
                            <tr className="as-log-expand-row">
                              <td colSpan={8}>
                                <div className="as-log-detail-inner">
                                  <h4>Module Breakdown</h4>
                                  {modEntries.length === 0 ? (
                                    <p className="as-log-no-data">No module data recorded.</p>
                                  ) : (
                                    <table className="as-log-detail-table">
                                      <thead>
                                        <tr>
                                          <th>Module</th>
                                          <th>Archived / Would Archive</th>
                                          <th>Deleted / Would Delete</th>
                                          <th>Skipped</th>
                                          <th>Reason</th>
                                        </tr>
                                      </thead>
                                      <tbody>
                                        {modEntries.map(([mod, data]) => (
                                          <tr key={mod}>
                                            <td>{MODULE_LABELS[mod] ?? mod}</td>
                                            <td>{data.archived ?? data.would_archive ?? '—'}</td>
                                            <td>{data.deleted ?? data.would_delete ?? '—'}</td>
                                            <td>{data.skipped ?? '—'}</td>
                                            <td>{data.reason ?? '—'}</td>
                                          </tr>
                                        ))}
                                      </tbody>
                                    </table>
                                  )}
                                </div>
                              </td>
                            </tr>
                          )}
                        </React.Fragment>
                      );
                    })}
                  </tbody>
                </table>

                {logsTotalPages > 1 && (
                  <div className="as-pagination">
                    <button
                      type="button"
                      className="as-page-btn"
                      onClick={() => setLogsPage(p => Math.max(1, p - 1))}
                      disabled={logsPage === 1}
                    >
                      Previous
                    </button>
                    <span className="as-page-info">Page {logsPage} of {logsTotalPages}</span>
                    <button
                      type="button"
                      className="as-page-btn"
                      onClick={() => setLogsPage(p => Math.min(logsTotalPages, p + 1))}
                      disabled={logsPage === logsTotalPages}
                    >
                      Next
                    </button>
                  </div>
                )}
              </div>
            )}
          </div>
        </div>

      </div>

      {/* ── Result Modal ─────────────────────────────────────── */}
      {modal && (
        <ResultModal
          {...modal}
          onClose={() => setModal(null)}
        />
      )}
    </AdminLayout>
  );
};

export default ArchiveSettingsPage;
