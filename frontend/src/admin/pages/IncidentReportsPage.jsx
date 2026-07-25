import React, { useCallback, useEffect, useMemo, useState } from 'react';
import AdminLayout from '../components/AdminLayout';
import Loader from '../components/Loader';
import IncidentResolveDrawer from '../components/IncidentResolveDrawer';
import { incidentReportsAPI, locationsAPI, statusAPI } from '../services/api';
import { useAuth } from '../context/AuthContext';

const REASONS = [
  { value: 'not_found', label: 'Not found' },
  { value: 'damaged', label: 'Damaged' },
  { value: 'wrong_type', label: 'Wrong type' },
  { value: 'zone_mismatch', label: 'Zone mismatch' },
];

const REASON_BADGE = {
  not_found: 'ak-badge--red',
  damaged: 'ak-badge--amber',
  wrong_type: 'ak-badge--blue',
  zone_mismatch: 'ak-badge--gray',
};

/**
 * Triage list + resolve drawer for incident reports (filed from the barcode
 * scanner during picking — see IncidentService on the backend). status_id is
 * an install-specific FK into status_lookup (category 'incident'), so the
 * Open/Resolved/Dismissed name is resolved here from that lookup rather than
 * hardcoded, matching how IncidentReport::statusId() works server-side.
 */
const IncidentReportsPage = () => {
  const { can } = useAuth();
  const canResolve = typeof can === 'function' && can('incident_reports', 'resolve');

  const [incidents, setIncidents] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const [statusFilter, setStatusFilter] = useState('open'); // 'open' | 'all'
  const [reasonFilter, setReasonFilter] = useState('');
  const [locationFilter, setLocationFilter] = useState('');
  const [locations, setLocations] = useState([]);
  const [statusNameById, setStatusNameById] = useState({});

  const [selected, setSelected] = useState(null);

  useEffect(() => {
    locationsAPI.getAll({ per_page: 500 })
      .then((res) => setLocations(Array.isArray(res?.data) ? res.data : []))
      .catch(() => setLocations([]));
    statusAPI.getAll({ category: 'incident' })
      .then((res) => {
        const rows = Array.isArray(res?.data) ? res.data : [];
        const map = {};
        rows.forEach((s) => { map[s.status_id] = s.status_name; });
        setStatusNameById(map);
      })
      .catch(() => setStatusNameById({}));
  }, []);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const params = {};
      if (statusFilter === 'open') params.status = 'open';
      if (reasonFilter) params.reason = reasonFilter;
      if (locationFilter) params.location_id = locationFilter;
      const res = await incidentReportsAPI.list(params);
      setIncidents(Array.isArray(res?.data) ? res.data : []);
      setError(null);
    } catch (err) {
      setError(err.message || 'Failed to load incident reports.');
      setIncidents([]);
    } finally {
      setLoading(false);
    }
  }, [statusFilter, reasonFilter, locationFilter]);

  useEffect(() => { load(); }, [load]);

  const statusLabel = useMemo(() => (incident) => {
    if (incident.is_open) return { text: 'Open', cls: 'ak-badge--amber' };
    const name = statusNameById[incident.status_id];
    if (name === 'Dismissed') return { text: 'Dismissed', cls: 'ak-badge--gray' };
    // Default to Resolved once reviewed — covers the brief window before the
    // status lookup has loaded, and any status other than Open/Dismissed.
    return { text: name || 'Resolved', cls: 'ak-badge--green' };
  }, [statusNameById]);

  const openCount = incidents.filter((i) => i.is_open).length;
  const alertReporterCount = incidents.filter((i) => i.reporter_incident_count >= 3).length;

  return (
    <AdminLayout>
      <div className="ak-page">
        <header className="ak-header">
          <div className="ak-header-row">
            <div>
              <p className="ak-eyebrow">Inventory · Exceptions</p>
              <h1 className="ak-title">Incident Reports</h1>
              <p className="ak-sub">
                Triage batch discrepancies filed from the scanner during picking — missing stock, damage, wrong
                product on the shelf, or a zone that doesn't match the batch record.
              </p>
            </div>
            <div className="ak-header-aside">
              <span className="ak-badge ak-badge--amber">{openCount} open</span>
              {alertReporterCount > 0 && (
                <span className="ak-badge ak-badge--red" style={{ marginLeft: '0.4rem' }}>
                  {alertReporterCount} row(s) from a flagged reporter
                </span>
              )}
            </div>
          </div>
        </header>

        <div className="ak-toolbar">
          <select className="ak-select" value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
            <option value="open">Open only</option>
            <option value="all">All statuses</option>
          </select>
          <select className="ak-select" value={reasonFilter} onChange={(e) => setReasonFilter(e.target.value)}>
            <option value="">All reasons</option>
            {REASONS.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}
          </select>
          <select className="ak-select" value={locationFilter} onChange={(e) => setLocationFilter(e.target.value)}>
            <option value="">All locations</option>
            {locations.map((l) => (
              <option key={l.location_id ?? l.id} value={l.location_id ?? l.id}>
                {l.location_name || l.name}
              </option>
            ))}
          </select>
        </div>

        {loading ? (
          <Loader label="Loading incidents" />
        ) : error ? (
          <div className="ak-state ak-state--error">{error}</div>
        ) : incidents.length === 0 ? (
          <div className="ak-state">No incident reports found.</div>
        ) : (
          <div className="ak-table-wrap">
            <table className="ak-table">
              <thead>
                <tr>
                  <th>Incident #</th>
                  <th>Date</th>
                  <th>Reason</th>
                  <th>Product</th>
                  <th>Qty</th>
                  <th>Zone</th>
                  <th>Source</th>
                  <th>Reported by</th>
                  <th>Status</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {incidents.map((i) => {
                  const st = statusLabel(i);
                  const flagged = i.reporter_incident_count >= 3;
                  return (
                    <tr key={i.incident_id}>
                      <td className="ak-mono">{i.incident_number}</td>
                      <td>{i.created_at ? new Date(i.created_at).toLocaleString() : '—'}</td>
                      <td>
                        <span className={`ak-badge ${REASON_BADGE[i.reason] || 'ak-badge--gray'}`}>
                          {REASONS.find((r) => r.value === i.reason)?.label || i.reason}
                        </span>
                      </td>
                      <td>{i.product_name || '—'}</td>
                      <td>{i.quantity}</td>
                      <td className="ak-mono">{i.expected_zone || '—'} → {i.actual_zone || '—'}</td>
                      <td>{i.source_type} #{i.source_id}</td>
                      <td>
                        <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem' }}>
                          <span>{i.reported_by_name || '—'}</span>
                          <span
                            className={`ak-badge ${flagged ? 'ak-badge--red' : 'ak-badge--gray'}`}
                            title="Incidents filed by this reporter in the trailing 30 days"
                          >
                            {i.reporter_incident_count}
                          </span>
                        </div>
                      </td>
                      <td><span className={`ak-badge ${st.cls}`}>{st.text}</span></td>
                      <td>
                        <button type="button" className="ak-btn ak-btn--sm" onClick={() => setSelected(i)}>
                          {canResolve && i.is_open ? 'Review' : 'View'}
                        </button>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <IncidentResolveDrawer
        incident={selected}
        onClose={() => setSelected(null)}
        canResolve={canResolve}
        onResolved={() => { setSelected(null); load(); }}
      />
    </AdminLayout>
  );
};

export default IncidentReportsPage;
