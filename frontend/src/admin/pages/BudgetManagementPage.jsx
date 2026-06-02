import React, { useState, useEffect, useCallback, useMemo } from 'react';
import AdminLayout from '../components/AdminLayout';
import Modal from '../components/Modal';
import ArchiveButton from '../components/ArchiveButton';
import {
  budgetAllocationsAPI,
  locationsAPI,
  categoriesAPI,
} from '../services/api';
import { useAuth } from '../context/AuthContext';
import { getRoleKey, ROLES } from '../utils/roles';
import '../styles/dashboard_air.css';
import '../styles/po_recommendation.css';
import '../styles/archive_management.css';
import DateInput from '../components/DateInput';

// ── Helpers ───────────────────────────────────────────────────────────────────

const fmtPeso = (n) =>
  `₱${Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const isoToMDY = (s) => {
  if (!s) return '';
  const [y, m, day] = String(s).slice(0, 10).split('-');
  return `${m}/${day}/${y}`;
};

const toLocalDate = (d) => {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
};

const today = () => toLocalDate(new Date());

const healthInfo = (available, total) => {
  if (!total || total <= 0) return { key: 'exhausted', label: 'EXHAUSTED', pct: 0 };
  const pct = available / total;
  if (available <= 0) return { key: 'exhausted', label: 'EXHAUSTED', pct: 0 };
  if (pct > 0.5)  return { key: 'healthy',   label: 'HEALTHY',   pct };
  if (pct > 0.10) return { key: 'moderate',  label: 'MODERATE',  pct };
  return              { key: 'low',       label: 'LOW',      pct };
};

const periodStatus = (start, end) => {
  const now = today();
  if (now < start) return { key: 'upcoming', label: 'UPCOMING' };
  if (now > end)   return { key: 'expired',  label: 'EXPIRED'  };
  return               { key: 'active',   label: 'ACTIVE'   };
};

const availColor = (key) => {
  if (key === 'healthy')   return 'budget-grid-value--available-green';
  if (key === 'moderate')  return 'budget-grid-value--available-amber';
  return 'budget-grid-value--available-red';
};

// ── Quick period helpers ──────────────────────────────────────────────────────

const getQuarterOf = (d) => Math.floor(d.getMonth() / 3);

const quarterDates = (year, q) => {
  const starts = [0, 3, 6, 9];
  const ends   = [2, 5, 8, 11];
  const start  = new Date(year, starts[q], 1);
  const endMonth = ends[q];
  const end    = new Date(year, endMonth + 1, 0);
  return {
    label: `${year}-Q${q + 1}`,
    start: toLocalDate(start),
    end:   toLocalDate(end),
  };
};

const quickPeriods = () => {
  const now   = new Date();
  const y     = now.getFullYear();
  const m     = now.getMonth();
  const q     = getQuarterOf(now);
  const pad   = (n) => String(n).padStart(2, '0');

  const monthStart = `${y}-${pad(m + 1)}-01`;
  const monthEnd   = toLocalDate(new Date(y, m + 1, 0));
  const monthNames = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

  const nextM = m + 1 > 11 ? 0 : m + 1;
  const nextY = m + 1 > 11 ? y + 1 : y;
  const nextMonthStart = `${nextY}-${pad(nextM + 1)}-01`;
  const nextMonthEnd   = toLocalDate(new Date(nextY, nextM + 1, 0));

  const thisQ = quarterDates(y, q);
  const nextQ = q + 1 > 3 ? quarterDates(y + 1, 0) : quarterDates(y, q + 1);

  return [
    { label: 'This Month',    period_label: `${y}-${monthNames[m]}`,    start: monthStart,         end: monthEnd         },
    { label: 'Next Month',    period_label: `${nextY}-${monthNames[nextM]}`, start: nextMonthStart, end: nextMonthEnd     },
    { label: 'This Quarter',  period_label: thisQ.label,                 start: thisQ.start,        end: thisQ.end        },
    { label: 'Next Quarter',  period_label: nextQ.label,                 start: nextQ.start,        end: nextQ.end        },
  ];
};

// ── Default form state ────────────────────────────────────────────────────────

const emptyForm = () => ({
  location_id:   '',
  period_label:  '',
  period_start:  '',
  period_end:    '',
  total_budget:  '',
  category_id:   '',
  notes:         '',
});

// ── Component ─────────────────────────────────────────────────────────────────

const BudgetManagementPage = () => {
  const { user } = useAuth();
  const role     = getRoleKey(user);
  const isAdmin  = role === ROLES.ADMIN;

  const [activeTab,    setActiveTab]    = useState('overview');
  const [budgets,      setBudgets]      = useState([]);
  const [loading,      setLoading]      = useState(true);
  const [loadError,    setLoadError]    = useState('');
  const [locations,    setLocations]    = useState([]);
  const [categories,   setCategories]   = useState([]);

  // Create form
  const [form,         setForm]         = useState(emptyForm);
  const [formErrors,   setFormErrors]   = useState({});
  const [submitting,   setSubmitting]   = useState(false);
  const [createSuccess,setCreateSuccess]= useState('');
  const [createError,  setCreateError]  = useState('');

  // Edit modal
  const [editBudget,   setEditBudget]   = useState(null); // budget object
  const [editForm,     setEditForm]     = useState({});
  const [editErrors,   setEditErrors]   = useState({});
  const [editSaving,   setEditSaving]   = useState(false);
  const [editError,    setEditError]    = useState('');


  // ── Load budgets ────────────────────────────────────────────────────────────
  const loadBudgets = useCallback(async () => {
    setLoading(true);
    setLoadError('');
    try {
      const res  = await budgetAllocationsAPI.getAll({ per_page: 200 });
      const list = Array.isArray(res?.data) ? res.data : [];
      setBudgets(list);
    } catch (err) {
      setLoadError(err?.message || 'Failed to load budgets.');
    } finally {
      setLoading(false);
    }
  }, []);

  // ── Load locations + categories once ───────────────────────────────────────
  useEffect(() => {
    if (!isAdmin) return;
    loadBudgets();
    locationsAPI.getAll({ per_page: 200 })
      .then(res => setLocations(Array.isArray(res?.data) ? res.data : []))
      .catch(() => {});
    categoriesAPI.getAll()
      .then(res => setCategories(Array.isArray(res?.data) ? res.data : []))
      .catch(() => {});
  }, [isAdmin, loadBudgets]);

  // ── Summary totals ──────────────────────────────────────────────────────────
  const summary = useMemo(() => budgets.reduce((acc, b) => ({
    total:     acc.total     + Number(b.total_budget || 0),
    committed: acc.committed + Number(b.committed    || 0),
    spent:     acc.spent     + Number(b.spent        || 0),
  }), { total: 0, committed: 0, spent: 0 }), [budgets]);

  // ── Form helpers ────────────────────────────────────────────────────────────
  const setF  = useCallback((k, v) => setForm(p => ({ ...p, [k]: v })), []);
  const setEF = useCallback((k, v) => setEditForm(p => ({ ...p, [k]: v })), []);

  const applyQuick = useCallback((qp) => {
    setForm(p => ({ ...p, period_label: qp.period_label, period_start: qp.start, period_end: qp.end }));
  }, []);

  const applyQuickEdit = useCallback((qp) => {
    setEditForm(p => ({ ...p, period_label: qp.period_label, period_start: qp.start, period_end: qp.end }));
  }, []);

  const validateForm = (f) => {
    const e = {};
    if (!f.location_id)   e.location_id   = 'Location is required.';
    if (!f.period_label?.trim()) e.period_label = 'Period label is required.';
    if (!f.period_start)  e.period_start  = 'Start date is required.';
    if (!f.period_end)    e.period_end    = 'End date is required.';
    if (f.period_start && f.period_end && f.period_end <= f.period_start)
      e.period_end = 'End date must be after start date.';
    const bNum = parseFloat(f.total_budget);
    if (!f.total_budget || isNaN(bNum) || bNum <= 0)
      e.total_budget = 'Total budget must be greater than 0.';
    return e;
  };

  // ── Create submit ────────────────────────────────────────────────────────────
  const handleCreate = useCallback(async () => {
    const errs = validateForm(form);
    setFormErrors(errs);
    if (Object.keys(errs).length) return;
    setSubmitting(true);
    setCreateError('');
    try {
      const payload = {
        location_id:  Number(form.location_id),
        period_label: form.period_label.trim(),
        period_start: form.period_start,
        period_end:   form.period_end,
        total_budget: parseFloat(form.total_budget),
        ...(form.category_id ? { category_id: Number(form.category_id) } : {}),
        ...(form.notes?.trim() ? { notes: form.notes.trim() } : {}),
      };
      await budgetAllocationsAPI.create(payload);
      const loc = locations.find(l => String(l.location_id) === String(form.location_id));
      setCreateSuccess(`Budget created for ${loc?.location_name || 'location'}!`);
      setForm(emptyForm());
      setFormErrors({});
      await loadBudgets();
      setActiveTab('overview');
    } catch (err) {
      const msg = err?.errors
        ? Object.values(err.errors).flat().join(' ')
        : err?.message || 'Failed to create budget.';
      setCreateError(msg);
    } finally {
      setSubmitting(false);
    }
  }, [form, locations, loadBudgets]);

  // ── Edit open ────────────────────────────────────────────────────────────────
  const openEdit = useCallback((b) => {
    setEditBudget(b);
    setEditForm({
      location_id:  String(b.location_id || ''),
      period_label: b.period_label || '',
      period_start: b.period_start ? String(b.period_start).slice(0, 10) : '',
      period_end:   b.period_end   ? String(b.period_end).slice(0, 10)   : '',
      total_budget: String(b.total_budget || ''),
      category_id:  String(b.category_id || ''),
      notes:        b.notes || '',
    });
    setEditErrors({});
    setEditError('');
  }, []);

  // ── Edit save ────────────────────────────────────────────────────────────────
  const handleEditSave = useCallback(async () => {
    const errs = validateForm(editForm);
    // Extra: cannot reduce below committed + spent
    const minBudget = Number(editBudget?.committed || 0) + Number(editBudget?.spent || 0);
    const bNum = parseFloat(editForm.total_budget);
    if (!isNaN(bNum) && bNum < minBudget) {
      errs.total_budget = `Total budget cannot be less than ${fmtPeso(minBudget)} (already committed or spent).`;
    }
    setEditErrors(errs);
    if (Object.keys(errs).length) return;
    setEditSaving(true);
    setEditError('');
    try {
      const payload = {
        period_label: editForm.period_label.trim(),
        period_start: editForm.period_start,
        period_end:   editForm.period_end,
        total_budget: parseFloat(editForm.total_budget),
        ...(editForm.category_id ? { category_id: Number(editForm.category_id) } : { category_id: null }),
        notes: editForm.notes?.trim() || null,
      };
      await budgetAllocationsAPI.update(editBudget.budget_id, payload);
      setEditBudget(null);
      await loadBudgets();
    } catch (err) {
      const msg = err?.errors
        ? Object.values(err.errors).flat().join(' ')
        : err?.message || 'Failed to update budget.';
      setEditError(msg);
    } finally {
      setEditSaving(false);
    }
  }, [editBudget, editForm, loadBudgets]);

  // ── Archive ──────────────────────────────────────────────────────────────────
  const handleArchive = useCallback(async (budgetId) => {
    try {
      await budgetAllocationsAPI.delete(budgetId);
      await loadBudgets();
    } catch (err) {
      alert(err?.message || 'Failed to delete budget.');
    }
  }, [loadBudgets]);

  // ── Shared form renderer ─────────────────────────────────────────────────────
  const renderFormFields = (f, setFn, errors, isEdit = false) => {
    const committed = isEdit ? Number(editBudget?.committed || 0) : 0;
    const spent     = isEdit ? Number(editBudget?.spent     || 0) : 0;
    const qps       = quickPeriods();

    return (
      <>
        {/* Location */}
        <div className="po-field">
          <label className="po-field-label">
            LOCATION <span style={{ color: '#dc2626' }}>*</span>
          </label>
          {isEdit ? (
            <div className="po-reference-badge">
              {locations.find(l => String(l.location_id) === String(f.location_id))?.location_name || '—'}
              <span style={{ color: '#94a3b8', marginLeft: 6, fontSize: 12 }}>(cannot change)</span>
            </div>
          ) : (
            <select
              className={`po-select${errors.location_id ? ' po-field--error' : ''}`}
              value={f.location_id}
              onChange={e => setFn('location_id', e.target.value)}
            >
              <option value="">Select location…</option>
              {locations.map(l => (
                <option key={l.location_id} value={l.location_id}>
                  {l.location_name}{l.location_type ? ` (${l.location_type})` : ''}
                </option>
              ))}
            </select>
          )}
          {errors.location_id && <p className="po-field-error">{errors.location_id}</p>}
        </div>

        {/* Period Label */}
        <div className="po-field">
          <label className="po-field-label">
            PERIOD LABEL <span style={{ color: '#dc2626' }}>*</span>
          </label>
          <input
            className={`po-input${errors.period_label ? ' po-field--error' : ''}`}
            type="text"
            placeholder="e.g. 2026-Q2 or 2026-May"
            value={f.period_label}
            onChange={e => setFn('period_label', e.target.value)}
          />
          {errors.period_label && <p className="po-field-error">{errors.period_label}</p>}
        </div>

        {/* Dates row */}
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
          <div className="po-field">
            <label className="po-field-label">
              PERIOD START <span style={{ color: '#dc2626' }}>*</span>
            </label>
            <DateInput
              className={`po-input${errors.period_start ? ' po-field--error' : ''}`}
              value={f.period_start}
              onChange={e => setFn('period_start', e.target.value)}
            />
            {errors.period_start && <p className="po-field-error">{errors.period_start}</p>}
          </div>
          <div className="po-field">
            <label className="po-field-label">
              PERIOD END <span style={{ color: '#dc2626' }}>*</span>
            </label>
            <DateInput
              className={`po-input${errors.period_end ? ' po-field--error' : ''}`}
              value={f.period_end}
              min={f.period_start || undefined}
              onChange={e => setFn('period_end', e.target.value)}
            />
            {errors.period_end && <p className="po-field-error">{errors.period_end}</p>}
          </div>
        </div>

        {/* Quick period buttons */}
        <div>
          <label className="po-field-label" style={{ marginBottom: 6 }}>QUICK PERIOD</label>
          <div className="budget-quick-period-btns">
            {qps.map(qp => (
              <button
                key={qp.label}
                type="button"
                className="budget-quick-btn"
                onClick={() => isEdit ? applyQuickEdit(qp) : applyQuick(qp)}
              >
                {qp.label}
              </button>
            ))}
          </div>
        </div>

        {/* Total Budget */}
        <div className="po-field">
          <label className="po-field-label">
            TOTAL BUDGET (₱) <span style={{ color: '#dc2626' }}>*</span>
          </label>
          {isEdit && (committed + spent) > 0 && (
            <div style={{ fontSize: 12, color: '#64748b', marginBottom: 6 }}>
              Already committed/spent:{' '}
              <strong style={{ color: '#1e293b' }}>
                {fmtPeso(committed)} committed + {fmtPeso(spent)} spent = {fmtPeso(committed + spent)}
              </strong>
              {' '}(minimum floor)
            </div>
          )}
          <div style={{ position: 'relative' }}>
            <span style={{ position: 'absolute', left: 12, top: '50%', transform: 'translateY(-50%)', color: '#64748b', fontSize: 14 }}>₱</span>
            <input
              className={`po-input${errors.total_budget ? ' po-field--error' : ''}`}
              type="number"
              min="0"
              step="0.01"
              placeholder="0.00"
              value={f.total_budget}
              onChange={e => setFn('total_budget', e.target.value)}
              style={{ paddingLeft: 28 }}
            />
          </div>
          {errors.total_budget && <p className="po-field-error">{errors.total_budget}</p>}
        </div>

        {/* Category (optional) */}
        <div className="po-field">
          <label className="po-field-label">CATEGORY (OPTIONAL)</label>
          <select
            className="po-select"
            value={f.category_id}
            onChange={e => setFn('category_id', e.target.value)}
          >
            <option value="">All categories</option>
            {categories.map(c => (
              <option key={c.category_id ?? c.id} value={c.category_id ?? c.id}>
                {c.category_name ?? c.name}
              </option>
            ))}
          </select>
        </div>

        {/* Notes */}
        <div className="po-field">
          <label className="po-field-label">NOTES (OPTIONAL)</label>
          <textarea
            className="po-input"
            rows={3}
            placeholder="e.g. Q2 restocking budget for air conditioning units"
            value={f.notes}
            onChange={e => setFn('notes', e.target.value)}
            style={{ resize: 'vertical', minHeight: 72 }}
          />
        </div>
      </>
    );
  };

  // ── Preview card (Create tab) ─────────────────────────────────────────────
  const renderPreview = () => {
    const loc     = locations.find(l => String(l.location_id) === String(form.location_id));
    const bNum    = parseFloat(form.total_budget) || 0;
    const hasData = loc || form.period_label || bNum > 0;
    if (!hasData) return null;

    return (
      <div className="budget-preview-card">
        <div className="budget-preview-title">PREVIEW</div>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
          <span style={{ fontWeight: 700, color: '#1e293b', fontSize: 15 }}>
            {loc?.location_name || '—'}
          </span>
          <span style={{ fontSize: 13, color: '#16a34a', fontWeight: 600 }}>
            {form.period_label || '—'}
          </span>
        </div>
        <div style={{ fontSize: 13, color: '#475569', marginBottom: 4 }}>
          Total Budget: <strong>{fmtPeso(bNum)}</strong>
        </div>
        <div style={{ fontSize: 13, color: '#16a34a' }}>
          Available: <strong>{fmtPeso(bNum)}</strong> (starts at 100%)
        </div>
        {form.period_start && form.period_end && (
          <div style={{ fontSize: 12, color: '#64748b', marginTop: 4 }}>
            Period: {isoToMDY(form.period_start)} → {isoToMDY(form.period_end)}
          </div>
        )}
      </div>
    );
  };

  // ── Budget card ─────────────────────────────────────────────────────────────
  const renderBudgetCard = (b) => {
    const total     = Number(b.total_budget || 0);
    const committed = Number(b.committed    || 0);
    const spent     = Number(b.spent        || 0);
    const available = Number(b.available    ?? (total - committed - spent));
    const health    = healthInfo(available, total);
    const pStatus   = periodStatus(
      b.period_start ? String(b.period_start).slice(0, 10) : '9999-01-01',
      b.period_end   ? String(b.period_end).slice(0, 10)   : '0000-01-01',
    );
    const createdBy = b.created_by
      ? `${b.created_by.first_name || ''} ${b.created_by.last_name || ''}`.trim() || b.created_by.username || '—'
      : '—';
    const fillPct = Math.min(100, (1 - (available / (total || 1))) * 100);

    return (
      <div key={b.budget_id} className="budget-card">
        <div className="budget-card-header">
          <div>
            <div className="budget-card-title">
              {b.location?.location_name || `Location ${b.location_id}`}
            </div>
            <div className="budget-card-period">
              {b.period_label}
              {b.period_start && b.period_end && (
                <span style={{ color: '#94a3b8', marginLeft: 6 }}>
                  ({isoToMDY(b.period_start)} – {isoToMDY(b.period_end)})
                </span>
              )}
            </div>
          </div>
          <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', justifyContent: 'flex-end' }}>
            <span className={`budget-health-badge budget-health-badge--${health.key}`}>
              {health.key === 'low' && '⚠ '}{health.label}
            </span>
            <span className={`budget-period-badge budget-period-badge--${pStatus.key}`}>
              {pStatus.label}
            </span>
          </div>
        </div>

        {/* Progress bar */}
        <div className="budget-progress-bar">
          <div
            className={`budget-progress-fill budget-progress-fill--${health.key === 'exhausted' ? 'low' : health.key}`}
            style={{ width: `${fillPct}%` }}
          />
        </div>
        <div style={{ fontSize: 11, color: '#94a3b8', textAlign: 'right', marginTop: -4, marginBottom: 8 }}>
          {Math.round(fillPct)}% used
        </div>

        {/* Grid */}
        <div className="budget-grid">
          <div className="budget-grid-item">
            <span className="budget-grid-label">TOTAL</span>
            <span className="budget-grid-value">{fmtPeso(total)}</span>
          </div>
          <div className="budget-grid-item">
            <span className="budget-grid-label">COMMITTED</span>
            <span className="budget-grid-value">{fmtPeso(committed)}</span>
          </div>
          <div className="budget-grid-item">
            <span className="budget-grid-label">SPENT</span>
            <span className="budget-grid-value">{fmtPeso(spent)}</span>
          </div>
          <div className="budget-grid-item">
            <span className="budget-grid-label">AVAILABLE</span>
            <span className={`budget-grid-value ${availColor(health.key)}`}>{fmtPeso(available)}</span>
          </div>
        </div>

        {/* Meta */}
        <div style={{ fontSize: 12, color: '#94a3b8' }}>
          Created by: <strong style={{ color: '#64748b' }}>{createdBy}</strong>
          {b.created_at && (
            <span> on {isoToMDY(b.created_at)}</span>
          )}
          {b.category?.category_name && (
            <span style={{ marginLeft: 12 }}>
              Category: <strong style={{ color: '#64748b' }}>{b.category.category_name}</strong>
            </span>
          )}
        </div>
        {b.notes && (
          <div style={{ fontSize: 12, color: '#64748b', marginTop: 4, fontStyle: 'italic' }}>
            Notes: {b.notes}
          </div>
        )}

        {/* Actions */}
        <div className="budget-card-actions">
          <button type="button" className="budget-edit-btn" onClick={() => openEdit(b)}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="13" height="13" style={{ marginRight: 5 }}>
              <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
              <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
            </svg>
            EDIT
          </button>
          <ArchiveButton onArchive={() => handleArchive(b.budget_id)} size="md" />
        </div>
      </div>
    );
  };

  // ── Tab: Overview ────────────────────────────────────────────────────────────
  const renderOverview = () => {
    if (loading) return <div className="po-status-loading">Loading budgets…</div>;
    if (loadError) return <div className="po-status-error-msg">{loadError}</div>;

    return (
      <div>
        {/* Summary card */}
        <div className="budget-summary-card">
          <div style={{ fontSize: 13, fontWeight: 700, letterSpacing: '0.06em', color: '#94a3b8' }}>
            BUDGET OVERVIEW — ALL LOCATIONS
          </div>
          <div className="budget-summary-grid">
            <div>
              <div className="budget-summary-item-label">TOTAL ALLOCATED</div>
              <div className="budget-summary-item-value">{fmtPeso(summary.total)}</div>
            </div>
            <div>
              <div className="budget-summary-item-label">TOTAL COMMITTED</div>
              <div className="budget-summary-item-value">{fmtPeso(summary.committed)}</div>
            </div>
            <div>
              <div className="budget-summary-item-label">TOTAL SPENT</div>
              <div className="budget-summary-item-value">{fmtPeso(summary.spent)}</div>
            </div>
          </div>
        </div>

        {budgets.length === 0 ? (
          <div className="po-status-empty">
            <svg viewBox="0 0 24 24" fill="none" stroke="#cbd5e1" strokeWidth="1.5" width="44" height="44">
              <line x1="12" y1="1" x2="12" y2="23" />
              <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
            </svg>
            <p>No budget allocations set yet.</p>
            <span>Create a budget for each location so Inventory Analysts can track spending.</span>
            <button
              type="button"
              className="po-btn po-btn-add-product"
              style={{ marginTop: 14 }}
              onClick={() => setActiveTab('create')}
            >
              + Create First Budget
            </button>
          </div>
        ) : (
          <div>
            {budgets.map(renderBudgetCard)}
          </div>
        )}
      </div>
    );
  };

  // ── Tab: Create ──────────────────────────────────────────────────────────────
  const renderCreate = () => (
    <div className="po-details-card" style={{ maxWidth: 680 }}>
      <h3 style={{ fontSize: 14, fontWeight: 700, color: '#1e293b', textTransform: 'uppercase', letterSpacing: '0.06em', margin: '0 0 18px' }}>
        New Budget Allocation
      </h3>

      <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
        {renderFormFields(form, setF, formErrors, false)}
      </div>

      {renderPreview()}

      {createError && (
        <div className="po-submit-error" style={{ marginTop: 14 }}>{createError}</div>
      )}

      <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 20 }}>
        <button
          type="button"
          className="po-btn po-btn-final-submission"
          onClick={handleCreate}
          disabled={submitting}
        >
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="16" height="16">
            <path d="M22 2L11 13" /><path d="M22 2l-7 20-4-9-9-4 20-7z" />
          </svg>
          {submitting ? 'CREATING…' : 'CREATE BUDGET ALLOCATION'}
        </button>
      </div>
    </div>
  );

  // ── Access guard ─────────────────────────────────────────────────────────────
  if (!isAdmin) {
    return (
      <AdminLayout>
        <div className="po-recommendation-page">
          <div className="po-status-empty">
            <svg viewBox="0 0 24 24" fill="none" stroke="#cbd5e1" strokeWidth="1.5" width="44" height="44">
              <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
              <path d="M7 11V7a5 5 0 0 1 10 0v4" />
            </svg>
            <p>Access Denied</p>
            <span>Budget management is restricted to System Administrators.</span>
          </div>
        </div>
      </AdminLayout>
    );
  }

  // ── Main render ──────────────────────────────────────────────────────────────
  return (
    <AdminLayout>
      <div className="po-recommendation-page">
        {/* Success banner */}
        {createSuccess && (
          <div className="po-success-banner">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="18" height="18">
              <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" /><polyline points="22 4 12 14.01 9 11.01" />
            </svg>
            {createSuccess}
            <button type="button" className="po-success-close" onClick={() => setCreateSuccess('')}>✕</button>
          </div>
        )}

        {/* Page header */}
        <div className="po-creator-header-card">
          <div className="po-creator-header-grid">
            <div className="po-creator-header-main">
              <div className="po-creator-cart-wrap po-creator-cart-wrap--doc" aria-hidden>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" width="28" height="28">
                  <line x1="12" y1="1" x2="12" y2="23" />
                  <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
                </svg>
              </div>
              <div className="po-creator-header-copy">
                <h1 className="po-page-title">BUDGET MANAGEMENT</h1>
                <p className="po-page-subtitle">Set and monitor budget allocations per location and period</p>
              </div>
            </div>
          </div>
        </div>

        {/* Tabs */}
        <div className="po-tab-nav">
          <button
            type="button"
            className={`po-tab-btn${activeTab === 'overview' ? ' po-tab-btn--active' : ''}`}
            onClick={() => setActiveTab('overview')}
          >
            Budget Overview
            {budgets.length > 0 && (
              <span style={{ marginLeft: 6, background: '#e2e8f0', color: '#475569', borderRadius: 10, fontSize: 11, padding: '1px 7px', fontWeight: 700 }}>
                {budgets.length}
              </span>
            )}
          </button>
          <button
            type="button"
            className={`po-tab-btn${activeTab === 'create' ? ' po-tab-btn--active' : ''}`}
            onClick={() => setActiveTab('create')}
          >
            + Create Budget
          </button>
        </div>

        {activeTab === 'overview' && renderOverview()}
        {activeTab === 'create'   && renderCreate()}
      </div>

      {/* ── Edit Modal ─────────────────────────────────────────────────────── */}
      <Modal
        isOpen={!!editBudget}
        onClose={() => setEditBudget(null)}
        title="Edit Budget Allocation"
        maxWidth={560}
      >
        {editBudget && (
          <div style={{ display: 'flex', flexDirection: 'column', gap: 14, paddingTop: 4 }}>
            {renderFormFields(editForm, setEF, editErrors, true)}

            {editError && (
              <div className="po-submit-error">{editError}</div>
            )}

            <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end', paddingTop: 8 }}>
              <button
                type="button"
                style={{ padding: '8px 20px', borderRadius: 8, border: '1px solid #e2e8f0', background: '#fff', fontSize: 13, cursor: 'pointer', color: '#475569' }}
                onClick={() => setEditBudget(null)}
                disabled={editSaving}
              >
                CANCEL
              </button>
              <button
                type="button"
                className="po-btn po-btn-final-submission"
                onClick={handleEditSave}
                disabled={editSaving}
              >
                {editSaving ? 'SAVING…' : 'SAVE CHANGES'}
              </button>
            </div>
          </div>
        )}
      </Modal>

    </AdminLayout>
  );
};

export default BudgetManagementPage;
