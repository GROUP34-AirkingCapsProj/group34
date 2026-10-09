import React, { useCallback, useEffect, useState } from 'react';
import AdminLayout from '../components/AdminLayout';
import Loader from '../components/Loader';
import IncidentDocument from '../components/incident/IncidentDocument';
import { incidentReportsAPI } from '../services/api';
import { useAuth } from '../context/AuthContext';
import { DOC_MODES, INSPECTION_EXEMPT_REASONS } from '../utils/incidentDocument';
import { reasonLabel } from '../utils/incidentReasons';

/**
 * Inspections — the queue, and the document opened on `inspect`.
 *
 * The report is ONE file that travels. What the inspector opens here is the
 * same sheet the reporter filled in, with the reporter's half locked above the
 * rule and the inspector's half editable below it — not a second screen that
 * summarises the first in a different order. All of that lives in
 * IncidentDocument; this page is the list on the left and the fetch that feeds
 * it.
 *
 * Filing quarantines the goods without deducting them: they are physically in
 * the building, still counted, simply unpickable. Nothing here moves stock
 * either. Only an admin's confirmation does.
 *
 * Statuses are read by NAME. The ids are install-specific, and a report the
 * admin REJECTED back sits at Awaiting Inspection rather than Open, so a queue
 * built on an `is_open` flag would silently lose every rejection.
 */

const INSPECTABLE_STATUSES = ['Open', 'Awaiting Inspection'];

const IncidentInspectionPage = ({ embedded = false }) => {
  const Layout = embedded ? React.Fragment : AdminLayout;
  const { can } = useAuth();
  const canInspect = typeof can === 'function' && can('incident_reports', 'inspect');

  const [queue, setQueue] = useState([]);
  const [loadingQueue, setLoadingQueue] = useState(true);
  const [queueError, setQueueError] = useState(null);

  const [selectedId, setSelectedId] = useState(null);
  const [report, setReport] = useState(null);
  const [loadingReport, setLoadingReport] = useState(false);
  const [reportError, setReportError] = useState(null);

  const loadQueue = useCallback(async () => {
    setLoadingQueue(true);
    try {
      const res = await incidentReportsAPI.list();
      const rows = Array.isArray(res?.data) ? res.data : [];
      const inspectable = rows.filter((r) =>
        INSPECTABLE_STATUSES.includes(r.status_name)
        && !INSPECTION_EXEMPT_REASONS.includes(r.reason));
      setQueue(inspectable);
      // A submitted inspection belongs to the ADMIN now, so it is gone from
      // this list. Leaving it selected left the document on screen in `inspect`
      // mode with a live Submit button, which is what made submitting look like
      // it had done nothing at all.
      setSelectedId((current) => (
        current !== null && !inspectable.some((r) => String(r.incident_id) === String(current))
          ? null
          : current
      ));
      setQueueError(null);
    } catch (err) {
      setQueueError(err.message || 'Failed to load reports awaiting inspection.');
      setQueue([]);
    } finally {
      setLoadingQueue(false);
    }
  }, []);

  useEffect(() => { loadQueue(); }, [loadQueue]);

  const loadReport = useCallback(async (id) => {
    if (!id) { setReport(null); return; }
    setLoadingReport(true);
    try {
      const res = await incidentReportsAPI.get(id);
      setReport(res?.data || null);
      setReportError(null);
    } catch (err) {
      setReportError(err.message || 'Failed to load that report.');
      setReport(null);
    } finally {
      setLoadingReport(false);
    }
  }, []);

  useEffect(() => { loadReport(selectedId); }, [selectedId, loadReport]);

  return (
    <Layout>
      <div className="ak-page">
        <header className="ak-header">
          <div className="ak-header-row">
            <div>
              <p className="ak-eyebrow">Inventory · Exceptions</p>
              <h1 className="ak-title">Inspections</h1>
              <p className="ak-sub">
                Examine what was reported, record what should happen to each unit, and attach the
                evidence. Nothing here moves stock — the quantities stay exactly as they are until an
                admin confirms your findings.
              </p>
            </div>
            <div className="ak-header-aside">
              <span className="ak-badge ak-badge--amber">{queue.length} to inspect</span>
            </div>
          </div>
        </header>

        <div style={{ display: 'grid', gridTemplateColumns: 'minmax(240px, 320px) 1fr', gap: '1rem', alignItems: 'start' }}>
          <div className="ak-card" style={{ padding: '0.6rem' }}>
            {loadingQueue ? (
              <Loader label="Loading reports" />
            ) : queueError ? (
              <div className="ak-state ak-state--error">{queueError}</div>
            ) : queue.length === 0 ? (
              <div className="ak-state">Nothing is waiting to be inspected.</div>
            ) : (
              <ul style={{ listStyle: 'none', margin: 0, padding: 0 }}>
                {queue.map((r) => (
                  <li key={r.incident_id}>
                    <button
                      type="button"
                      className={`ak-btn ${String(r.incident_id) === String(selectedId) ? 'ak-btn--primary' : ''}`}
                      style={{ width: '100%', justifyContent: 'flex-start', textAlign: 'left', marginBottom: '0.35rem' }}
                      onClick={() => setSelectedId(r.incident_id)}
                    >
                      <span>
                        <span className="ak-mono">{r.incident_number}</span>
                        <br />
                        <span style={{ fontSize: '0.8rem' }}>
                          {reasonLabel(r.reason)} · {r.location_name || '—'}
                        </span>
                        {r.status_name === 'Awaiting Inspection' && (
                          <>
                            <br />
                            {/* A resubmission, not a fresh report: the admin sent
                                this one back and the note says what to fix. */}
                            <span className="ak-badge ak-badge--blue">Sent back</span>
                          </>
                        )}
                      </span>
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </div>

          <div>
            {loadingReport ? (
              <Loader label="Loading report" />
            ) : reportError ? (
              <div className="ak-state ak-state--error">{reportError}</div>
            ) : !report ? (
              <div className="ak-state">Choose a report on the left to inspect it.</div>
            ) : (
              <IncidentDocument
                // Remounted per report, so the findings and signature fields
                // seed from the one on screen rather than keeping the last
                // report's text.
                key={report.incident_id}
                mode={DOC_MODES.INSPECT}
                report={report}
                canInspect={canInspect}
                onChanged={setReport}
                onSubmitted={() => { loadQueue(); }}
              />
            )}
          </div>
        </div>
      </div>
    </Layout>
  );
};

export default IncidentInspectionPage;
