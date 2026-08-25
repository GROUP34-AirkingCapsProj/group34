import React, { useEffect, useState, useCallback } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import Loader from '../components/Loader';
import LookupPanel from '../components/LookupPanel';
import { categoriesAPI, unitsAPI, batchAPI } from '../services/api';
import { useAuth } from '../context/AuthContext';
import { getRoleKey, ROLES } from '../utils/roles';
import { LOOKUP_CONFIGS, LOOKUP_TABS, tabForLookup } from '../config/lookupConfigs';
import '../styles/dashboard_air.css';
import '../styles/lookup_management.css';

const formatMoney = (n, { decimals = 2 } = {}) =>
  '₱' + (Number(n) ?? 0).toLocaleString('en-PH', {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  });

const isValidLookup = (key) => Object.prototype.hasOwnProperty.call(LOOKUP_CONFIGS, key);

const LookupManagement = () => {
  const { user, can } = useAuth();
  const roleKey = getRoleKey(user || {});
  const isAdmin = roleKey === ROLES.ADMIN;
  const permissions = {
    canCreate: isAdmin || (typeof can === 'function' && can('lookup_management', 'create')),
    canUpdate: isAdmin || (typeof can === 'function' && can('lookup_management', 'update')),
    canArchive: isAdmin || (typeof can === 'function' && can('lookup_management', 'archive')),
  };

  const location = useLocation();
  const navigate = useNavigate();

  const [activeLookup, setActiveLookup] = useState(() => {
    const params = new URLSearchParams(location.search);
    const requested = params.get('tab');
    if (requested && isValidLookup(requested)) return requested;
    if (location.pathname === '/admin/brands') return 'brands';
    return 'categories';
  });

  const activeTab = tabForLookup(activeLookup);

  const [units, setUnits] = useState([]);
  const [refLoading, setRefLoading] = useState(true);
  const [refError, setRefError] = useState(null);

  const fetchReferenceData = useCallback(async () => {
    try {
      setRefLoading(true);
      const batchRes = await batchAPI.get({ include: ['units'] });
      const batchData = batchRes?.data || {};
      setUnits(Array.isArray(batchData.units?.data) ? batchData.units.data : []);
      setRefError(null);
    } catch (err) {
      try {
        const unitRes = await unitsAPI.getAll();
        setUnits(Array.isArray(unitRes?.data) ? unitRes.data : []);
        setRefError(null);
      } catch (fallbackErr) {
        setRefError(fallbackErr.message || 'Failed to load reference data.');
        setUnits([]);
      }
    } finally {
      setRefLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchReferenceData();
  }, [fetchReferenceData]);

  // Sync the URL's ?tab= whenever the active lookup changes, without spamming history.
  useEffect(() => {
    const params = new URLSearchParams(location.search);
    if (params.get('tab') === activeLookup) return;
    params.set('tab', activeLookup);
    navigate({ pathname: location.pathname, search: `?${params.toString()}` }, { replace: true });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [activeLookup]);

  const selectTopTab = (tabKey) => {
    const tab = LOOKUP_TABS.find((t) => t.key === tabKey);
    if (!tab) return;
    setActiveLookup(tab.lookups[0]);
  };

  const config = LOOKUP_CONFIGS[activeLookup];

  const [perfOpen, setPerfOpen] = useState(false);
  const [performance, setPerformance] = useState([]);
  const [perfLoading, setPerfLoading] = useState(false);
  const [perfLoaded, setPerfLoaded] = useState(false);
  const [perfError, setPerfError] = useState(null);

  const togglePerformance = async () => {
    const next = !perfOpen;
    setPerfOpen(next);
    if (next && !perfLoaded) {
      try {
        setPerfLoading(true);
        const res = await categoriesAPI.getPerformance();
        setPerformance(Array.isArray(res?.data) ? res.data : []);
        setPerfError(null);
        setPerfLoaded(true);
      } catch (err) {
        setPerfError(err.message || 'Failed to load category performance.');
        setPerformance([]);
      } finally {
        setPerfLoading(false);
      }
    }
  };

  return (
    <AdminLayout>
      <div className="ak-page lk-page">
        <header className="ak-header">
          <div className="ak-header-row">
            <div>
              <p className="ak-eyebrow">Catalog · Classification</p>
              <h1 className="ak-title">Item Lookups</h1>
              <p className="ak-sub">
                Manage the shared categories, brands, units, capacities, variants,
                and AC types used across the item master.
              </p>
            </div>
          </div>
        </header>

        <div className="ak-tabs lk-top-tabs">
          {LOOKUP_TABS.map((tab) => (
            <button
              key={tab.key}
              type="button"
              className={`ak-tab${activeTab.key === tab.key ? ' is-active' : ''}`}
              onClick={() => selectTopTab(tab.key)}
            >
              {tab.label}
            </button>
          ))}
        </div>

        <div className="lk-subtabs" role="tablist">
          {activeTab.lookups.map((lookupKey) => (
            <button
              key={lookupKey}
              type="button"
              role="tab"
              aria-selected={activeLookup === lookupKey}
              className={`lk-subtab${activeLookup === lookupKey ? ' is-active' : ''}`}
              onClick={() => setActiveLookup(lookupKey)}
            >
              {LOOKUP_CONFIGS[lookupKey].label}
            </button>
          ))}
        </div>

        {refLoading && <Loader />}
        {!refLoading && refError && <div className="lk-error">Error: {refError}</div>}

        {!refLoading && !refError && (
          <>
            <LookupPanel
              key={activeLookup}
              config={config}
              units={units}
              permissions={permissions}
            />

            {activeLookup === 'categories' && (
              <section className="ak-card lk-performance">
                <button
                  type="button"
                  className="lk-performance-toggle"
                  onClick={togglePerformance}
                  aria-expanded={perfOpen}
                >
                  <span>
                    <h2 className="ak-card-title">Category Performance</h2>
                    <p className="ak-card-meta">Items and value by category.</p>
                  </span>
                  <span className={`lk-performance-caret${perfOpen ? ' is-open' : ''}`} aria-hidden>
                    ▾
                  </span>
                </button>

                {perfOpen && (
                  <div className="lk-performance-body">
                    {perfLoading && <Loader variant="inline" />}
                    {!perfLoading && perfError && <div className="lk-error">Error: {perfError}</div>}
                    {!perfLoading && !perfError && (
                      <div className="ak-table-wrap">
                        <table className="ak-table">
                          <thead>
                            <tr>
                              <th scope="col">Category</th>
                              <th scope="col">Total items</th>
                              <th scope="col">Total units</th>
                              <th scope="col">Total value</th>
                              <th scope="col">Avg price</th>
                            </tr>
                          </thead>
                          <tbody>
                            {performance.length === 0 ? (
                              <tr>
                                <td colSpan={5} className="lk-empty">No performance data yet.</td>
                              </tr>
                            ) : (
                              performance.map((row, i) => (
                                <tr key={row.category || i}>
                                  <td>{row.category}</td>
                                  <td>{row.total_items ?? 0}</td>
                                  <td>{(row.total_units ?? 0).toLocaleString('en-PH')}</td>
                                  <td>{formatMoney(row.total_value, { decimals: 0 })}</td>
                                  <td>{formatMoney(row.avg_price, { decimals: 2 })}</td>
                                </tr>
                              ))
                            )}
                          </tbody>
                        </table>
                      </div>
                    )}
                  </div>
                )}
              </section>
            )}
          </>
        )}
      </div>
    </AdminLayout>
  );
};

export default LookupManagement;
