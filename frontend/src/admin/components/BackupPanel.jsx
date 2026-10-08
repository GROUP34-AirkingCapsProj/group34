import React, { useCallback, useEffect, useState } from 'react';
import { backupAPI } from '../services/api';
import { toast } from '../utils/toast';
import { confirmAction } from '../utils/confirm';
import '../styles/backup_panel.css';

const formatBytes = (n) => {
  const bytes = Number(n) || 0;
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
};

const formatWhen = (value) => {
  if (!value) return '—';
  try {
    return new Date(value.includes(' ') ? value.replace(' ', 'T') : value).toLocaleString('en-US', {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
    });
  } catch {
    return value;
  }
};

const toInputTime = (hhmm) => {
  if (!hhmm || !/^\d{2}:\d{2}$/.test(hhmm)) return '00:01';
  return hhmm;
};

const StatusBadge = ({ status }) => (
  <span className={`bp-badge bp-badge--${String(status || '').toLowerCase()}`}>
    {String(status || '—').toUpperCase()}
  </span>
);

const BackupPanel = () => {
  const [loading, setLoading] = useState(true);
  const [environment, setEnvironment] = useState(null);
  const [backupTab, setBackupTab] = useState(null);
  const [loadError, setLoadError] = useState(null);
  const [saving, setSaving] = useState(false);
  const [running, setRunning] = useState(false);
  const [historyLoading, setHistoryLoading] = useState(false);
  const [busyId, setBusyId] = useState(null);

  const [enabled, setEnabled] = useState(false);
  const [time, setTime] = useState('00:01');
  const [frequency, setFrequency] = useState('daily');
  const [storagePath, setStoragePath] = useState('');
  const [keepDailyDays, setKeepDailyDays] = useState(14);
  const [keepWeeklyWeeks, setKeepWeeklyWeeks] = useState(12);
  const [keepMonthlyMonths, setKeepMonthlyMonths] = useState(12);

  const [stats, setStats] = useState({});
  const [database, setDatabase] = useState({});
  const [remote, setRemote] = useState({});
  const [history, setHistory] = useState([]);

  const loadAll = useCallback(async () => {
    setLoading(true);
    setLoadError(null);
    try {
      const [settingsRes, historyRes] = await Promise.all([
        backupAPI.getSettings(),
        backupAPI.getHistory({ per_page: 20 }),
      ]);
      const payload = settingsRes?.data || settingsRes || {};
      const currentEnvironment = payload.environment;
      setEnvironment(currentEnvironment === 'live' || currentEnvironment === 'local' ? currentEnvironment : null);
      setBackupTab((current) => current || currentEnvironment || 'live');
      const schedule = payload.schedule || {};
      setEnabled(Boolean(schedule.enabled));
      setTime(toInputTime(schedule.time));
      setFrequency(schedule.frequency || 'daily');
      setStoragePath(schedule.storage_path || '');
      setKeepDailyDays(schedule.keep_daily_days ?? 14);
      setKeepWeeklyWeeks(schedule.keep_weekly_weeks ?? 12);
      setKeepMonthlyMonths(schedule.keep_monthly_months ?? 12);
      setStats(payload.stats || {});
      setDatabase(payload.database || {});
      setRemote(payload.remote || {});
      setHistory(Array.isArray(historyRes?.data) ? historyRes.data : []);
    } catch (err) {
      setLoadError(err.message || 'Failed to load backup settings');
      toast.error(err.message || 'Failed to load backup settings');
    } finally {
      setLoading(false);
    }
  }, []);

  const reloadHistory = useCallback(async () => {
    setHistoryLoading(true);
    try {
      const historyRes = await backupAPI.getHistory({ per_page: 20 });
      setHistory(Array.isArray(historyRes?.data) ? historyRes.data : []);
      const settingsRes = await backupAPI.getSettings();
      const payload = settingsRes?.data || settingsRes || {};
      setStats(payload.stats || {});
      setDatabase(payload.database || {});
      setRemote(payload.remote || {});
    } catch (err) {
      toast.error(err.message || 'Failed to refresh history');
    } finally {
      setHistoryLoading(false);
    }
  }, []);

  useEffect(() => {
    loadAll();
  }, [loadAll]);

  const handleSaveSchedule = async () => {
    setSaving(true);
    try {
      await backupAPI.updateSettings({
        enabled,
        time,
        frequency: 'daily',
        storage_path: storagePath.trim() || null,
        keep_daily_days: Number(keepDailyDays) || 14,
        keep_weekly_weeks: Number(keepWeeklyWeeks) || 12,
        keep_monthly_months: Number(keepMonthlyMonths) || 12,
      });
      toast.success('Backup schedule saved');
      await loadAll();
    } catch (err) {
      toast.error(err.message || 'Could not save schedule');
    } finally {
      setSaving(false);
    }
  };

  const handleRunNow = async () => {
    const ok = await confirmAction({
      title: 'Create backup now?',
      text: 'This will create a full SQL snapshot of the live database. Continue?',
      confirmText: 'Create backup',
      tone: 'primary',
    });
    if (!ok) return;

    setRunning(true);
    try {
      await backupAPI.runNow();
      toast.success('Backup completed successfully');
      await reloadHistory();
    } catch (err) {
      toast.error(err.message || 'Backup failed');
    } finally {
      setRunning(false);
    }
  };

  const handleDownload = async (row) => {
    setBusyId(row.log_id);
    try {
      await backupAPI.download(row.log_id, row.file_name || `backup_${row.log_id}.sql`);
      toast.success('Download started');
    } catch (err) {
      toast.error(err.message || 'Download failed');
    } finally {
      setBusyId(null);
    }
  };

  const handleRestore = async (row) => {
    const ok = await confirmAction({
      title: 'Restore this backup?',
      text:
        `This will overwrite the current database with ${row.file_name || 'this snapshot'}. `
        + 'All newer data will be lost. Continue only if you are sure.',
      confirmText: 'Restore database',
      tone: 'danger',
    });
    if (!ok) return;

    setBusyId(row.log_id);
    try {
      await backupAPI.restore(row.log_id);
      toast.success('Database restored successfully');
      await reloadHistory();
    } catch (err) {
      toast.error(err.message || 'Restore failed');
    } finally {
      setBusyId(null);
    }
  };

  if (loading) {
    return <div className="bp-loading">Loading backup settings…</div>;
  }

  const displayPath = storagePath.trim() || database.storage_path || 'storage/app/backups';

  return (
    <div className="bp-root">
      <div className="sc-tabs-bar" role="tablist" aria-label="Backup environment">
        {['live', 'local'].map((target) => (
          <button key={target} type="button" role="tab" id={`backup-tab-${target}`}
            aria-selected={backupTab === target} aria-controls="backup-environment-panel"
            className={`sc-tab ${backupTab === target ? 'active' : ''}`}
            onClick={() => setBackupTab(target)}>
            {target === 'live' ? 'Live Backup' : 'Local Backup'}
          </button>
        ))}
      </div>
      <div id="backup-environment-panel" role="tabpanel" aria-labelledby={`backup-tab-${backupTab}`}>
      {loadError ? (
        <div className="bp-card" role="alert">
          <p>{loadError}</p>
          <button type="button" className="sc-btn-save" onClick={loadAll}>Retry</button>
        </div>
      ) : backupTab !== environment ? (
        <div className="bp-card">
          <h3>{backupTab === 'live' ? 'Live Backup' : 'Local Backup'}</h3>
          <p>{!environment ? 'The server has not identified its backup environment. Update the backend before using backup controls.'
            : backupTab === 'live'
              ? 'Open System Configuration ? Backup on the live website to manage production backups.'
              : 'Open System Configuration ? Backup in your local XAMPP application to manage local backups.'}</p>
        </div>
      ) : (
      <>
      <p>Managing {environment === 'live' ? 'live production' : 'local'} database backups: <strong>{database.name || '?'}</strong></p>
      <div className="bp-stats">
        <div className="bp-stat">
          <span className="bp-stat-label">Schedule</span>
          <strong className="bp-stat-value">{stats.schedule_enabled ? 'Enabled' : 'Disabled'}</strong>
        </div>
        <div className="bp-stat">
          <span className="bp-stat-label">Backups</span>
          <strong className="bp-stat-value">{stats.backups_total ?? 0}</strong>
          <span className="bp-stat-sub">{stats.backups_failed ?? 0} failed</span>
        </div>
        <div className="bp-stat">
          <span className="bp-stat-label">Restores</span>
          <strong className="bp-stat-value">{stats.restores_total ?? 0}</strong>
          <span className="bp-stat-sub">{stats.restores_failed ?? 0} failed</span>
        </div>
        <div className="bp-stat">
          <span className="bp-stat-label">Last success</span>
          <strong className="bp-stat-value bp-stat-value--sm">{formatWhen(stats.last_success_at)}</strong>
        </div>
        <div className="bp-stat">
          <span className="bp-stat-label">Offsite copy</span>
          {/* Read-only: which disk this points at is an environment
              variable (BACKUP_REMOTE_DISK), set on the server, not something
              this panel edits -- same reasoning as the scheduler's token. */}
          <strong className="bp-stat-value bp-stat-value--sm">
            {remote.configured ? `Enabled (${remote.disk})` : 'Not configured'}
          </strong>
          {!remote.configured && (
            <span className="bp-stat-sub">A deploy erases local-only backups</span>
          )}
        </div>
      </div>

      <div className="bp-grid">
        <section className="bp-card">
          <header className="bp-card-head">
            <h3>Backup schedule</h3>
            <p>Store SQL snapshots on the server and run them on a daily schedule.</p>
          </header>

          <label className="bp-check">
            <input
              type="checkbox"
              checked={enabled}
              onChange={(e) => setEnabled(e.target.checked)}
            />
            <span>Enable scheduled backups</span>
          </label>

          <div className="bp-field">
            <label htmlFor="bp-frequency">Schedule type</label>
            <select
              id="bp-frequency"
              value={frequency}
              onChange={(e) => setFrequency(e.target.value)}
              disabled
            >
              <option value="daily">Daily</option>
            </select>
          </div>

          <div className="bp-field">
            <label htmlFor="bp-time">
              Backup time ({stats.server_timezone || 'UTC'})
            </label>
            <input
              id="bp-time"
              type="time"
              value={time}
              onChange={(e) => setTime(e.target.value)}
            />
            <small className="bp-hint">
              Server time, not your local time. Server clock now:{' '}
              {formatWhen(stats.server_time)}
            </small>
          </div>

          <div className="bp-field">
            <label htmlFor="bp-path">Backup storage path</label>
            <input
              id="bp-path"
              type="text"
              placeholder={database.storage_path || 'Leave blank for default storage/app/backups'}
              value={storagePath}
              onChange={(e) => setStoragePath(e.target.value)}
            />
          </div>

          <div className="bp-field">
            <label htmlFor="bp-keep-daily">Keep daily snapshots for (days)</label>
            <input
              id="bp-keep-daily"
              type="number"
              min="1"
              max="365"
              value={keepDailyDays}
              onChange={(e) => setKeepDailyDays(e.target.value)}
            />
          </div>

          <div className="bp-field">
            <label htmlFor="bp-keep-weekly">Keep Sunday snapshots for (weeks)</label>
            <input
              id="bp-keep-weekly"
              type="number"
              min="1"
              max="260"
              value={keepWeeklyWeeks}
              onChange={(e) => setKeepWeeklyWeeks(e.target.value)}
            />
          </div>

          <div className="bp-field">
            <label htmlFor="bp-keep-monthly">Keep 1st-of-month snapshots for (months)</label>
            <input
              id="bp-keep-monthly"
              type="number"
              min="1"
              max="120"
              value={keepMonthlyMonths}
              onChange={(e) => setKeepMonthlyMonths(e.target.value)}
            />
            <small className="bp-hint">
              Weekly and monthly copies are what let you go back past an intrusion
              discovered weeks later, when every recent snapshot already contains
              the damage. The newest 3 snapshots are always kept, whatever these
              say. &ldquo;Sunday&rdquo; and &ldquo;1st&rdquo; follow server time
              ({stats.server_timezone || 'UTC'}), not your local calendar.
            </small>
          </div>

          <div className="bp-summary">
            <div><span>Summary</span><strong>{enabled ? 'Scheduled backups enabled' : 'Scheduled backups disabled'}</strong></div>
            <div>
              <span>Next scheduled run</span>
              {/* Overdue means the configured time has passed and today has not
                  run yet, so the next scheduler tick will fire a catch-up within
                  the minute. Printing the raw timestamp there would read as a
                  future appointment when it is really "now". */}
              <strong>
                {!enabled
                  ? '—'
                  : stats.scheduled_run_overdue
                    ? 'Overdue — runs within a minute'
                    : formatWhen(stats.next_scheduled_run)}
              </strong>
            </div>
            <div><span>Server timezone</span><strong>{stats.server_timezone || 'UTC'}</strong></div>
            <div><span>Server storage</span><strong className="bp-path">{displayPath}</strong></div>
          </div>

          <div className="bp-actions">
            <button type="button" className="bp-btn bp-btn--ghost" onClick={loadAll} disabled={saving || running}>
              Reload
            </button>
            <button type="button" className="bp-btn bp-btn--primary" onClick={handleSaveSchedule} disabled={saving || running}>
              {saving ? 'Saving…' : 'Save schedule'}
            </button>
          </div>
        </section>

        <section className="bp-card">
          <header className="bp-card-head">
            <h3>Backup safety</h3>
            <p>Manual and scheduled snapshots use the same conflict lock.</p>
          </header>

          <ul className="bp-safety">
            <li><strong>Conflict protection</strong> — only one backup or restore can run at a time.</li>
            <li><strong>Server storage</strong> — snapshots stay on the server until downloaded or restored.</li>
            <li><strong>Restore tracking</strong> — every restore attempt is logged in history.</li>
            <li><strong>Integrity</strong> — every snapshot records a SHA-256 and is
            verified before any restore. A dump without its completion trailer is
            rejected rather than kept. This catches corruption, not a determined
            attacker: the checksum lives in the same database the snapshot came from.</li>
            <li><strong>Retention</strong> — aged snapshots are deleted on the schedule
            above, but their history rows stay. The newest 3 are never deleted,
            and an unreachable storage folder is left alone rather than assumed
            empty.</li>
          </ul>

          <div className="bp-db">
            <div>
              <span>Database</span>
              <strong>{database.name || '—'}</strong>
            </div>
            <div>
              <span>Tables</span>
              <strong>{database.table_count != null ? database.table_count : '—'}</strong>
            </div>
          </div>

          <button
            type="button"
            className="bp-btn bp-btn--primary bp-btn--block"
            onClick={handleRunNow}
            disabled={running || saving}
          >
            {running ? 'Creating backup…' : 'Create backup now'}
          </button>
        </section>
      </div>

      <section className="bp-card bp-card--wide">
        <header className="bp-card-head bp-card-head--row">
          <div>
            <h3>Backup history</h3>
            <p>Review, download, and restore previous SQL snapshots.</p>
          </div>
          <button type="button" className="bp-btn bp-btn--ghost" onClick={reloadHistory} disabled={historyLoading}>
            {historyLoading ? 'Refreshing…' : 'Refresh'}
          </button>
        </header>

        <div className="bp-table-wrap">
          <table className="bp-table">
            <thead>
              <tr>
                <th>Date / Time</th>
                <th>Action</th>
                <th>Trigger</th>
                <th>Status</th>
                <th>File name</th>
                <th>Size</th>
                <th>Offsite</th>
                <th>Details</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {history.length === 0 ? (
                <tr>
                  <td colSpan={9} className="bp-empty">No backups yet. Create one with “Create backup now”.</td>
                </tr>
              ) : history.map((row) => (
                <tr key={row.log_id}>
                  <td>{formatWhen(row.ran_at)}</td>
                  <td>{row.action === 'restore' ? 'Restore' : 'Backup'}</td>
                  <td>{row.trigger === 'scheduled' ? 'Scheduled' : 'Manual'}</td>
                  <td><StatusBadge status={row.status} /></td>
                  <td className="bp-mono">{row.file_name || '—'}</td>
                  <td>{row.file_size_bytes != null ? formatBytes(row.file_size_bytes) : '—'}</td>
                  <td>
                    {/* Only backup rows have a file to sync; a restore record
                        never does. synced_to_remote is per-row, not a reflection
                        of whether remote sync is configured generally -- a sync
                        attempt can fail (logged) while the backup itself
                        succeeds, so this is the thing to trust, not the stat tile
                        above. */}
                    {row.action === 'backup' && row.status === 'success'
                      ? (row.synced_to_remote ? '✓' : '—')
                      : ''}
                  </td>
                  <td className="bp-details">
                    {row.error_message
                      || (row.triggered_by_name ? `By ${row.triggered_by_name}` : '—')}
                  </td>
                  <td className="bp-row-actions">
                    {row.action === 'backup' && row.status === 'success' ? (
                      <>
                        <button
                          type="button"
                          className="bp-link"
                          disabled={busyId === row.log_id}
                          onClick={() => handleDownload(row)}
                        >
                          Download
                        </button>
                        <button
                          type="button"
                          className="bp-link bp-link--danger"
                          disabled={busyId === row.log_id}
                          onClick={() => handleRestore(row)}
                        >
                          Restore
                        </button>
                      </>
                    ) : (
                      <span className="bp-muted">—</span>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>
      </>
      )}
      </div>
    </div>
  );
};

export default BackupPanel;
