import React, { useState, useEffect, useCallback } from 'react';
import AdminLayout from '../components/AdminLayout';
import Loader from '../components/Loader';
import { backupAPI } from '../services/api';
import { confirmAction } from '../utils/confirm';
import { toast } from '../utils/toast';
import '../styles/archive_settings.css';
import '../styles/backup.css';

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

function formatDateTime(value) {
  if (!value) return '—';
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return value;
  return d.toLocaleString('en-US', {
    month: '2-digit', day: '2-digit', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  });
}

function formatBytes(bytes) {
  if (bytes == null) return '—';
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

const STATUS_BADGE = {
  success: <span className="as-badge as-badge--green">Success</span>,
  failed: <span className="as-badge as-badge--red">Failed</span>,
};

const ACTION_LABEL = { backup: 'Backup', restore: 'Restore' };
const TRIGGER_LABEL = { manual: 'Manual', scheduled: 'Scheduled', pre_restore: 'Pre-restore' };

const BackupPage = () => {
  const [stats, setStats] = useState(null);
  const [scheduleForm, setScheduleForm] = useState(null);
  const [loading, setLoading] = useState(true);
  const [savingSchedule, setSavingSchedule] = useState(false);
  const [savedSchedule, setSavedSchedule] = useState(false);
  const [runningBackup, setRunningBackup] = useState(false);
  const [restoringId, setRestoringId] = useState(null);

  const [history, setHistory] = useState([]);
  const [historyPage, setHistoryPage] = useState(1);
  const [historyTotalPages, setHistoryTotalPages] = useState(1);
  const [historyLoading, setHistoryLoading] = useState(false);

  const loadSettings = useCallback(async () => {
    setLoading(true);
    try {
      const res = await backupAPI.getSettings();
      const data = res.data ?? res;
      setStats(data.stats ?? null);
      setScheduleForm({
        enabled: data.schedule?.enabled ?? false,
        time: data.schedule?.time ?? '00:01',
        frequency: data.schedule?.frequency ?? 'daily',
        storage_path: data.schedule?.storage_path ?? '',
      });
    } catch {
      // silent — page shows empty
    } finally {
      setLoading(false);
    }
  }, []);

  const loadHistory = useCallback(async (page = 1) => {
    setHistoryLoading(true);
    try {
      const res = await backupAPI.getHistory({ page, per_page: 20 });
      const data = res.data ?? [];
      setHistory(Array.isArray(data) ? data : []);
      setHistoryTotalPages(res.pagination?.last_page ?? 1);
    } catch {
      setHistory([]);
    } finally {
      setHistoryLoading(false);
    }
  }, []);

  useEffect(() => { loadSettings(); }, [loadSettings]);
  useEffect(() => { loadHistory(historyPage); }, [loadHistory, historyPage]);

  const handleSaveSchedule = async () => {
    setSavingSchedule(true);
    setSavedSchedule(false);
    try {
      await backupAPI.updateSettings({
        ...scheduleForm,
        storage_path: scheduleForm.storage_path?.trim() || null,
      });
      setSavedSchedule(true);
      setTimeout(() => setSavedSchedule(false), 3000);
      loadSettings();
    } catch {
      toast.error("Couldn't save schedule", 'Check the time format and try again.');
    } finally {
      setSavingSchedule(false);
    }
  };

  const handleRunNow = async () => {
    if (!(await confirmAction({
      title: 'Create a backup now?',
      text: 'This will dump the live database to a file on the server.',
      confirmText: 'Create backup',
      tone: 'primary',
    }))) return;

    setRunningBackup(true);
    try {
      await backupAPI.runNow();
      toast.success('Backup complete', 'The snapshot was saved successfully.');
      loadSettings();
      loadHistory(1);
      setHistoryPage(1);
    } catch (err) {
      toast.error("Backup failed", err.message || 'Check the server logs for details.');
    } finally {
      setRunningBackup(false);
    }
  };

  const handleDownload = (row) => {
    backupAPI.download(row.log_id, row.file_name || `backup_${row.log_id}.sql`);
  };

  const handleRestore = async (row) => {
    if (!(await confirmAction({
      title: 'Restore this backup?',
      text: 'This will overwrite the current live database with this snapshot. A fresh safety backup is taken automatically first.',
      confirmText: 'Continue',
      tone: 'danger',
    }))) return;
    if (!(await confirmAction({
      title: 'Are you sure?',
      text: 'This action replaces all current data with the selected snapshot.',
      confirmText: 'Restore now',
      tone: 'danger',
    }))) return;

    setRestoringId(row.log_id);
    try {
      await backupAPI.restore(row.log_id);
      toast.success('Database restored', 'The selected snapshot has been applied.');
      loadSettings();
      loadHistory(historyPage);
    } catch (err) {
      toast.error("Restore failed", err.message || 'Check the server logs for details.');
    } finally {
      setRestoringId(null);
    }
  };

  return (
    <AdminLayout>
      <div className="ak-page as-page">

        {/* ── Page Header ─────────────────────────────────────── */}
        <div className="as-page-header">
          <header className="ak-header">
            <div className="ak-header-row as-page-header-row">
              <div>
                <p className="ak-eyebrow">System · Backup</p>
                <h1 className="ak-title">Backup</h1>
                <p className="ak-sub">Back up the database on a schedule or on demand, and restore from any saved snapshot.</p>
              </div>
            </div>
          </header>
        </div>

        {/* ── Stat Cards ───────────────────────────────────────── */}
        <div className="bk-stats-row">
          <div className="bk-stat-card">
            <span className="bk-stat-label">Schedule</span>
            <span className="bk-stat-value">
              {stats?.schedule_enabled ? 'Enabled' : 'Disabled'}
            </span>
            <span className="bk-stat-sub">
              Next run: {stats?.next_scheduled_run ? formatDateTime(stats.next_scheduled_run) : '—'}
            </span>
          </div>
          <div className="bk-stat-card">
            <span className="bk-stat-label">Backups</span>
            <span className="bk-stat-value">{stats?.backups_total ?? 0}</span>
            <span className="bk-stat-sub">{stats?.backups_failed ?? 0} failed</span>
          </div>
          <div className="bk-stat-card">
            <span className="bk-stat-label">Restores</span>
            <span className="bk-stat-value">{stats?.restores_total ?? 0}</span>
            <span className="bk-stat-sub">{stats?.restores_failed ?? 0} failed</span>
          </div>
          <div className="bk-stat-card">
            <span className="bk-stat-label">Last success</span>
            <span className="bk-stat-value bk-stat-value--sm">{formatDateTime(stats?.last_success_at)}</span>
            <span className="bk-stat-sub">Server: {formatDateTime(stats?.server_time)}</span>
          </div>
        </div>

        <div className="bk-columns">
          {/* ── Backup Schedule ──────────────────────────────── */}
          <div className="as-section">
            <div className="as-section-header">
              <h2>Backup schedule</h2>
              <p>Store SQL snapshots on the server and run them on a schedule.</p>
            </div>
            <div className="as-section-body">
              {loading || !scheduleForm ? (
                <Loader label="Loading settings" />
              ) : (
                <>
                  <div className="as-field-row">
                    <span className="as-field-label">Enable scheduled backups</span>
                    <div className="as-toggle-wrap">
                      <Toggle
                        checked={!!scheduleForm.enabled}
                        onChange={v => setScheduleForm(p => ({ ...p, enabled: v }))}
                      />
                    </div>
                  </div>

                  <div className="as-field-row">
                    <label className="as-field-label">Schedule type</label>
                    <select
                      className="as-field-input"
                      value={scheduleForm.frequency}
                      onChange={e => setScheduleForm(p => ({ ...p, frequency: e.target.value }))}
                    >
                      <option value="daily">Daily</option>
                    </select>
                  </div>

                  <div className="as-field-row">
                    <label className="as-field-label">Backup time</label>
                    <input
                      type="time"
                      className="as-field-input"
                      value={scheduleForm.time}
                      onChange={e => setScheduleForm(p => ({ ...p, time: e.target.value }))}
                    />
                  </div>

                  <div className="as-field-row">
                    <label className="as-field-label">Backup storage path</label>
                    <input
                      type="text"
                      className="as-field-input"
                      placeholder="Leave blank for the default server folder"
                      value={scheduleForm.storage_path || ''}
                      onChange={e => setScheduleForm(p => ({ ...p, storage_path: e.target.value }))}
                    />
                  </div>
                  <p className="as-schedule-note">
                    Leave blank for the default server folder. Absolute paths are allowed.
                  </p>

                  <div className="bk-summary">
                    <div><span>Summary</span><strong>{scheduleForm.enabled ? 'Scheduled backups enabled' : 'Scheduled backups disabled'}</strong></div>
                    <div><span>Next scheduled run</span><strong>{stats?.next_scheduled_run ? formatDateTime(stats.next_scheduled_run) : '—'}</strong></div>
                    <div><span>Server timezone</span><strong>{stats?.server_timezone || '—'}</strong></div>
                    <div><span>Server storage</span><strong>{scheduleForm.storage_path || 'storage/app/backups'}</strong></div>
                  </div>

                  <div className="bk-schedule-actions">
                    <button type="button" className="as-btn-preview" onClick={loadSettings} disabled={savingSchedule}>
                      Reload
                    </button>
                    <button
                      type="button"
                      className="as-save-schedule-btn"
                      onClick={handleSaveSchedule}
                      disabled={savingSchedule}
                    >
                      {savedSchedule ? 'Schedule Saved' : savingSchedule ? 'Saving…' : 'Save schedule'}
                    </button>
                  </div>
                </>
              )}
            </div>
          </div>

          {/* ── Backup Safety ────────────────────────────────── */}
          <div className="as-section">
            <div className="as-section-header">
              <h2>Backup safety</h2>
              <p>How backups are protected on this system.</p>
            </div>
            <div className="as-section-body">
              <div className="bk-safety-item">
                <h3>Conflict protection</h3>
                <p>A server lock prevents overlapping backup and restore jobs.</p>
              </div>
              <div className="bk-safety-item">
                <h3>Server storage</h3>
                <p>Snapshots stay on the server and appear in history until you download or restore them.</p>
              </div>
              <div className="bk-safety-item">
                <h3>Restore tracking</h3>
                <p>Every restore attempt is logged for auditability, and a fresh safety backup is taken automatically before any restore.</p>
              </div>
              <button
                type="button"
                className="bk-run-now-btn"
                onClick={handleRunNow}
                disabled={runningBackup}
              >
                {runningBackup ? 'Creating backup…' : 'Create backup now'}
              </button>
            </div>
          </div>
        </div>

        {/* ── Backup History ───────────────────────────────── */}
        <div className="as-section">
          <div className="as-section-header">
            <h2>Backup history</h2>
            <p>Review backup and restore activity, download stored backups, and restore from a previous successful snapshot.</p>
          </div>
          <div className="as-section-body" style={{ padding: 0 }}>
            {historyLoading ? (
              <Loader label="Loading history" />
            ) : history.length === 0 ? (
              <div className="as-empty">No backups recorded yet.</div>
            ) : (
              <div className="as-log-table-wrap">
                <table className="as-log-table">
                  <thead>
                    <tr>
                      <th>Date / Time</th>
                      <th>Action</th>
                      <th>Trigger</th>
                      <th>Status</th>
                      <th>File name</th>
                      <th>Size</th>
                      <th>Details</th>
                      <th>Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    {history.map(row => (
                      <tr key={row.log_id}>
                        <td>{row.ran_at}</td>
                        <td>{ACTION_LABEL[row.action] ?? row.action}</td>
                        <td>{TRIGGER_LABEL[row.trigger] ?? row.trigger}</td>
                        <td>{STATUS_BADGE[row.status] ?? row.status}</td>
                        <td>{row.file_name ?? '—'}</td>
                        <td>{formatBytes(row.file_size_bytes)}</td>
                        <td>
                          {row.status === 'failed'
                            ? (row.error_message ?? 'Failed')
                            : row.action === 'restore'
                              ? `Restored from #${row.restored_from_log_id}`
                              : 'Stored on server'}
                        </td>
                        <td className="bk-actions-cell">
                          {row.status === 'success' && row.action === 'backup' && row.file_name && (
                            <>
                              <button type="button" className="bk-link-btn" onClick={() => handleDownload(row)}>
                                Download
                              </button>
                              <button
                                type="button"
                                className="bk-link-btn"
                                onClick={() => handleRestore(row)}
                                disabled={restoringId === row.log_id}
                              >
                                {restoringId === row.log_id ? 'Restoring…' : 'Restore'}
                              </button>
                            </>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>

                {historyTotalPages > 1 && (
                  <div className="as-pagination">
                    <button
                      type="button"
                      className="as-page-btn"
                      onClick={() => setHistoryPage(p => Math.max(1, p - 1))}
                      disabled={historyPage === 1}
                    >
                      Previous
                    </button>
                    <span className="as-page-info">Page {historyPage} of {historyTotalPages}</span>
                    <button
                      type="button"
                      className="as-page-btn"
                      onClick={() => setHistoryPage(p => Math.min(historyTotalPages, p + 1))}
                      disabled={historyPage === historyTotalPages}
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
    </AdminLayout>
  );
};

export default BackupPage;
