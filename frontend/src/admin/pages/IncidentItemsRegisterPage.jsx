import React, { useCallback, useEffect, useMemo, useState } from 'react';
import AdminLayout from '../components/AdminLayout';
import Loader from '../components/Loader';
import UnitRoleBadge from '../components/UnitRoleBadge';
import { incidentItemsAPI, locationsAPI } from '../services/api';
import { packageBreakdown } from '../utils/packageFormat';
import { ITEM_STATUSES, itemStatusMeta, outcomeLabel, unitRoleLabel } from '../utils/incidentItems';

/**
 * The incident-items register — every physical unit an incident report has
 * touched, and where it stands.
 *
 * Read-only, and deliberately so. This used to be Held Stock, a worklist with a
 * Dispose button; the inspection took that decision over, and it now belongs to
 * one person examining the goods and another confirming the result. A second
 * place to settle an item would be a second way for the stock count to move.
 *
 * **Quarantined is not a deduction.** Those units are physically in the
 * building and still counted in quantity_on_hand — they are simply unpickable
 * until an admin confirms an inspection. Scrapped, returned and missing are the
 * ones that have already left the count.
 */
const IncidentItemsRegisterPage = () => {
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const [statusFilter, setStatusFilter] = useState('quarantined'); // a status, or 'all'
  const [locationFilter, setLocationFilter] = useState('');
  const [locations, setLocations] = useState([]);

  useEffect(() => {
    locationsAPI.getAll({ per_page: 500 })
      .then((res) => setLocations(Array.isArray(res?.data) ? res.data : []))
      .catch(() => setLocations([]));
  }, []);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const params = { status: statusFilter };
      if (locationFilter) params.location_id = locationFilter;
      const res = await incidentItemsAPI.list(params);
      setItems(Array.isArray(res?.data) ? res.data : []);
      setError(null);
    } catch (err) {
      setError(err.message || 'Failed to load the register.');
      setItems([]);
    } finally {
      setLoading(false);
    }
  }, [statusFilter, locationFilter]);

  useEffect(() => { load(); }, [load]);

  // Counts describe what is actually on screen, so they stay honest under a
  // filter rather than claiming a total the table does not show.
  const counts = useMemo(() => ({
    total: items.length,
    quarantined: items.filter((i) => i.status === 'quarantined').length,
  }), [items]);

  return (
    <AdminLayout>
      <div className="ak-page">
        <header className="ak-header">
          <div className="ak-header-row">
            <div>
              <p className="ak-eyebrow">Inventory · Exceptions</p>
              <h1 className="ak-title">Incident Items</h1>
              <p className="ak-sub">
                Every physical unit an incident report names, and where it stands. Quarantined stock
                is still on hand and still counted — it just cannot be picked until an admin confirms
                an inspection. Scrapped, returned and missing have already left the count.
              </p>
            </div>
            <div className="ak-header-aside">
              <span className="ak-badge ak-badge--gray">{counts.total} shown</span>
              {counts.quarantined > 0 && (
                <span className="ak-badge ak-badge--amber" style={{ marginLeft: '0.4rem' }}>
                  {counts.quarantined} quarantined
                </span>
              )}
            </div>
          </div>
        </header>

        <div className="ak-toolbar">
          <select className="ak-select" value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
            {ITEM_STATUSES.map((s) => (
              <option key={s.value} value={s.value}>{s.label}</option>
            ))}
            <option value="all">All, including settled</option>
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
          <Loader label="Loading the register" />
        ) : error ? (
          <div className="ak-state ak-state--error">{error}</div>
        ) : items.length === 0 ? (
          <div className="ak-state">Nothing matches that filter.</div>
        ) : (
          <div className="ak-table-wrap">
            <table className="ak-table">
              <thead>
                <tr>
                  <th>Status</th>
                  <th>Product</th>
                  <th>Unit</th>
                  <th>Qty</th>
                  <th>Location</th>
                  <th>Batch / Serial</th>
                  <th>From incident</th>
                  <th>Outcome</th>
                  <th>Since</th>
                </tr>
              </thead>
              <tbody>
                {items.map((item) => {
                  // quantity is PIECES. packageBreakdown returns null when the
                  // product has no pack size, so appliances read "3 pcs".
                  const breakdown = packageBreakdown(item.quantity, item.pieces_per_package, item.package_unit);
                  const status = itemStatusMeta(item.status);
                  return (
                    <tr key={item.item_id}>
                      <td><span className={`ak-badge ${status.badge}`}>{status.label}</span></td>
                      <td>
                        <UnitRoleBadge role={item.unit_role} />
                        {item.product_name || `#${item.product_id}`}
                        {item.ac_type_name && (
                          <span style={{ color: '#6b7280', fontSize: 11 }}> · {item.ac_type_name}</span>
                        )}
                      </td>
                      <td>
                        {unitRoleLabel(item.unit_role)}
                        {item.model_code && (
                          <span className="ak-muted" style={{ marginLeft: 4, fontSize: '0.8rem' }}>
                            · {item.model_code}
                          </span>
                        )}
                      </td>
                      <td>
                        {item.quantity} pcs
                        {breakdown && (
                          <span className="ak-muted" style={{ marginLeft: 4, fontSize: '0.8rem' }}>
                            · {breakdown}
                          </span>
                        )}
                      </td>
                      <td>{item.location_name || '—'}</td>
                      <td className="ak-mono">
                        {item.batch_id ? `#${item.batch_id}` : '—'}
                        {item.serial_number ? ` · SN ${item.serial_number}` : (item.serial_id ? ` · SN ${item.serial_id}` : '')}
                      </td>
                      <td className="ak-mono">{item.incident_number || `#${item.incident_id}`}</td>
                      <td>
                        {item.outcome ? (
                          <>
                            {outcomeLabel(item.outcome)}
                            {/* An outcome on a still-quarantined item is the
                                inspector's proposal, not a fact — the stock has
                                not moved and an admin can still reject it. */}
                            {item.status === 'quarantined' && (
                              <span className="ak-muted" style={{ marginLeft: 4, fontSize: '0.8rem' }}>
                                · proposed, not yet confirmed
                              </span>
                            )}
                            {item.outcome_note && (
                              <div className="ak-muted" style={{ fontSize: '0.8rem' }}>{item.outcome_note}</div>
                            )}
                          </>
                        ) : (
                          <span className="ak-muted" style={{ fontSize: '0.8rem' }}>Awaiting inspection</span>
                        )}
                      </td>
                      <td>{item.created_at ? new Date(item.created_at).toLocaleString() : '—'}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </AdminLayout>
  );
};

export default IncidentItemsRegisterPage;
