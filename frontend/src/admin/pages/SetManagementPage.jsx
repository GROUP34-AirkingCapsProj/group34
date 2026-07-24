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
      const params = { active_only: 0 };
      if (search.trim()) params.search = search.trim();
      const res = await productSetsAPI.getAll(params);
      setSets(Array.isArray(res?.data) ? res.data : []);
      setError(null);
    } catch (err) {
      setError(err.message || 'Failed to load sets.');
      setSets([]);
    } finally {
      setLoading(false);
    }
  }, [search]);

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
    setEditingSet({
      ...set,
      set_id: undefined,
      set_code: '',
      set_name: `${set.set_name} (copy)`,
    });
    setBuilderOpen(true);
  };

  const handleArchive = async (set) => {
    const confirmed = await confirmAction({
      title: `Archive "${set.set_name}"?`,
      text: 'This set will no longer be offered when authoring issuances. It can be restored by re-creating it if needed.',
      confirmText: 'Archive',
      tone: 'danger',
    });
    if (!confirmed) return;
    try {
      await productSetsAPI.archive(set.set_id);
      toast.success(`Set "${set.set_name}" archived.`);
      load();
    } catch (err) {
      toast.error(err.message || 'Failed to archive set.');
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
            {canCreate && (
              <div className="ak-header-aside">
                <button type="button" className="ak-btn ak-btn--primary" onClick={openCreate}>+ New Set</button>
              </div>
            )}
          </div>
        </header>

        <div className="ak-toolbar">
          <div className="ak-search">
            <input
              className="ak-input"
              placeholder="Search set code or name…"
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
          <div className="ak-state">No sets found.</div>
        ) : (
          <div className="ak-table-wrap">
            <table className="ak-table">
              <thead>
                <tr>
                  <th>Code</th>
                  <th>Name</th>
                  <th>Brand</th>
                  <th>Capacity</th>
                  <th>Variant</th>
                  <th>Lines</th>
                  <th>Set price</th>
                  <th>Status</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {sets.map((s) => (
                  <tr key={s.set_id}>
                    <td className="ak-mono">{s.set_code}</td>
                    <td>{s.set_name}</td>
                    <td>{s.brand_name ? <span className="ak-badge ak-badge--blue">{s.brand_name}</span> : <span className="ak-muted">Any</span>}</td>
                    <td>{s.capacity_rating ? <span className="ak-badge ak-badge--gray">{s.capacity_rating}</span> : <span className="ak-muted">Any</span>}</td>
                    <td>{s.variant ? <span className="ak-badge ak-badge--violet">{s.variant}</span> : <span className="ak-muted">Any</span>}</td>
                    <td>{(s.lines || []).length}</td>
                    <td className="ak-mono">{formatMoney(s.set_price)}</td>
                    <td>
                      <span className={`ak-badge ${s.is_active ? 'ak-badge--green' : 'ak-badge--gray'}`}>
                        {s.is_active ? 'Active' : 'Archived'}
                      </span>
                    </td>
                    <td>
                      <div style={{ display: 'flex', gap: '0.4rem', justifyContent: 'flex-end' }}>
                        {canUpdate && (
                          <button type="button" className="ak-btn ak-btn--sm" onClick={() => openEdit(s)}>Edit</button>
                        )}
                        {canCreate && (
                          <button type="button" className="ak-btn ak-btn--sm" onClick={() => openDuplicate(s)}>Duplicate</button>
                        )}
                        {canArchive && s.is_active && (
                          <button type="button" className="ak-btn ak-btn--sm ak-btn--danger" onClick={() => handleArchive(s)}>Archive</button>
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
