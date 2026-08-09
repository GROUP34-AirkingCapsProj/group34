import React, { useCallback, useEffect, useMemo, useState } from 'react';
import AdminLayout from '../components/AdminLayout';
import Loader from '../components/Loader';
import IncidentResolveDrawer from '../components/IncidentResolveDrawer';
import IncidentFileModal from '../components/IncidentFileModal';
import { incidentReportsAPI, locationsAPI, statusAPI } from '../services/api';
import { useAuth } from '../context/AuthContext';
import { INCIDENT_REASONS, reasonBadge, reasonLabel } from '../utils/incidentReasons';
import { packageBreakdown } from '../utils/packageFormat';

/**
 * Report + triage surface for incident reports. Incidents arrive two ways: as a
 * FIFO override filed from the barcode scanner mid-pick, and — via the Report
 * Incident button here — as a standalone report of something a user simply
 * noticed (theft, damage, a customer return).
 *
 * status_id is an install-specific FK into status_lookup (category 'incident'),
 * so the Open/Resolved/Dismissed name is resolved here from that lookup rather
 * than hardcoded, matching how IncidentReport::statusId() works server-side.
 */
const IncidentReportsPage = () => {
  const { can } = useAuth();
  const canResolve = typeof can === 'function' && can('incident_reports', 'resolve');
  const canCreate = typeof can === 'function' && can('incident_reports', 'create');

  const [incidents, setIncidents] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const [statusFilter, setStatusFilter] = useState('open'); // 'open' | 'all'
  const [reasonFilter, setReasonFilter] = useState('');
  const [locationFilter, setLocationFilter] = useState('');
  const [locations, setLocations] = useState([]);
  const [statusNameById, setStatusNameById] = useState({});

  const [selected, setSelected] = useState(null);
  const [filing, setFiling] = useState(false);

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
                Report and triage stock problems — missing or stolen stock, damage in storage or delivery, the
                wrong product on the shelf, customer returns, or anything else that needs a record and a reviewer.
              </p>
            </div>
            <div className="ak-header-aside">
              {canCreate && (
                <button
                  type="button"
                  className="ak-btn ak-btn--primary"
                  onClick={() => setFiling(true)}
                  style={{ marginRight: '0.6rem' }}
                >
                  Report Incident
                </button>
              )}
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
            {INCIDENT_REASONS.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}
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
                  <th>Occurred</th>
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
                      {/* occurred_at is when it happened; created_at is when it was
                          filed. Rows predating the column have only the latter. */}
                      <td>
                        {(i.occurred_at || i.created_at)
                          ? new Date(i.occurred_at || i.created_at).toLocaleString()
                          : '—'}
                      </td>
                      <td>
                        <span className={`ak-badge ${reasonBadge(i.reason)}`}>
                          {reasonLabel(i.reason)}
                        </span>
                      </td>
                      <td>{i.product_name || '—'}</td>
                      {/* quantity is PIECES. packageBreakdown returns null when
                          the product has no pack size, so appliances read "3 pcs". */}
                      <td>
                        {i.quantity} pcs
                        {packageBreakdown(i.quantity, i.pieces_per_package, i.package_unit) && (
                          <span className="ak-muted" style={{ marginLeft: 4, fontSize: '0.8rem' }}>
                            · {packageBreakdown(i.quantity, i.pieces_per_package, i.package_unit)}
                          </span>
                        )}
                      </td>
                      <td className="ak-mono">{i.expected_zone || '—'} → {i.actual_zone || '—'}</td>
                      {/* A standalone report has no source transaction, so the
                          old `{type} #{id}` would render "standalone #". */}
                      <td>
                        {i.source_type === 'standalone'
                          ? 'Reported directly'
                          : `${i.source_type} #${i.source_id}`}
                      </td>
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

      <IncidentFileModal
        isOpen={filing}
        onClose={() => setFiling(false)}
        onFiled={() => { setFiling(false); load(); }}
      />
    </AdminLayout>
  );
};

export default IncidentReportsPage;
