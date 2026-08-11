import React, { useCallback, useEffect, useMemo, useState } from 'react';
import AdminLayout from '../components/AdminLayout';
import Loader from '../components/Loader';
import DisposeHoldModal from '../components/incident/DisposeHoldModal';
import { locationsAPI, stockHoldsAPI } from '../services/api';
import { useAuth } from '../context/AuthContext';
import { packageBreakdown } from '../utils/packageFormat';

/**
 * Held Stock — the worklist for stock that exists but is not sellable.
 *
 * Approving an incident no longer deletes the quantity; it moves it here, into
 * a condition. Missing is terminal and stays as a countable loss. Defective and
 * Returned wait for an inspection that decides where they go.
 *
 * Which dispositions a row offers comes from the server's
 * `allowed_dispositions`, never from a rule re-derived here.
 */
const CONDITION_BADGE = {
  missing: 'ak-badge--red',
  defective: 'ak-badge--amber',
  returned: 'ak-badge--green',
};

const CONDITION_LABEL = {
  missing: 'Missing',
  defective: 'Defective',
  returned: 'Returned',
};

const StockHoldsPage = () => {
  const { can } = useAuth();
  const canDispose = typeof can === 'function' && can('incident_reports', 'dispose');

  const [holds, setHolds] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const [statusFilter, setStatusFilter] = useState('held'); // 'held' | 'all'
  const [conditionFilter, setConditionFilter] = useState('');
  const [locationFilter, setLocationFilter] = useState('');
  const [locations, setLocations] = useState([]);

  const [disposing, setDisposing] = useState(null);

  useEffect(() => {
    locationsAPI.getAll({ per_page: 500 })
      .then((res) => setLocations(Array.isArray(res?.data) ? res.data : []))
      .catch(() => setLocations([]));
  }, []);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const params = { status: statusFilter };
      if (conditionFilter) params.condition = conditionFilter;
      if (locationFilter) params.location_id = locationFilter;
      const res = await stockHoldsAPI.list(params);
      setHolds(Array.isArray(res?.data) ? res.data : []);
      setError(null);
    } catch (err) {
      setError(err.message || 'Failed to load held stock.');
      setHolds([]);
    } finally {
      setLoading(false);
    }
  }, [statusFilter, conditionFilter, locationFilter]);

  useEffect(() => { load(); }, [load]);

  // Counts describe what is actually on screen, so they stay honest under a
  // filter rather than claiming a total the table does not show.
  const openCounts = useMemo(() => {
    const held = holds.filter((h) => h.status === 'held');
    return {
      total: held.length,
      defective: held.filter((h) => h.condition === 'defective').length,
      returned: held.filter((h) => h.condition === 'returned').length,
    };
  }, [holds]);

  return (
    <AdminLayout>
      <div className="ak-page">
        <header className="ak-header">
          <div className="ak-header-row">
            <div>
              <p className="ak-eyebrow">Inventory · Exceptions</p>
              <h1 className="ak-title">Held Stock</h1>
              <p className="ak-sub">
                Stock that exists but is not sellable — missing, defective, or returned and awaiting
                inspection. Nothing here counts toward active inventory until it is put back deliberately.
              </p>
            </div>
            <div className="ak-header-aside">
              <span className="ak-badge ak-badge--amber">{openCounts.total} held</span>
              {openCounts.returned > 0 && (
                <span className="ak-badge ak-badge--green" style={{ marginLeft: '0.4rem' }}>
                  {openCounts.returned} awaiting inspection
                </span>
              )}
            </div>
          </div>
        </header>

        <div className="ak-toolbar">
          <select className="ak-select" value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
            <option value="held">Still held</option>
            <option value="all">All, including disposed</option>
          </select>
          <select className="ak-select" value={conditionFilter} onChange={(e) => setConditionFilter(e.target.value)}>
            <option value="">All conditions</option>
            <option value="missing">Missing</option>
            <option value="defective">Defective</option>
            <option value="returned">Returned</option>
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
          <Loader label="Loading held stock" />
        ) : error ? (
          <div className="ak-state ak-state--error">{error}</div>
        ) : holds.length === 0 ? (
          <div className="ak-state">Nothing is being held.</div>
        ) : (
          <div className="ak-table-wrap">
            <table className="ak-table">
              <thead>
                <tr>
                  <th>Condition</th>
                  <th>Product</th>
                  <th>Qty</th>
                  <th>Location</th>
                  <th>Batch / Serial</th>
                  <th>From incident</th>
                  <th>Held since</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {holds.map((h) => {
                  // quantity is PIECES. packageBreakdown returns null when the
                  // product has no pack size, so appliances read "3 pcs".
                  const breakdown = packageBreakdown(h.quantity, h.pieces_per_package, h.package_unit);
                  return (
                    <tr key={h.hold_id}>
                      <td>
                        <span className={`ak-badge ${CONDITION_BADGE[h.condition] || 'ak-badge--gray'}`}>
                          {CONDITION_LABEL[h.condition] || h.condition}
                        </span>
                      </td>
                      <td>{h.product_name || `#${h.product_id}`}</td>
                      <td>
                        {h.quantity} pcs
                        {breakdown && (
                          <span className="ak-muted" style={{ marginLeft: 4, fontSize: '0.8rem' }}>
                            · {breakdown}
                          </span>
                        )}
                      </td>
                      <td>{h.location_name || '—'}</td>
                      <td className="ak-mono">
                        {h.batch_id ? `#${h.batch_id}` : '—'}
                        {h.serial_id ? ` · SN ${h.serial_id}` : ''}
                      </td>
                      <td className="ak-mono">{h.incident_number || `#${h.incident_id}`}</td>
                      <td>{h.created_at ? new Date(h.created_at).toLocaleString() : '—'}</td>
                      <td>
                        {h.status === 'released' ? (
                          <span className="ak-muted" style={{ fontSize: '0.8rem' }}>
                            {(h.disposition || '').replace(/_/g, ' ') || 'disposed'}
                            {h.disposed_by_name ? ` — ${h.disposed_by_name}` : ''}
                          </span>
                        ) : h.allowed_dispositions.length === 0 ? (
                          // Missing is terminal: the loss is the record, and an
                          // item that turns up comes back via a fresh report.
                          <span className="ak-muted" style={{ fontSize: '0.8rem' }}>
                            No action — recorded as a loss
                          </span>
                        ) : canDispose ? (
                          <button
                            type="button"
                            className="ak-btn ak-btn--sm ak-btn--primary"
                            onClick={() => setDisposing(h)}
                          >
                            Dispose
                          </button>
                        ) : (
                          <span className="ak-muted" style={{ fontSize: '0.8rem' }}>Awaiting inspection</span>
                        )}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <DisposeHoldModal
        hold={disposing}
        onClose={() => setDisposing(null)}
        onDisposed={() => { setDisposing(null); load(); }}
      />
    </AdminLayout>
  );
};

export default StockHoldsPage;
