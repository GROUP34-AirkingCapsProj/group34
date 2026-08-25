import React, { useEffect, useState, useCallback } from 'react';
import AdminLayout from '../components/AdminLayout';
import Loader from '../components/Loader';
import AirconModelBuilderModal from '../components/AirconModelBuilderModal';
import { airconModelsAPI, brandsAPI } from '../services/api';
import { toast } from '../utils/toast';
import { confirmAction } from '../utils/confirm';
import { useAuth } from '../context/AuthContext';

const formatMoney = (n) =>
  '₱' + (Number(n) || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const AirconModelsPage = () => {
  const { can } = useAuth();
  const canRead = typeof can === 'function' && can('aircon_model_management', 'read');
  const canCreate = typeof can === 'function' && can('aircon_model_management', 'create');
  const canUpdate = typeof can === 'function' && can('aircon_model_management', 'update');
  const canArchive = typeof can === 'function' && can('aircon_model_management', 'archive');

  // 'active' is the backend default listing (excludes soft-deleted models);
  // 'archived' is a distinct soft-deleted-only view with its own endpoint.
  // is_active === false is a THIRD, unrelated state (still live, just not
  // offered) — that one is shown inline on the active view as "Inactive".
  const [view, setView] = useState('active');
  const [models, setModels] = useState([]);
  const [brands, setBrands] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [search, setSearch] = useState('');

  const [builderOpen, setBuilderOpen] = useState(false);
  const [editingModel, setEditingModel] = useState(null); // null = create; object = edit

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const params = {};
      if (search.trim()) params.search = search.trim();
      const res = view === 'archived'
        ? await airconModelsAPI.getArchived(params)
        // active_only defaults to true server-side; ask for false so an
        // is_active=false model is still reachable for editing here. Archived
        // rows stay hidden regardless — that is the SoftDeletes scope, a
        // separate axis.
        : await airconModelsAPI.getAll({ ...params, active_only: false });
      setModels(Array.isArray(res?.data) ? res.data : []);
      setError(null);
    } catch (err) {
      setError(err.message || 'Failed to load aircon models.');
      setModels([]);
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
    setEditingModel(null);
    setBuilderOpen(true);
  };

  const openEdit = (model) => {
    setEditingModel(model);
    setBuilderOpen(true);
  };

  const handleArchive = async (model) => {
    const confirmed = await confirmAction({
      title: `Archive "${model.model_name}"?`,
      text: 'This hides the model from the active catalogue. It is not permanent — it can be restored from the Archived tab.',
      confirmText: 'Archive',
      tone: 'danger',
    });
    if (!confirmed) return;
    try {
      await airconModelsAPI.delete(model.aircon_model_id);
      toast.success(`Aircon model "${model.model_name}" archived.`);
      load();
    } catch (err) {
      toast.error(err.message || 'Failed to archive the aircon model.');
    }
  };

  const handleRestore = async (model) => {
    try {
      await airconModelsAPI.restore(model.aircon_model_id);
      toast.success(`Aircon model "${model.model_name}" restored.`);
      load();
    } catch (err) {
      toast.error(err.message || 'Failed to restore the aircon model.');
    }
  };

  const closeBuilder = () => {
    setBuilderOpen(false);
    setEditingModel(null);
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
              <p className="ak-eyebrow">Catalog · Aircon Models</p>
              <h1 className="ak-title">Aircon Models</h1>
              <p className="ak-sub">
                The sellable machines — each one a brand, AC type, capacity and variant, composed from the
                appliance units that physically make it up.
              </p>
            </div>
            {canCreate && view === 'active' && (
              <div className="ak-header-aside">
                <button type="button" className="ak-btn ak-btn--primary" onClick={openCreate}>+ New Model</button>
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
            Models
          </button>
          {/* Gated on read, not archive: the backend's /aircon-models/archived
              is deliberately a read-gated route so an auditor without archive
              rights can still see what was taken out of the catalogue. The
              Restore button inside the tab is what needs `archive`. */}
          {canRead && (
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
              placeholder="Search by model name, capacity or variant…"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
          </div>
        </div>

        {loading ? (
          <Loader label="Loading aircon models" />
        ) : error ? (
          <div className="ak-state ak-state--error">{error}</div>
        ) : models.length === 0 ? (
          <div className="ak-state">{view === 'archived' ? 'No archived aircon models.' : 'No aircon models found.'}</div>
        ) : (
          <div className="ak-table-wrap">
            <table className="ak-table">
              <thead>
                <tr>
                  <th>Model name</th>
                  <th>Brand</th>
                  <th>AC type</th>
                  <th>Capacity</th>
                  <th>Variant</th>
                  <th>Pieces</th>
                  <th>Units</th>
                  <th>Total price</th>
                  {view === 'active' && <th>Status</th>}
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {models.map((m) => (
                  <tr key={m.aircon_model_id}>
                    <td>{m.model_name}</td>
                    <td>
                      {m.brand_name
                        ? <span className="ak-badge ak-badge--blue">{m.brand_name}</span>
                        : <span className="ak-muted">Any</span>}
                    </td>
                    <td>
                      {m.ac_type_name
                        ? <span className="ak-badge ak-badge--violet">{m.ac_type_name}</span>
                        : <span className="ak-muted">Any</span>}
                    </td>
                    <td>
                      {m.capacity_rating
                        ? <span className="ak-badge ak-badge--gray">{m.capacity_rating}</span>
                        : <span className="ak-muted">Any</span>}
                    </td>
                    <td>{m.variant || <span className="ak-muted">Any</span>}</td>
                    <td>{m.is_two_piece ? 'Two-piece' : 'One-piece'}</td>
                    <td>{m.units?.length ?? 0}</td>
                    <td className="ak-mono">{formatMoney(m.total_price)}</td>
                    {view === 'active' && (
                      <td>
                        <span className={`ak-badge ${m.is_active ? 'ak-badge--green' : 'ak-badge--amber'}`}>
                          {m.is_active ? 'Active' : 'Inactive'}
                        </span>
                      </td>
                    )}
                    <td>
                      <div style={{ display: 'flex', gap: '0.4rem', justifyContent: 'flex-end' }}>
                        {view === 'archived' ? (
                          canArchive && (
                            <button type="button" className="ak-btn ak-btn--sm" onClick={() => handleRestore(m)}>Restore</button>
                          )
                        ) : (
                          <>
                            {canUpdate && (
                              <button type="button" className="ak-btn ak-btn--sm" onClick={() => openEdit(m)}>Edit</button>
                            )}
                            {canArchive && (
                              <button type="button" className="ak-btn ak-btn--sm ak-btn--danger" onClick={() => handleArchive(m)}>Archive</button>
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

      <AirconModelBuilderModal
        isOpen={builderOpen}
        onClose={closeBuilder}
        initialModel={editingModel}
        brands={brands}
        onSaved={handleSaved}
        canSubmit={editingModel?.aircon_model_id ? canUpdate : canCreate}
      />
    </AdminLayout>
  );
};

export default AirconModelsPage;
