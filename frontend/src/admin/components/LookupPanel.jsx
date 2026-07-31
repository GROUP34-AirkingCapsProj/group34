import React, { useCallback, useEffect, useState } from 'react';
import Modal from './Modal';
import Loader from './Loader';
import { toast } from '../utils/toast';
import { confirmAction } from '../utils/confirm';

/**
 * LookupPanel — generic CRUD surface for one entry of LOOKUP_CONFIGS.
 *
 * Renders list (active/archived), search, add/edit modal, archive/restore,
 * and (when config.supportsMerge) a rename-collision -> merge offer, purely
 * from the config's `fields` / `columns` — no lookup-specific branching.
 *
 * Props:
 *   config       {object}  one entry from LOOKUP_CONFIGS
 *   categories   {array}   for the `category` field type + `category_name` column
 *   units        {array}   for the `unit` field type + `unit_name` column
 *   permissions  {canCreate, canUpdate, canArchive}
 */
const formatMoney = (n) =>
  '₱' + (Number(n) || 0).toLocaleString('en-PH', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });

// decimal(10,6)-sourced numeric strings ("1.000000") trimmed for display.
const formatDecimal = (v) => {
  const num = Number(v);
  if (Number.isNaN(num)) return String(v);
  return num.toString();
};

const isBlank = (v) => v === '' || v === null || v === undefined;

const buildEmptyForm = (config) => {
  const obj = {};
  config.fields.forEach((f) => {
    if (f.type === 'checkbox') {
      obj[f.name] = false;
    } else if (Object.prototype.hasOwnProperty.call(f, 'default')) {
      obj[f.name] = f.default;
    } else {
      obj[f.name] = '';
    }
  });
  return obj;
};

const buildFormFromRow = (config, row) => {
  const obj = {};
  config.fields.forEach((f) => {
    const v = row[f.name];
    if (f.type === 'checkbox') {
      obj[f.name] = !!v;
    } else if (v === null || v === undefined) {
      obj[f.name] = '';
    } else {
      obj[f.name] = v;
    }
  });
  return obj;
};

const buildPayload = (config, form) => {
  const payload = {};
  config.fields.forEach((f) => {
    const raw = form[f.name];
    if (f.omitWhenBlank && isBlank(raw)) {
      // Leave the key out entirely — sending '' becomes a present `null` and
      // fails `sometimes|integer` validation server-side.
      return;
    }
    if (f.type === 'number' || f.type === 'category' || f.type === 'unit') {
      payload[f.name] = isBlank(raw) ? null : Number(raw);
    } else if (f.type === 'checkbox') {
      payload[f.name] = !!raw;
    } else {
      payload[f.name] = raw;
    }
  });
  return payload;
};

export default function LookupPanel({ config, categories = [], units = [], permissions = {} }) {
  const { canCreate = false, canUpdate = false, canArchive = false } = permissions;

  const [activeRows, setActiveRows] = useState([]);
  const [archivedRows, setArchivedRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const [search, setSearch] = useState('');
  const [showArchived, setShowArchived] = useState(false);

  const [modalOpen, setModalOpen] = useState(false);
  const [editingRow, setEditingRow] = useState(null);
  const [form, setForm] = useState(() => buildEmptyForm(config));
  const [submitting, setSubmitting] = useState(false);

  // `isCancelled` lets a caller opt this fetch out of updating state once it
  // resolves. Manual refreshes (after create/update/archive/restore/merge)
  // call fetchRows() with no argument, so they always default to "never
  // cancelled" and behave exactly as before.
  const fetchRows = useCallback(async (isCancelled = () => false) => {
    try {
      setLoading(true);
      const [activeRes, archivedRes] = await Promise.all([
        config.api.getAll(),
        config.api.getArchived(),
      ]);
      if (isCancelled()) return;
      setActiveRows(Array.isArray(activeRes?.data) ? activeRes.data : []);
      setArchivedRows(Array.isArray(archivedRes?.data) ? archivedRes.data : []);
      setError(null);
    } catch (err) {
      if (isCancelled()) return;
      setError(err.message || 'Failed to load data.');
      setActiveRows([]);
      setArchivedRows([]);
    } finally {
      if (!isCancelled()) setLoading(false);
    }
  }, [config]);

  // Reset transient UI and reload whenever the lookup being shown changes.
  //
  // Guarded against stale in-flight requests: fetchRows is re-invoked on
  // every `config` change, but nothing stops an earlier request from
  // resolving after a later one. E.g. the user clicks Categories then
  // quickly Brands — if the Categories response lands last, an unguarded
  // setActiveRows would put Category rows into state while `config` is
  // already Brands, so the table would render Brands columns over Category
  // rows, and clicking Edit would call config.api.update(row[config.idField], ...)
  // with a Brands idField ('brand_id') against a Category row that doesn't
  // have one — PUT /api/brands/undefined, or a genuinely wrong record if the
  // shapes happen to overlap. The `cancelled` flag below skips every
  // setActiveRows/setArchivedRows/setError/setLoading call belonging to a
  // superseded request. Do not remove this guard to "simplify" the effect.
  useEffect(() => {
    let cancelled = false;
    setSearch('');
    setShowArchived(false);
    setModalOpen(false);
    setEditingRow(null);
    fetchRows(() => cancelled);
    return () => {
      cancelled = true;
    };
  }, [config, fetchRows]);

  const openAdd = () => {
    setEditingRow(null);
    setForm(buildEmptyForm(config));
    setModalOpen(true);
  };

  const openEdit = (row) => {
    setEditingRow(row);
    setForm(buildFormFromRow(config, row));
    setModalOpen(true);
  };

  const setField = (name, value) => setForm((prev) => ({ ...prev, [name]: value }));

  const handleSubmit = async (e) => {
    e.preventDefault();

    const missingRequired = config.fields.find(
      (f) => f.required && f.type !== 'checkbox' && isBlank(form[f.name])
    );
    if (missingRequired) {
      toast.error(`${missingRequired.label} is required.`);
      return;
    }

    const payload = buildPayload(config, form);
    setSubmitting(true);
    try {
      if (editingRow) {
        await config.api.update(editingRow[config.idField], payload);
        toast.success(`${config.singular} updated.`);
      } else {
        await config.api.create(payload);
        toast.success(`${config.singular} added.`);
      }
      setModalOpen(false);
      fetchRows();
    } catch (err) {
      const fieldError = err.errors?.[config.nameField]?.[0];

      if (editingRow && config.supportsMerge && fieldError) {
        const newName = payload[config.nameField];
        const match = activeRows.find(
          (r) =>
            r[config.idField] !== editingRow[config.idField] &&
            String(r[config.nameField]).trim().toLowerCase() ===
              String(newName).trim().toLowerCase()
        );
        if (match) {
          const count = editingRow.usage_count ?? 0;
          const proceed = await confirmAction({
            title: `Merge into "${match[config.nameField]}"?`,
            text: `"${editingRow[config.nameField]}" already matches an existing ${config.singular.toLowerCase()}. Merging moves ${count} item${count === 1 ? '' : 's'} onto "${match[config.nameField]}" and archives "${editingRow[config.nameField]}". This cannot be undone.`,
            confirmText: 'Merge',
            tone: 'danger',
          });
          if (proceed) {
            try {
              await config.api.merge(editingRow[config.idField], match[config.idField]);
              toast.success(`Merged into "${match[config.nameField]}".`);
              setModalOpen(false);
              fetchRows();
            } catch (mergeErr) {
              toast.error(mergeErr.message || 'Merge failed.');
            }
          }
          setSubmitting(false);
          return;
        }
      }

      toast.error(fieldError || err.message || `Failed to save ${config.singular.toLowerCase()}.`);
    } finally {
      setSubmitting(false);
    }
  };

  const handleArchive = async (row) => {
    const count = row.usage_count ?? 0;
    const confirmed = await confirmAction({
      title: `Archive "${row[config.nameField]}"?`,
      text:
        count > 0
          ? `Used by ${count} item${count === 1 ? '' : 's'} — they keep this value, but it will no longer be selectable for new items.`
          : undefined,
      confirmText: 'Archive',
      tone: 'danger',
    });
    if (!confirmed) return;
    try {
      const res = await config.api.delete(row[config.idField]);
      const n = res?.data?.usage_count ?? count;
      toast.success(
        `${config.singular} archived.`,
        n > 0 ? `Used by ${n} item${n === 1 ? '' : 's'}.` : undefined
      );
      fetchRows();
    } catch (err) {
      toast.error(err.message || `Failed to archive ${config.singular.toLowerCase()}.`);
    }
  };

  const handleRestore = async (row) => {
    const confirmed = await confirmAction({
      title: `Restore "${row[config.nameField]}"?`,
      confirmText: 'Restore',
      tone: 'primary',
    });
    if (!confirmed) return;
    try {
      await config.api.restore(row[config.idField]);
      toast.success(`${config.singular} restored.`);
      fetchRows();
    } catch (err) {
      toast.error(err.message || `Failed to restore ${config.singular.toLowerCase()}.`);
    }
  };

  const renderField = (f) => {
    const value = form[f.name];
    switch (f.type) {
      case 'text':
        return (
          <input
            type="text"
            className="ak-input"
            value={value}
            maxLength={f.maxLength}
            placeholder={f.placeholder}
            required={!!f.required}
            onChange={(e) => setField(f.name, e.target.value)}
          />
        );
      case 'textarea':
        return (
          <textarea
            className="ak-input"
            rows={3}
            value={value}
            placeholder={f.placeholder}
            onChange={(e) => setField(f.name, e.target.value)}
          />
        );
      case 'number':
        return (
          <input
            type="number"
            className="ak-input"
            value={value}
            min={f.min}
            max={f.max}
            step={f.step}
            placeholder={f.placeholder}
            required={!!f.required}
            onChange={(e) => setField(f.name, e.target.value)}
          />
        );
      case 'checkbox':
        return (
          <label className="lk-checkbox-field">
            <input
              type="checkbox"
              checked={!!value}
              onChange={(e) => setField(f.name, e.target.checked)}
            />
            <span>{f.label}</span>
          </label>
        );
      case 'select':
        return (
          <select
            className="ak-select"
            value={value}
            required={!!f.required}
            onChange={(e) => setField(f.name, e.target.value)}
          >
            {f.options.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </select>
        );
      case 'category':
        return (
          <select
            className="ak-select"
            value={value}
            required={!!f.required}
            onChange={(e) => setField(f.name, e.target.value)}
          >
            {f.allowEmpty && <option value="">All categories</option>}
            {categories.map((c) => (
              <option key={c.category_id} value={c.category_id}>
                {c.category_name}
              </option>
            ))}
          </select>
        );
      case 'unit':
        return (
          <select
            className="ak-select"
            value={value}
            required={!!f.required}
            onChange={(e) => setField(f.name, e.target.value)}
          >
            <option value="">Select unit…</option>
            {units.map((u) => (
              <option key={u.unit_id} value={u.unit_id}>
                {u.unit_name}
                {u.unit_abbreviation ? ` (${u.unit_abbreviation})` : ''}
              </option>
            ))}
          </select>
        );
      default:
        return null;
    }
  };

  const renderCell = (row, col) => {
    if (col.key === 'category_name') {
      if (row.category_id === null || row.category_id === undefined) return 'All categories';
      const match = categories.find(
        (c) => String(c.category_id) === String(row.category_id)
      );
      return match ? match.category_name : '—';
    }
    if (col.key === 'unit_name') {
      return row.unit?.unit_name || '—';
    }

    const raw = row[col.key];
    if (raw === undefined || raw === null || raw === '') return '—';
    if (col.format === 'bool') return raw ? 'Yes' : 'No';
    if (col.format === 'currency') return formatMoney(raw);
    if (col.format === 'decimal') return formatDecimal(raw);
    return String(raw);
  };

  // usage_count is rendered via the dedicated "Used by" column below;
  // config.columns must never list it (see lookupConfigs.js header comment).
  const displayColumns = config.columns;

  const rows = showArchived ? archivedRows : activeRows;
  const filteredRows = rows.filter((r) => {
    if (!search.trim()) return true;
    const name = r[config.nameField];
    return String(name || '').toLowerCase().includes(search.trim().toLowerCase());
  });

  const liveCount = showArchived ? archivedRows.length : activeRows.length;

  return (
    <section className="ak-card lk-panel">
      <div className="ak-card-head">
        <div>
          <h2 className="ak-card-title">{config.label}</h2>
          <p className="ak-card-meta">
            {liveCount} {showArchived ? 'archived' : 'active'} {config.label.toLowerCase()}
          </p>
        </div>
        {canCreate && (
          <button type="button" className="ak-btn ak-btn--primary" onClick={openAdd}>
            + Add {config.singular}
          </button>
        )}
      </div>

      <div className="ak-toolbar lk-toolbar">
        <div className="ak-search">
          <input
            type="text"
            className="ak-input"
            placeholder={`Search ${config.label.toLowerCase()}...`}
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
        </div>
        <label className="lk-archived-toggle">
          <input
            type="checkbox"
            checked={showArchived}
            onChange={(e) => setShowArchived(e.target.checked)}
          />
          <span>Show archived{archivedRows.length > 0 ? ` (${archivedRows.length})` : ''}</span>
        </label>
      </div>

      {loading && <Loader />}
      {!loading && error && <div className="lk-error">Error: {error}</div>}

      {!loading && !error && (
        <div className="ak-table-wrap">
          <table className="ak-table">
            <thead>
              <tr>
                {displayColumns.map((col) => (
                  <th key={col.key} scope="col">
                    {col.label}
                  </th>
                ))}
                <th scope="col">Used by</th>
                <th scope="col">Actions</th>
              </tr>
            </thead>
            <tbody>
              {filteredRows.length === 0 ? (
                <tr>
                  <td colSpan={displayColumns.length + 2} className="lk-empty">
                    {rows.length === 0
                      ? showArchived
                        ? `No archived ${config.label.toLowerCase()} yet.`
                        : `No ${config.label.toLowerCase()} yet.`
                      : `No results for "${search}".`}
                  </td>
                </tr>
              ) : (
                filteredRows.map((row) => (
                  <tr key={row[config.idField]}>
                    {displayColumns.map((col) => (
                      <td key={col.key}>{renderCell(row, col)}</td>
                    ))}
                    <td>{row.usage_count ?? 0}</td>
                    <td className="lk-actions-cell">
                      {showArchived ? (
                        canArchive && (
                          <button
                            type="button"
                            className="ak-btn ak-btn--sm ak-btn--primary"
                            onClick={() => handleRestore(row)}
                          >
                            Restore
                          </button>
                        )
                      ) : (
                        <>
                          {canUpdate && (
                            <button
                              type="button"
                              className="ak-btn ak-btn--sm ak-btn--ghost"
                              onClick={() => openEdit(row)}
                            >
                              Edit
                            </button>
                          )}
                          {canArchive && (
                            <button
                              type="button"
                              className="ak-btn ak-btn--sm ak-btn--danger"
                              onClick={() => handleArchive(row)}
                            >
                              Archive
                            </button>
                          )}
                        </>
                      )}
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      )}

      <Modal
        isOpen={modalOpen}
        title={editingRow ? `Edit ${config.singular}` : `Add ${config.singular}`}
        onClose={() => setModalOpen(false)}
      >
        <form onSubmit={handleSubmit} className="lk-form">
          {config.fields.map((f) => (
            <div key={f.name} className="ak-field">
              {f.type !== 'checkbox' && <label className="ak-label">{f.label}</label>}
              {renderField(f)}
              {f.hint && <p className="lk-field-hint">{f.hint}</p>}
            </div>
          ))}
          <div className="lk-form-actions">
            <button
              type="button"
              className="ak-btn ak-btn--ghost"
              onClick={() => setModalOpen(false)}
            >
              Cancel
            </button>
            <button type="submit" className="ak-btn ak-btn--primary" disabled={submitting}>
              {submitting ? 'Saving...' : editingRow ? 'Update' : `Add ${config.singular}`}
            </button>
          </div>
        </form>
      </Modal>
    </section>
  );
}
