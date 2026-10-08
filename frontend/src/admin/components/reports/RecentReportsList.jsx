import React from 'react';
import { reportsAPI } from '../../services/api';

/**
 * "Recent Reports" panel — every field it shows comes straight off the
 * ReportRun row (file_name, title_snapshot, generated_by, row_count,
 * file_size_bytes). Nothing here invents a filename or a size: the old page
 * this replaces hardcoded four fake rows, which is exactly the kind of claim
 * this whole project exists to stop the UI making.
 *
 * Props:
 *   runs    {array}    ReportRun rows (as returned by GET /reports/runs), newest first
 *   loading {boolean}  show skeleton rows instead of `runs`
 *   onRerun {(run) => void}  called for an expired run's Re-run button
 */
export default function RecentReportsList({ runs = [], loading = false, onRerun }) {
  if (loading) {
    return (
      <div className="sr-recent-list" aria-busy="true">
        {[0, 1, 2].map((i) => (
          <div className="sr-recent-item" key={i} data-testid="recent-report-skeleton">
            <div className="sr-recent-item-left">
              <div className="sr-file-icon" />
              <div className="sr-recent-info">
                <span className="sr-recent-name">&nbsp;</span>
                <span className="sr-recent-meta">&nbsp;</span>
              </div>
            </div>
          </div>
        ))}
      </div>
    );
  }

  if (runs.length === 0) {
    return <p className="ak-muted">No reports have been generated yet — generate one above to see it here.</p>;
  }

  return (
    <div className="sr-recent-list">
      {runs.map((run) => (
        <RecentReportRow key={run.report_run_id} run={run} onRerun={onRerun} />
      ))}
    </div>
  );
}

function RecentReportRow({ run, onRerun }) {
  const expired = !!run.is_expired;
  // `generated_by`, not `generatedBy`: Laravel snake-cases relation keys on
  // serialization and merges relations over attributes, so the eager-loaded
  // user arrives under the same key as the id column it shadows.
  const generatedBy = userDisplayName(run.generated_by);

  return (
    <div
      className={`sr-recent-item${expired ? ' sr-recent-item--expired' : ''}`}
      style={expired ? { opacity: 0.65 } : undefined}
    >
      <div className="sr-recent-item-left">
        <div className="sr-file-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
            <polyline points="14 2 14 8 20 8" />
            <line x1="16" y1="13" x2="8" y2="13" />
            <line x1="16" y1="17" x2="8" y2="17" />
          </svg>
        </div>
        <div className="sr-recent-info">
          <span className="sr-recent-name">
            {run.file_name}
            {expired && <span className="ak-badge ak-badge--gray" style={{ marginLeft: '0.5rem' }}>File expired</span>}
          </span>
          <span className="sr-recent-meta">
            {run.title_snapshot} • Generated: {formatDateTime(run.created_at)} by {generatedBy}
            {run.row_count != null && ` • ${run.row_count.toLocaleString()} rows`}
          </span>
        </div>
      </div>
      <div className="sr-recent-item-right">
        {!expired && run.file_size_bytes != null && (
          <span className="sr-recent-size">{formatFileSize(run.file_size_bytes)}</span>
        )}
        {expired ? (
          // No download link for an expired run — the file is gone, only the
          // row survives. Re-run is the only honest action left to offer.
          <button type="button" className="ak-btn ak-btn--secondary" onClick={() => onRerun?.(run)}>
            Re-run
          </button>
        ) : (
          <>
            <a
              className="sr-btn-download"
              href={reportsAPI.fileUrl(run.report_run_id)}
              target="_blank"
              rel="noopener noreferrer"
            >
              Download
            </a>
            {run.format === 'pdf' && (
              <a
                className="sr-btn-view"
                href={reportsAPI.fileUrl(run.report_run_id, { inline: true })}
                target="_blank"
                rel="noopener noreferrer"
              >
                View
              </a>
            )}
          </>
        )}
      </div>
    </div>
  );
}

// `generatedBy` is the eager-loaded relation (ReportController::generate/runs
// call ->load('generatedBy')); User has no full_name column, only
// first_name/last_name, so it's assembled here rather than assumed.
function userDisplayName(user) {
  // A run whose relation was not eager-loaded carries the bare id here, which
  // is a number and names nobody.
  if (!user || typeof user !== 'object') return 'Unknown user';
  const name = [user.first_name, user.last_name].filter(Boolean).join(' ').trim();
  return name || user.username || user.email || 'Unknown user';
}

function formatDateTime(raw) {
  if (!raw) return 'Unknown date';
  const d = new Date(raw);
  if (Number.isNaN(d.getTime())) return String(raw);
  return d.toLocaleString('en-PH', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  });
}

function formatFileSize(bytes) {
  const n = Number(bytes);
  if (!Number.isFinite(n) || n < 0) return '—';
  if (n < 1024) return `${n} B`;
  const units = ['KB', 'MB', 'GB'];
  let value = n / 1024;
  let unitIndex = 0;
  while (value >= 1024 && unitIndex < units.length - 1) {
    value /= 1024;
    unitIndex += 1;
  }
  return `${value.toFixed(value < 10 ? 1 : 0)} ${units[unitIndex]}`;
}
