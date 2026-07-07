import React, { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import Loader from '../components/Loader';
import { restockAPI } from '../services/api';
import { toast } from '../utils/toast';
import { confirmAction } from '../utils/confirm';
import '../styles/po_recommendation.css';

const PRIORITY_BADGE = {
  CRITICAL: 'ak-badge ak-badge--red',
  HIGH:     'ak-badge ak-badge--amber',
  MEDIUM:   'ak-badge ak-badge--blue',
};

const REASON_LABELS = {
  OUT_OF_STOCK:       'Out of stock',
  BELOW_SAFETY_STOCK: 'Below safety stock',
  STOCKOUT_RISK:      'Stockout risk',
  LOW_STOCK:          'Low stock',
  FAST_MOVING:        'Fast moving',
  HIGH_TURNOVER:      'High turnover',
  SLOW_MOVING:        'Slow moving',
  NO_MOVEMENT:        'No movement',
  DEMAND_SHIFT:       'Demand shift',
};

const fmt = (v, digits = 1) =>
  v === null || v === undefined || v === '' ? '—' : Number(v).toFixed(digits);

function ReasonChips({ codes, title }) {
  const list = Array.isArray(codes) ? codes : [];
  return (
    <span className="po-reason-chips" title={title || ''}>
      {list.map((code) => (
        <span key={code} className="ak-pill">{REASON_LABELS[code] || code}</span>
      ))}
    </span>
  );
}

const PORecommendation = () => {
  const navigate = useNavigate();

  const [tab, setTab]                   = useState('recommendations');
  const [runAt, setRunAt]               = useState(null);
  const [rows, setRows]                 = useState([]);
  const [suggestions, setSuggestions]   = useState([]);
  const [loading, setLoading]           = useState(true);
  const [recalculating, setRecalc]      = useState(false);
  const [creating, setCreating]         = useState(false);
  const [search, setSearch]             = useState('');
  const [priorityFilter, setPriority]   = useState('all');
  // product_id -> quantity override (selection)
  const [selected, setSelected]         = useState(() => new Map());
  const [busySuggestion, setBusySug]    = useState(null);

  const loadAll = async () => {
    setLoading(true);
    try {
      const [recRes, sugRes] = await Promise.all([
        restockAPI.getRecommendations(),
        restockAPI.getSuggestions('pending'),
      ]);
      setRunAt(recRes?.data?.run_at || null);
      setRows(Array.isArray(recRes?.data?.recommendations) ? recRes.data.recommendations : []);
      setSuggestions(Array.isArray(sugRes?.data) ? sugRes.data : []);
    } catch (err) {
      console.error('PO Recommendation load failed:', err);
      toast.error('Failed to load recommendations', err?.message);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { loadAll(); }, []);

  const handleRecalculate = async () => {
    setRecalc(true);
    try {
      const res = await restockAPI.recalculate();
      setRunAt(res?.data?.run_at || null);
      setRows(Array.isArray(res?.data?.recommendations) ? res.data.recommendations : []);
      const sugRes = await restockAPI.getSuggestions('pending');
      setSuggestions(Array.isArray(sugRes?.data) ? sugRes.data : []);
      setSelected(new Map());
      toast.success('Recommendations recalculated');
    } catch (err) {
      toast.error('Recalculation failed', err?.message);
    } finally {
      setRecalc(false);
    }
  };

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    return rows.filter((r) => {
      if (priorityFilter !== 'all' && r.priority !== priorityFilter) return false;
      if (!q) return true;
      const name = r.product?.product_name || '';
      const code = r.product?.product_code || '';
      return name.toLowerCase().includes(q) || code.toLowerCase().includes(q);
    });
  }, [rows, search, priorityFilter]);

  const counts = useMemo(() => ({
    critical: rows.filter((r) => r.priority === 'CRITICAL').length,
    high:     rows.filter((r) => r.priority === 'HIGH').length,
    medium:   rows.filter((r) => r.priority === 'MEDIUM').length,
  }), [rows]);

  const toggleRow = (r) => {
    setSelected((prev) => {
      const next = new Map(prev);
      if (next.has(r.product_id)) next.delete(r.product_id);
      else next.set(r.product_id, Number(r.recommended_quantity) || 1);
      return next;
    });
  };

  const setQty = (productId, qty) => {
    setSelected((prev) => {
      const next = new Map(prev);
      next.set(productId, qty);
      return next;
    });
  };

  const handleCreatePR = async () => {
    const items = [...selected.entries()]
      .map(([product_id, quantity]) => ({ product_id, quantity: Number(quantity) }))
      .filter((i) => Number.isFinite(i.quantity) && i.quantity > 0);
    if (items.length === 0) {
      toast.warning('Select at least one product with a quantity above zero');
      return;
    }

    const ok = await confirmAction({
      title: `Create purchase request for ${items.length} product(s)?`,
      text: 'A draft PR will be created and sent for admin approval.',
      confirmText: 'Create PR',
      tone: 'primary',
    });
    if (!ok) return;

    setCreating(true);
    try {
      const res = await restockAPI.createPR(items);
      toast.success('Draft purchase request created', res?.data?.pr_number);
      setSelected(new Map());
      navigate('/admin/purchase-requests');
    } catch (err) {
      toast.error('Failed to create purchase request', err?.message);
    } finally {
      setCreating(false);
    }
  };

  const handleApply = async (s) => {
    const ok = await confirmAction({
      title: `Apply new stock parameters to ${s.product?.product_name || 'this product'}?`,
      text:
        `Recommended ${s.current_recommended} → ${s.suggested_recommended}, ` +
        `Safety ${s.current_safety ?? 0} → ${s.suggested_safety ?? 0}, ` +
        `Max ${s.current_max ?? 0} → ${s.suggested_max ?? 0}`,
      confirmText: 'Apply',
      tone: 'primary',
    });
    if (!ok) return;

    setBusySug(s.suggestion_id);
    try {
      await restockAPI.applySuggestion(s.suggestion_id);
      setSuggestions((prev) => prev.filter((x) => x.suggestion_id !== s.suggestion_id));
      toast.success('Stock parameters updated');
    } catch (err) {
      toast.error('Could not apply suggestion', err?.message);
    } finally {
      setBusySug(null);
    }
  };

  const handleDismiss = async (s) => {
    setBusySug(s.suggestion_id);
    try {
      await restockAPI.dismissSuggestion(s.suggestion_id);
      setSuggestions((prev) => prev.filter((x) => x.suggestion_id !== s.suggestion_id));
      toast.info('Suggestion dismissed');
    } catch (err) {
      toast.error('Could not dismiss suggestion', err?.message);
    } finally {
      setBusySug(null);
    }
  };

  if (loading) {
    return (
      <AdminLayout>
        <div className="ak-page po-recommendation-page"><Loader /></div>
      </AdminLayout>
    );
  }

  return (
    <AdminLayout>
      <div className="ak-page po-recommendation-page">
        <div className="ak-header">
          <div className="ak-header-row">
            <div>
              <h1>PO Recommendation</h1>
              <p className="ak-muted">
                {runAt
                  ? `Last computed: ${new Date(runAt).toLocaleString()}`
                  : 'No analytics run yet — click Recalculate.'}
              </p>
            </div>
            <div className="ak-header-aside">
              <button
                className="ak-btn ak-btn--primary"
                onClick={handleRecalculate}
                disabled={recalculating}
              >
                {recalculating ? 'Recalculating…' : 'Recalculate now'}
              </button>
            </div>
          </div>
        </div>

        <div className="ak-stat-grid">
          <div className="ak-stat">
            <span className="ak-stat-label">Critical</span>
            <span className="ak-stat-value">{counts.critical}</span>
          </div>
          <div className="ak-stat">
            <span className="ak-stat-label">High priority</span>
            <span className="ak-stat-value">{counts.high}</span>
          </div>
          <div className="ak-stat">
            <span className="ak-stat-label">Medium</span>
            <span className="ak-stat-value">{counts.medium}</span>
          </div>
          <div className="ak-stat">
            <span className="ak-stat-label">Parameter suggestions</span>
            <span className="ak-stat-value">{suggestions.length}</span>
          </div>
        </div>

        <div className="ak-tabs">
          <button
            className={`ak-tab ${tab === 'recommendations' ? 'is-active' : ''}`}
            onClick={() => setTab('recommendations')}
          >
            Restock Recommendations ({rows.length})
          </button>
          <button
            className={`ak-tab ${tab === 'suggestions' ? 'is-active' : ''}`}
            onClick={() => setTab('suggestions')}
          >
            Stock Parameter Suggestions ({suggestions.length})
          </button>
        </div>

        {tab === 'recommendations' && (
          <div className="ak-card">
            <div className="ak-toolbar">
              <input
                className="ak-input ak-search"
                placeholder="Search product name or code…"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
              />
              <select
                className="ak-input"
                value={priorityFilter}
                onChange={(e) => setPriority(e.target.value)}
              >
                <option value="all">All priorities</option>
                <option value="CRITICAL">Critical</option>
                <option value="HIGH">High</option>
                <option value="MEDIUM">Medium</option>
              </select>
              <div className="ak-toolbar--push">
                <button
                  className="ak-btn ak-btn--primary"
                  onClick={handleCreatePR}
                  disabled={creating || selected.size === 0}
                >
                  {creating ? 'Creating…' : `Create Purchase Request (${selected.size})`}
                </button>
              </div>
            </div>

            {filtered.length === 0 ? (
              <div className="ak-state">
                <p>No restock recommendations{rows.length > 0 ? ' match the filters' : ''}.</p>
              </div>
            ) : (
              <div className="ak-table-wrap">
                <table className="ak-table">
                  <thead>
                    <tr>
                      <th></th>
                      <th>Product</th>
                      <th>Priority</th>
                      <th>Stock</th>
                      <th>On order</th>
                      <th>Demand/day</th>
                      <th>Cover (days)</th>
                      <th>Lead (days)</th>
                      <th>Order qty</th>
                      <th>Why</th>
                    </tr>
                  </thead>
                  <tbody>
                    {filtered.map((r) => {
                      const checked = selected.has(r.product_id);
                      const trend = r.demand_trend === null || r.demand_trend === undefined
                        ? null : Number(r.demand_trend);
                      return (
                        <tr key={r.recommendation_id}>
                          <td>
                            <input
                              type="checkbox"
                              checked={checked}
                              onChange={() => toggleRow(r)}
                              aria-label={`Select ${r.product?.product_name || `product ${r.product_id}`}`}
                            />
                          </td>
                          <td>
                            <div>{r.product?.product_name || `#${r.product_id}`}</div>
                            <div className="ak-muted ak-mono">{r.product?.product_code}</div>
                          </td>
                          <td>
                            <span className={PRIORITY_BADGE[r.priority] || 'ak-badge ak-badge--gray'}>
                              {r.priority}
                            </span>
                          </td>
                          <td>{r.current_stock}</td>
                          <td>{r.on_order_quantity}</td>
                          <td>
                            {fmt(r.avg_daily_demand, 2)}
                            {trend !== null && trend >= 1.3 && <span title="Accelerating"> ▲</span>}
                            {trend !== null && trend <= 0.7 && <span title="Slowing down"> ▼</span>}
                          </td>
                          <td>{fmt(r.days_of_cover)}</td>
                          <td>{fmt(r.lead_time_days, 0)}</td>
                          <td>
                            {checked ? (
                              <input
                                type="number"
                                min="1"
                                className="ak-input po-qty-input"
                                style={{ width: 84 }}
                                value={selected.get(r.product_id)}
                                onChange={(e) => setQty(r.product_id, e.target.value)}
                                aria-label={`Order quantity for ${r.product?.product_name || `product ${r.product_id}`}`}
                              />
                            ) : (
                              r.recommended_quantity
                            )}
                          </td>
                          <td>
                            <ReasonChips codes={r.reason_codes} title={r.reason} />
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        )}

        {tab === 'suggestions' && (
          <div className="ak-card">
            {suggestions.length === 0 ? (
              <div className="ak-state">
                <p>No pending stock parameter suggestions. The analytics run raises one when a
                product's demand drifts away from its configured levels.</p>
              </div>
            ) : (
              <div className="ak-table-wrap">
                <table className="ak-table">
                  <thead>
                    <tr>
                      <th>Product</th>
                      <th>Recommended stock</th>
                      <th>Safety stock</th>
                      <th>Max level</th>
                      <th>Why</th>
                      <th className="ak-actions">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    {suggestions.map((s) => (
                      <tr key={s.suggestion_id}>
                        <td>
                          <div>{s.product?.product_name || `#${s.product_id}`}</div>
                          <div className="ak-muted ak-mono">{s.product?.product_code}</div>
                        </td>
                        <td>{s.current_recommended} → <strong>{s.suggested_recommended}</strong></td>
                        <td>{s.current_safety ?? 0} → <strong>{s.suggested_safety ?? 0}</strong></td>
                        <td>{s.current_max ?? 0} → <strong>{s.suggested_max ?? 0}</strong></td>
                        <td><ReasonChips codes={s.reason_codes} title={s.summary} /></td>
                        <td className="ak-actions">
                          <button
                            className="ak-btn ak-btn--sm ak-btn--primary"
                            disabled={busySuggestion === s.suggestion_id}
                            onClick={() => handleApply(s)}
                          >
                            Apply
                          </button>
                          <button
                            className="ak-btn ak-btn--sm ak-btn--ghost"
                            disabled={busySuggestion === s.suggestion_id}
                            onClick={() => handleDismiss(s)}
                          >
                            Dismiss
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        )}
      </div>
    </AdminLayout>
  );
};

export default PORecommendation;
