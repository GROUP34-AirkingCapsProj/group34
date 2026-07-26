import React, { useEffect, useState, useCallback } from 'react';
import AdminLayout from '../components/AdminLayout';
import Loader from '../components/Loader';
import SetBuilderModal from '../components/SetBuilderModal';
import { productSetsAPI, brandsAPI } from '../services/api';
import { toast } from '../utils/toast';
import { confirmAction } from '../utils/confirm';
import { useAuth } from '../context/AuthContext';

const formatMoney = (n) =>
  '₱' + (Number(n) || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const SetManagementPage = () => {
  const { can } = useAuth();
  const canCreate = typeof can === 'function' && can('product_set_management', 'create');
  const canUpdate = typeof can === 'function' && can('product_set_management', 'update');
  const canArchive = typeof can === 'function' && can('product_set_management', 'archive');

  // 'active' is the backend default listing (excludes soft-deleted sets);
  // 'archived' is a distinct soft-deleted-only view with its own endpoint.
  // is_active === false is a THIRD, unrelated state (still live, just not
  // suggested) — that one is shown inline on the active view as "Inactive".
  const [view, setView] = useState('active');
  const [sets, setSets] = useState([]);
  const [brands, setBrands] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [search, setSearch] = useState('');

  const [builderOpen, setBuilderOpen] = useState(false);
  const [editingSet, setEditingSet] = useState(null); // null = create; set object = edit/duplicate

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const params = {};
      if (search.trim()) params.search = search.trim();
      const res = view === 'archived'
        ? await productSetsAPI.getArchived(params)
        : await productSetsAPI.getAll(params); // backend default: active_only omitted = excludes archived
      setSets(Array.isArray(res?.data) ? res.data : []);
      setError(null);
    } catch (err) {
      setError(err.message || 'Failed to load sets.');
      setSets([]);
    } finally {
      setLoading(false);
    }
  }, [search, view]);

  useEffect(() => {
    brandsAPI.getAll()
      .then((r) => setBrands(Array.isArray(r?.data) ? r.data : []))
      .catch(() => setBrands([]));
  }, []);

  useEffect(() => {
    const t = window.setTimeout(load, search ? 300 : 0);
    return () => window.clearTimeout(t);
  }, [load, search]);

  const openCreate = () => {
    setEditingSet(null);
    setBuilderOpen(true);
  };

  const openEdit = (set) => {
    setEditingSet(set);
    setBuilderOpen(true);
  };

  const openDuplicate = (set) => {
    // Pre-fill the builder from this set MINUS its id so it saves as a new set.
    setEditingSet({
      ...set,
      set_id: undefined,
    });
    setBuilderOpen(true);
  };

  const handleArchive = async (set) => {
    const confirmed = await confirmAction({
      title: `Archive "${set.label}"?`,
      text: 'This hides the set from issuance authoring and the active list. It is not permanent — it can be restored from the Archived tab.',
      confirmText: 'Archive',
      tone: 'danger',
    });
    if (!confirmed) return;
    try {
      await productSetsAPI.archive(set.set_id);
      toast.success(`Set "${set.label}" archived.`);
      load();
    } catch (err) {
      toast.error(err.message || 'Failed to archive set.');
    }
  };

  const handleRestore = async (set) => {
    try {
      await productSetsAPI.restore(set.set_id);
      toast.success(`Set "${set.label}" restored.`);
      load();
    } catch (err) {
      toast.error(err.message || 'Failed to restore set.');
    }
  };

  const closeBuilder = () => {
    setBuilderOpen(false);
    setEditingSet(null);
  };

  const handleSaved = () => {
    closeBuilder();
    load();
  };

  return (
    <AdminLayout>
      <div className="ak-page">
        <header className="ak-header">
          <div className="ak-header-row">
            <div>
              <p className="ak-eyebrow">Catalog · BOQ Templates</p>
              <h1 className="ak-title">Set Management</h1>
              <p className="ak-sub">
                Standard installation material bills of quantity (BOQ) for each aircon brand, capacity, and variant.
              </p>
            </div>
            {canCreate && view === 'active' && (
              <div className="ak-header-aside">
                <button type="button" className="ak-btn ak-btn--primary" onClick={openCreate}>+ New Set</button>
              </div>
            )}
          </div>
        </header>

        <div className="ak-tabs">
          <button
            type="button"
            className={`ak-tab${view === 'active' ? ' is-active' : ''}`}
            onClick={() => setView('active')}
          >
            Sets
          </button>
          {canArchive && (
            <button
              type="button"
              className={`ak-tab${view === 'archived' ? ' is-active' : ''}`}
              onClick={() => setView('archived')}
            >
              Archived
            </button>
          )}
        </div>

        <div className="ak-toolbar">
          <div className="ak-search">
            <input
              className="ak-input"
              placeholder="Search by capacity or variant…"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
          </div>
        </div>

        {loading ? (
          <Loader label="Loading sets" />
        ) : error ? (
          <div className="ak-state ak-state--error">{error}</div>
        ) : sets.length === 0 ? (
          <div className="ak-state">{view === 'archived' ? 'No archived sets.' : 'No sets found.'}</div>
        ) : (
          <div className="ak-table-wrap">
            <table className="ak-table">
              <thead>
                <tr>
                  <th>Brands</th>
                  <th>Capacity</th>
                  <th>Type</th>
                  <th>Lines</th>
                  <th>Set price</th>
                  {view === 'active' && <th>Status</th>}
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {sets.map((s) => (
                  <tr key={s.set_id}>
                    <td>
                      {Array.isArray(s.brands) && s.brands.length > 0 ? (
                        <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.3rem' }}>
                          {s.brands.map((b) => (
                            <span key={b.brand_id} className="ak-badge ak-badge--blue">{b.brand_name}</span>
                          ))}
                        </div>
                      ) : s.brand_names ? (
                        <span className="ak-badge ak-badge--blue">{s.brand_names}</span>
                      ) : (
                        <span className="ak-muted">Any</span>
                      )}
                    </td>
                    <td>{s.capacity_rating ? <span className="ak-badge ak-badge--gray">{s.capacity_rating}</span> : <span className="ak-muted">Any</span>}</td>
                    <td>{s.variant ? <span className="ak-badge ak-badge--violet">{s.variant}</span> : <span className="ak-muted">Any</span>}</td>
                    <td>{s.lines?.length ?? 0}</td>
                    <td className="ak-mono">{formatMoney(s.set_price)}</td>
                    {view === 'active' && (
                      <td>
                        <span className={`ak-badge ${s.is_active ? 'ak-badge--green' : 'ak-badge--amber'}`}>
                          {s.is_active ? 'Active' : 'Inactive'}
                        </span>
                      </td>
                    )}
                    <td>
                      <div style={{ display: 'flex', gap: '0.4rem', justifyContent: 'flex-end' }}>
                        {view === 'archived' ? (
                          canArchive && (
                            <button type="button" className="ak-btn ak-btn--sm" onClick={() => handleRestore(s)}>Restore</button>
                          )
                        ) : (
                          <>
                            {canUpdate && (
                              <button type="button" className="ak-btn ak-btn--sm" onClick={() => openEdit(s)}>Edit</button>
                            )}
                            {canCreate && (
                              <button type="button" className="ak-btn ak-btn--sm" onClick={() => openDuplicate(s)}>Duplicate</button>
                            )}
                            {canArchive && (
                              <button type="button" className="ak-btn ak-btn--sm ak-btn--danger" onClick={() => handleArchive(s)}>Archive</button>
                            )}
                          </>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <SetBuilderModal
        isOpen={builderOpen}
        onClose={closeBuilder}
        initialSet={editingSet}
        brands={brands}
        onSaved={handleSaved}
        canSubmit={editingSet?.set_id ? canUpdate : canCreate}
      />
    </AdminLayout>
  );
};

export default SetManagementPage;
