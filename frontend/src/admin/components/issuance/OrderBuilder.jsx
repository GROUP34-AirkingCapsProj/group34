import React, { useEffect, useRef, useState } from 'react';
import Modal from '../Modal';
import LocationBadge from '../LocationBadge';
import LocationSelector from '../LocationSelector';
import {
  customersAPI,
  issuanceScanAPI,
  issuanceOrderAPI,
  productSetsAPI,
  productsAPI,
  categoriesAPI,
  brandsAPI,
} from '../../services/api';
import { toast } from '../../utils/toast';
import { confirmAction } from '../../utils/confirm';
import { packageBreakdown } from '../../utils/packageFormat';

/**
 * OrderBuilder — Step 1 of the issuance wizard.
 *
 * Two phases live in one component:
 *   1. NO draft yet → the setup form (customer, issuance type, discount, location,
 *      notes). Submitting posts through issuanceScanAPI.initSession, which now
 *      creates a DRAFT and returns the session; the parent stores it.
 *   2. Draft exists → two add-paths (plain set / custom item) feeding one
 *      grouped line list rendered from the quote.
 *
 * Every line mutation hits issuanceOrderAPI (which returns the fresh quote) and the
 * result is pushed up via onQuoteUpdate so grouping/totals stay in sync.
 *
 * Props:
 *   issuanceId         number|null   — draft id once created
 *   quote              object|null   — quote `data` payload (set_groups, lines, totals)
 *   session            object|null   — init response (dr_number, customer)
 *   canIssue           boolean
 *   isStrictLocation   boolean
 *   enforcedLocationId number|null
 *   locationName, locationType, allowedLocations, locations — location controls
 *   onSessionCreated(sessionResponse) → parent stores session + loads quote
 *   onQuoteUpdate(quoteData)          → parent replaces its quote state
 *   onProceed()                       → parent advances to the Quote step
 */

const fmtMoney = (val) => {
  const n = parseFloat(val);
  if (val === null || val === undefined || Number.isNaN(n)) return '—';
  return '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
};

const EMPTY_FORM = {
  issuance_type: 'individual',
  discount_type: 'none',
  discount_percent: 0,
  location_id: '',
  notes: '',
};

function QuickAddModal({ onSave, onClose }) {
  const [form, setForm] = useState({ customer_type: 'walk_in', customer_name: '', contact_number: '', email: '' });
  const [saving, setSaving] = useState(false);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setSaving(true);
    try {
      const res = await customersAPI.create(form);
      const newC = res?.data ?? res;
      if (newC?.customer_id) {
        onSave(newC);
        toast.success('Customer added.');
      }
    } catch (err) {
      toast.error(err.message || 'Failed to add customer.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal isOpen title="Add New Customer" onClose={onClose}>
      <form onSubmit={handleSubmit} className="isp-form">
        <div className="isp-form-group">
          <label>Customer Name *</label>
          <input value={form.customer_name} onChange={(e) => setForm((f) => ({ ...f, customer_name: e.target.value }))} required autoFocus />
        </div>
        <div className="isp-form-group">
          <label>Type</label>
          <select value={form.customer_type} onChange={(e) => setForm((f) => ({ ...f, customer_type: e.target.value }))}>
            <option value="registered">Registered</option>
            <option value="walk_in">Walk-in</option>
          </select>
        </div>
        <div className="isp-form-group">
          <label>Contact Number</label>
          <input value={form.contact_number} onChange={(e) => setForm((f) => ({ ...f, contact_number: e.target.value }))} />
        </div>
        <div className="isp-form-group">
          <label>Email</label>
          <input type="email" value={form.email} onChange={(e) => setForm((f) => ({ ...f, email: e.target.value }))} />
        </div>
        <div className="isp-form-actions">
          <button type="button" className="isp-btn-secondary" onClick={onClose}>Cancel</button>
          <button type="submit" className="isp-btn-primary" disabled={saving}>{saving ? 'Saving...' : 'Save Customer'}</button>
        </div>
      </form>
    </Modal>
  );
}

// ── The setup form (phase 1) ──────────────────────────────────────────────────
function SetupForm({
  canIssue, isStrictLocation, enforcedLocationId, locationName, locationType,
  allowedLocations, locations, onSessionCreated,
}) {
  const [form, setForm] = useState({ ...EMPTY_FORM });
  const [customerMode, setCustomerMode] = useState('registered');
  const [walkInMode, setWalkInMode] = useState('registry');
  const [walkInName, setWalkInName] = useState('');
  const [customerId, setCustomerId] = useState('');
  const [customer, setCustomer] = useState(null);
  const [customerQuery, setCustomerQuery] = useState('');
  const [customerResults, setCustomerResults] = useState([]);
  const [customerLoading, setCustomerLoading] = useState(false);
  const [showDropdown, setShowDropdown] = useState(false);
  const [quickAddOpen, setQuickAddOpen] = useState(false);
  const [saving, setSaving] = useState(false);
  const searchTimer = useRef(null);

  useEffect(() => {
    clearTimeout(searchTimer.current);
    if (!customerQuery.trim() || customerQuery.trim().length < 2) {
      setCustomerResults([]);
      return;
    }
    searchTimer.current = setTimeout(async () => {
      setCustomerLoading(true);
      try {
        const res = await customersAPI.search(customerQuery);
        setCustomerResults(Array.isArray(res?.data) ? res.data : []);
        setShowDropdown(true);
      } catch {
        setCustomerResults([]);
      } finally {
        setCustomerLoading(false);
      }
    }, 300);
    return () => clearTimeout(searchTimer.current);
  }, [customerQuery]);

  const selectCustomer = (c) => {
    setCustomerId(c.customer_id);
    setCustomer(c);
    setCustomerQuery(c.customer_name);
    setShowDropdown(false);
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    const isOneTime = customerMode === 'walkin' && walkInMode === 'onetime';
    const isRegistry = customerMode === 'walkin' && walkInMode === 'registry';

    if (customerMode === 'registered' && !customerId) { toast.error('Select a customer.'); return; }
    if (isRegistry && !customerId) { toast.error('Add or select a walk-in customer first.'); return; }
    if (isOneTime && !walkInName.trim()) { toast.error('Enter the walk-in customer name.'); return; }

    const effectiveLocationId = isStrictLocation
      ? (enforcedLocationId ?? null)
      : (form.location_id ? parseInt(form.location_id, 10) : null);
    if (!effectiveLocationId) { toast.error('Select a location.'); return; }

    setSaving(true);
    try {
      const baseNotes = form.notes ? form.notes.trim() : null;
      const notesVal = isOneTime
        ? `Walk-in: ${walkInName.trim()}${baseNotes ? '. ' + baseNotes : ''}`
        : baseNotes;

      const payload = {
        issuance_type: form.issuance_type,
        discount_type: form.discount_type || 'none',
        discount_percent: form.issuance_type === 'business' ? parseFloat(form.discount_percent) || 0 : 0,
        location_id: effectiveLocationId,
        notes: notesVal || null,
      };
      if (!isOneTime) payload.customer_id = parseInt(customerId, 10);

      const res = await issuanceScanAPI.initSession(payload);
      onSessionCreated(res);
    } catch (err) {
      toast.error(err.message || 'Failed to start order.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="isp-card">
      <h2 className="isp-card-title">Order Setup</h2>
      <form onSubmit={handleSubmit} className="isp-form" autoComplete="off">
        <div className="isp-form-group">
          <label>Customer *</label>
          <div className="isp-mode-toggle">
            <button
              type="button"
              className={`isp-mode-btn ${customerMode === 'registered' ? 'isp-mode-btn-active' : ''}`}
              onClick={() => { setCustomerMode('registered'); setWalkInName(''); setCustomerId(''); setCustomer(null); setCustomerQuery(''); }}
            >Registered Customer</button>
            <button
              type="button"
              className={`isp-mode-btn ${customerMode === 'walkin' ? 'isp-mode-btn-active' : ''}`}
              onClick={() => { setCustomerMode('walkin'); setCustomerId(''); setCustomer(null); setCustomerQuery(''); }}
            >Walk-in</button>
          </div>
        </div>

        {customerMode === 'registered' && (
          <div className="isp-form-group" style={{ position: 'relative' }}>
            <input
              type="text"
              value={customerQuery}
              onChange={(e) => { setCustomerQuery(e.target.value); setCustomerId(''); setCustomer(null); }}
              onFocus={() => customerQuery && setShowDropdown(true)}
              placeholder="Type customer name or code..."
              autoComplete="off"
            />
            {customer && (
              <div className="isp-customer-selected">
                <span className={`isp-type-badge isp-type-${customer.customer_type}`}>
                  {customer.customer_type === 'walk_in' ? 'Walk-in' : 'Registered'}
                </span>
                {customer.company_name && <span className="isp-customer-company">{customer.company_name}</span>}
              </div>
            )}
            {showDropdown && (customerResults.length > 0 || customerLoading) && (
              <div className="isp-dropdown">
                {customerLoading && <div className="isp-dropdown-item isp-dropdown-loading">Searching...</div>}
                {customerResults.map((c) => (
                  <button key={c.customer_id} type="button" className="isp-dropdown-item" onMouseDown={() => selectCustomer(c)}>
                    <span className="isp-dropdown-name">{c.customer_name}</span>
                    <span className={`isp-type-badge isp-type-${c.customer_type}`}>
                      {c.customer_type === 'walk_in' ? 'Walk-in' : 'Registered'}
                    </span>
                    {c.company_name && <span className="isp-dropdown-company">{c.company_name}</span>}
                  </button>
                ))}
                <button type="button" className="isp-dropdown-item isp-dropdown-add" onMouseDown={() => { setShowDropdown(false); setQuickAddOpen(true); }}>
                  + Add New Customer
                </button>
              </div>
            )}
            {showDropdown && customerResults.length === 0 && !customerLoading && customerQuery.trim() && (
              <div className="isp-dropdown">
                <div className="isp-dropdown-item isp-dropdown-empty">No customers found.</div>
                <button type="button" className="isp-dropdown-item isp-dropdown-add" onMouseDown={() => { setShowDropdown(false); setQuickAddOpen(true); }}>
                  + Add New Customer
                </button>
              </div>
            )}
          </div>
        )}

        {customerMode === 'walkin' && (
          <div className="isp-form-group">
            <div className="isp-walkin-options">
              <label className="isp-walkin-option">
                <input type="radio" name="walkInMode" value="registry" checked={walkInMode === 'registry'} onChange={() => setWalkInMode('registry')} />
                Save to customer registry (walk-in type)
              </label>
              <label className="isp-walkin-option">
                <input type="radio" name="walkInMode" value="onetime" checked={walkInMode === 'onetime'} onChange={() => setWalkInMode('onetime')} />
                One-time only (no registry entry)
              </label>
            </div>
            {walkInMode === 'registry' && (
              <div style={{ marginTop: 8 }}>
                <button type="button" className="isp-btn-secondary" style={{ fontSize: 13 }} onClick={() => setQuickAddOpen(true)}>
                  {customer ? `Selected: ${customer.customer_name}` : '+ Add / Select Walk-in Customer'}
                </button>
              </div>
            )}
            {walkInMode === 'onetime' && (
              <div style={{ marginTop: 8 }}>
                <input
                  type="text"
                  placeholder="Walk-in customer name *"
                  value={walkInName}
                  onChange={(e) => setWalkInName(e.target.value)}
                  style={{ width: '100%', padding: '8px 12px', border: '1px solid #d1d5db', borderRadius: 6, fontSize: 13, boxSizing: 'border-box' }}
                />
                <p style={{ marginTop: 4, fontSize: 12, color: '#6b7280' }}>
                  Name will be stored in the order notes. No customer record will be created.
                </p>
              </div>
            )}
          </div>
        )}

        <div className="isp-form-row">
          <div className="isp-form-group">
            <label>Issuance Type *</label>
            <select value={form.issuance_type} onChange={(e) => setForm((f) => ({ ...f, issuance_type: e.target.value, discount_percent: 0 }))}>
              <option value="individual">Individual</option>
              <option value="business">Business</option>
            </select>
          </div>
          <div className="isp-form-group">
            <label>Location *</label>
            {isStrictLocation ? (
              <LocationBadge locationName={locationName} locationType={locationType} />
            ) : (
              <LocationSelector
                value={form.location_id ? Number(form.location_id) : null}
                onChange={(val) => setForm((f) => ({ ...f, location_id: val != null ? String(val) : '' }))}
                allowedLocations={allowedLocations.length ? allowedLocations : locations.map((l) => ({
                  location_id: l.location_id ?? l.id,
                  location_name: l.location_name ?? l.name,
                  location_type: l.location_type ?? '',
                  location_code: l.location_code ?? '',
                }))}
              />
            )}
          </div>
        </div>

        {form.issuance_type === 'business' && (
          <div className="isp-form-row">
            <div className="isp-form-group">
              <label>Discount Type</label>
              <select value={form.discount_type} onChange={(e) => setForm((f) => ({ ...f, discount_type: e.target.value }))}>
                <option value="none">None</option>
                <option value="business">Business Rate</option>
                <option value="senior">Senior</option>
                <option value="custom">Custom</option>
              </select>
            </div>
            {form.discount_type !== 'none' && (
              <div className="isp-form-group">
                <label>Discount % (0–100)</label>
                <input type="number" min="0" max="100" step="0.01" value={form.discount_percent} onChange={(e) => setForm((f) => ({ ...f, discount_percent: e.target.value }))} />
              </div>
            )}
          </div>
        )}

        <div className="isp-form-group">
          <label>Notes (optional)</label>
          <textarea rows={2} value={form.notes} onChange={(e) => setForm((f) => ({ ...f, notes: e.target.value }))} />
        </div>

        <div className="isp-form-actions">
          <button type="submit" className="isp-btn-primary" disabled={saving || !canIssue}>
            {saving ? 'Starting...' : 'Start Order'}
          </button>
          {!canIssue && <span style={{ fontSize: 12, color: '#dc2626', marginLeft: 8 }}>You do not have permission to issue stock.</span>}
        </div>
      </form>

      {quickAddOpen && (
        <QuickAddModal
          onSave={(newCustomer) => { selectCustomer(newCustomer); setQuickAddOpen(false); }}
          onClose={() => setQuickAddOpen(false)}
        />
      )}
    </div>
  );
}

// ── Add-set path (plain dropdown) ─────────────────────────────────────────────
function AddSetPath({ disabled, onAdd }) {
  const [sets, setSets] = useState([]);
  const [setId, setSetId] = useState('');
  const [qty, setQty] = useState(1);

  useEffect(() => {
    productSetsAPI.getAll({ active_only: true }).then((res) => {
      setSets(Array.isArray(res?.data) ? res.data : []);
    }).catch(() => setSets([]));
  }, []);

  const add = async () => {
    if (!setId) return;
    await onAdd(parseInt(setId, 10), Math.max(1, parseInt(qty, 10) || 1));
    setSetId('');
    setQty(1);
  };

  return (
    <div style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap', alignItems: 'flex-end' }}>
      <div className="ak-field" style={{ flex: '1 1 220px' }}>
        <label className="ak-label">Set</label>
        <select className="ak-select" value={setId} onChange={(e) => setSetId(e.target.value)} disabled={disabled}>
          <option value="">Select a set…</option>
          {sets.map((s) => (
            <option key={s.set_id} value={s.set_id}>
              {(s.label || s.brand_names || 'Set')} — {fmtMoney(s.set_price)}
            </option>
          ))}
        </select>
      </div>
      <div className="ak-field" style={{ width: 100 }}>
        <label className="ak-label">Qty</label>
        <input className="ak-input" type="number" min={1} value={qty} onChange={(e) => setQty(e.target.value)} disabled={disabled} />
      </div>
      <button type="button" className="ak-btn ak-btn--primary" onClick={add} disabled={disabled || !setId}>Add set</button>
    </div>
  );
}

// ── Add-custom path (any product, any qty) ────────────────────────────────────
function AddCustomPath({ disabled, onAdd }) {
  const [categories, setCategories] = useState([]);
  const [brands, setBrands] = useState([]);
  const [categoryId, setCategoryId] = useState('');
  const [brandId, setBrandId] = useState('');
  const [results, setResults] = useState([]);
  const [loading, setLoading] = useState(false);
  const [productId, setProductId] = useState('');
  const [qty, setQty] = useState(1);         // used when the product has no pack size
  const [packages, setPackages] = useState(0); // used when pieces_per_package > 1
  const [pieces, setPieces] = useState(0);     // loose pieces alongside packages

  // Load the category + brand filter options once.
  useEffect(() => {
    categoriesAPI.getAll().then((res) => {
      const list = Array.isArray(res?.data) ? res.data : (res?.data?.data ?? []);
      setCategories(Array.isArray(list) ? list : []);
    }).catch(() => setCategories([]));
    brandsAPI.getAll().then((res) => {
      const list = Array.isArray(res?.data) ? res.data : (res?.data?.data ?? []);
      setBrands(Array.isArray(list) ? list : []);
    }).catch(() => setBrands([]));
  }, []);

  // Load the product options for the chosen category/brand. The product picker is
  // a dropdown — no typing — so a picker who forgets the exact name just browses.
  useEffect(() => {
    if (!categoryId && !brandId) { setResults([]); setProductId(''); return; }
    let cancelled = false;
    setLoading(true);
    const params = { per_page: 200 };
    if (categoryId) params.category_id = categoryId;
    if (brandId) params.brand_id = brandId;
    productsAPI.getAll(params)
      .then((res) => {
        if (cancelled) return;
        const list = Array.isArray(res?.data) ? res.data : (res?.data?.data ?? []);
        setResults(Array.isArray(list) ? list : []);
      })
      .catch(() => { if (!cancelled) setResults([]); })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, [categoryId, brandId]);

  const picked = results.find((p) => String(p.product_id) === String(productId)) || null;
  const noFilter = !categoryId && !brandId;

  // Pack/box quantity, same model as Transfers: a non-appliance product that ships
  // in packages of >1 piece is entered as packages + loose pieces; everything else
  // is a single piece count. Stored quantity is always PIECES.
  const ppp = picked && picked.product_type !== 'appliance' ? (Number(picked.pieces_per_package) || 0) : 0;
  const usePack = ppp > 1;
  const totalPieces = usePack
    ? (parseInt(packages, 10) || 0) * ppp + (parseInt(pieces, 10) || 0)
    : (parseInt(qty, 10) || 0);

  const resetQty = () => { setQty(1); setPackages(0); setPieces(0); };

  const add = async () => {
    if (!picked || totalPieces < 1) return;
    await onAdd(picked.product_id, Math.max(1, totalPieces));
    setProductId('');
    resetQty();
  };

  return (
    <div className="ak-field" style={{ gap: '0.5rem' }}>
      {/* Category + brand dropdowns narrow the product list; pick the product from
          the third dropdown — no free-text needed. */}
      <div style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap' }}>
        <select className="ak-select" style={{ flex: '0 1 150px', maxWidth: 180 }} value={categoryId} onChange={(e) => { setCategoryId(e.target.value); setProductId(''); }} disabled={disabled}>
          <option value="">All categories</option>
          {categories.map((c) => (
            <option key={c.category_id} value={c.category_id}>{c.category_name}</option>
          ))}
        </select>
        <select className="ak-select" style={{ flex: '0 1 150px', maxWidth: 180 }} value={brandId} onChange={(e) => { setBrandId(e.target.value); setProductId(''); }} disabled={disabled}>
          <option value="">All brands</option>
          {brands.map((b) => (
            <option key={b.brand_id} value={b.brand_id}>{b.brand_name}</option>
          ))}
        </select>
      </div>
      <div style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap', alignItems: 'flex-end' }}>
        <div className="ak-field" style={{ flex: '1 1 200px', maxWidth: 320, minWidth: 0 }}>
          <label className="ak-label">Product</label>
          <select
            className="ak-select"
            style={{ maxWidth: '100%', textOverflow: 'ellipsis', overflow: 'hidden', whiteSpace: 'nowrap' }}
            value={productId}
            onChange={(e) => { setProductId(e.target.value); resetQty(); }}
            disabled={disabled || noFilter || loading}
          >
            <option value="">
              {noFilter ? 'Select a category or brand first…' : loading ? 'Loading…' : (results.length ? 'Select a product…' : 'No products found')}
            </option>
            {results.map((p) => (
              <option key={p.product_id} value={p.product_id}>
                {p.product_name}{p.product_code ? ` — ${p.product_code}` : ''}
              </option>
            ))}
          </select>
        </div>
        {usePack ? (
          <>
            <div className="ak-field" style={{ width: 92 }} title={`${ppp} pcs per ${picked.package_unit || 'package'}`}>
              <label className="ak-label">{picked.package_unit || 'Pack'}×{ppp}</label>
              <input className="ak-input" type="number" min={0} value={packages} onChange={(e) => setPackages(e.target.value)} disabled={disabled} />
            </div>
            <div className="ak-field" style={{ width: 80 }}>
              <label className="ak-label">Pieces</label>
              <input className="ak-input" type="number" min={0} value={pieces} onChange={(e) => setPieces(e.target.value)} disabled={disabled} />
            </div>
          </>
        ) : (
          <div className="ak-field" style={{ width: 100 }}>
            <label className="ak-label">Qty</label>
            <input className="ak-input" type="number" min={1} value={qty} onChange={(e) => setQty(e.target.value)} disabled={disabled || !picked} />
          </div>
        )}
        <button type="button" className="ak-btn ak-btn--primary" onClick={add} disabled={disabled || !picked || totalPieces < 1}>Add item</button>
      </div>
      {usePack && totalPieces > 0 && (
        <div className="ak-muted" style={{ fontSize: '0.78rem' }}>
          = {totalPieces} pcs{packageBreakdown(totalPieces, ppp, picked.package_unit) ? ` · ${packageBreakdown(totalPieces, ppp, picked.package_unit)}` : ''}
        </div>
      )}
    </div>
  );
}

// ── One editable line row ─────────────────────────────────────────────────────
function LineRow({ line, disabled, onUpdate, onRemove }) {
  const [editing, setEditing] = useState(false);
  const [qty, setQty] = useState(line.quantity_requested);

  useEffect(() => { setQty(line.quantity_requested); }, [line.quantity_requested]);

  const save = async () => {
    const q = Math.max(1, parseInt(qty, 10) || 1);
    setEditing(false);
    if (q !== line.quantity_requested) await onUpdate(line.issuance_detail_id, q);
  };

  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', padding: '0.45rem 0', borderBottom: '1px solid var(--ak-line)' }}>
      <div style={{ flex: 1, minWidth: 0 }}>
        <div style={{ fontWeight: 600 }}>{line.product_name || `Product #${line.product_id}`}</div>
        <div className="ak-muted" style={{ fontSize: '0.8rem' }}>
          {fmtMoney(line.unit_price)} each
          {line.is_short && (
            <span style={{ color: '#b91c1c', marginLeft: '0.5rem' }}>
              Only {line.available} available — short by {line.short_by}
            </span>
          )}
        </div>
      </div>
      {editing ? (
        <>
          <input
            className="ak-input"
            type="number"
            min={1}
            value={qty}
            onChange={(e) => setQty(e.target.value)}
            style={{ width: 80 }}
            disabled={disabled}
          />
          <button type="button" className="ak-btn ak-btn--primary" onClick={save} disabled={disabled}>Save</button>
          <button type="button" className="ak-btn" onClick={() => { setEditing(false); setQty(line.quantity_requested); }} disabled={disabled}>Cancel</button>
        </>
      ) : (
        <>
          <span className="ak-mono" style={{ minWidth: 32, textAlign: 'right' }}>
            ×{line.quantity_requested}
            {packageBreakdown(line.quantity_requested, line.pieces_per_package, line.package_unit)
              && <span className="ak-muted" style={{ marginLeft: 6, fontWeight: 400 }}>· {packageBreakdown(line.quantity_requested, line.pieces_per_package, line.package_unit)}</span>}
          </span>
          <span style={{ minWidth: 90, textAlign: 'right', fontWeight: 600 }}>{fmtMoney(line.line_total)}</span>
          <button type="button" className="ak-btn" onClick={() => setEditing(true)} disabled={disabled}>Edit</button>
          <button type="button" className="ak-btn" onClick={() => onRemove(line.issuance_detail_id, line.product_name)} disabled={disabled}>Remove</button>
        </>
      )}
    </div>
  );
}

export default function OrderBuilder({
  issuanceId, quote, session, canIssue,
  isStrictLocation, enforcedLocationId, locationName, locationType, allowedLocations = [], locations = [],
  onSessionCreated, onQuoteUpdate, onProceed,
}) {
  const [busy, setBusy] = useState(false);

  // Phase 1 — no draft yet.
  if (!issuanceId) {
    return (
      <SetupForm
        canIssue={canIssue}
        isStrictLocation={isStrictLocation}
        enforcedLocationId={enforcedLocationId}
        locationName={locationName}
        locationType={locationType}
        allowedLocations={allowedLocations}
        locations={locations}
        onSessionCreated={onSessionCreated}
      />
    );
  }

  // Phase 2 — draft exists; manage lines.
  const lines = quote?.lines ?? [];
  const setGroups = quote?.set_groups ?? [];
  const looseLines = lines.filter((l) => l.issuance_set_id == null);

  // Run any mutation that returns a fresh quote, then push it up.
  const run = async (fn) => {
    setBusy(true);
    try {
      const res = await fn();
      const data = res?.data ?? res;
      if (data) onQuoteUpdate(data);
      return data;
    } catch (err) {
      toast.error(err.message || 'Action failed.');
      throw err;
    } finally {
      setBusy(false);
    }
  };

  const handleAddSet = async (setId, setQty) => {
    try {
      await run(() => issuanceOrderAPI.addSet(issuanceId, { set_id: setId, set_quantity: setQty }));
      toast.success('Set added.');
    } catch { /* handled */ }
  };

  const handleAddCustom = async (productId, qty) => {
    try {
      await run(() => issuanceOrderAPI.addCustom(issuanceId, { product_id: productId, quantity: qty }));
      toast.success('Item added.');
    } catch { /* handled */ }
  };

  const handleUpdateLine = async (lineId, qty) => {
    try { await run(() => issuanceOrderAPI.updateLine(issuanceId, lineId, { quantity: qty })); }
    catch { /* handled */ }
  };

  const handleRemoveLine = async (lineId, productName) => {
    if (!(await confirmAction({ title: `Remove ${productName || 'this line'} from the order?`, confirmText: 'Remove', tone: 'danger' }))) return;
    try {
      await run(() => issuanceOrderAPI.removeLine(issuanceId, lineId));
      toast.success('Line removed.');
    } catch { /* handled */ }
  };

  const hasLines = lines.length > 0;

  return (
    <div className="ak-field" style={{ gap: '1rem' }}>
      {/* Session header */}
      <div className="isp-session-header">
        <div className="isp-session-info">
          <span className="isp-session-dr">DR: {session?.dr_number ?? quote?.dr_number ?? '—'}</span>
          <span className="isp-session-sep">|</span>
          <span>{session?.customer?.customer_name ?? '—'}</span>
          <span className="isp-session-sep">|</span>
          <span className={`isp-type-badge isp-type-${session?.issuance_type ?? ''}`}>{session?.issuance_type ?? ''}</span>
          <span className="isp-session-sep">|</span>
          <span className="isp-session-total">Total: {fmtMoney(quote?.total_amount)}</span>
        </div>
        <button type="button" className="isp-btn-complete" disabled={!hasLines || busy} onClick={onProceed}>
          Proceed to Quote
        </button>
      </div>

      {/* Add-paths */}
      <div className="ak-card" style={{ padding: '1rem', display: 'grid', gap: '1.2rem' }}>
        <div>
          <div className="ak-label" style={{ marginBottom: '0.4rem' }}>Add set</div>
          <AddSetPath disabled={busy || !canIssue} onAdd={handleAddSet} />
        </div>
        <div>
          <div className="ak-label" style={{ marginBottom: '0.4rem' }}>Add custom item</div>
          <AddCustomPath disabled={busy || !canIssue} onAdd={handleAddCustom} />
        </div>
      </div>

      {/* Grouped lines */}
      <div className="ak-card" style={{ padding: '1rem' }}>
        <h2 className="isp-card-title" style={{ marginTop: 0 }}>Order Lines</h2>
        {!hasLines ? (
          <div style={{ textAlign: 'center', padding: '28px 0', color: '#9ca3af', fontSize: 14 }}>
            No lines yet. Add a set or custom item above.
          </div>
        ) : (
          <div style={{ display: 'grid', gap: '1rem' }}>
            {setGroups.map((g) => {
              const groupLines = lines.filter((l) => l.issuance_set_id === g.issuance_set_id);
              return (
                <div key={g.issuance_set_id} style={{ border: '1px solid var(--ak-line)', borderRadius: 10, padding: '0.8rem' }}>
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: '0.5rem', marginBottom: '0.4rem' }}>
                    <div style={{ fontWeight: 700 }}>
                      {g.set_name_snapshot}
                      <span className="ak-muted" style={{ fontWeight: 400, marginLeft: '0.4rem' }}>×{g.set_quantity}</span>
                      {g.was_modified && (
                        <span className="ak-badge ak-badge--amber" style={{ marginLeft: '0.5rem' }}>Modified</span>
                      )}
                    </div>
                    <div style={{ fontWeight: 700 }}>{fmtMoney(g.group_total)}</div>
                  </div>
                  {groupLines.map((l) => (
                    <LineRow key={l.issuance_detail_id} line={l} disabled={busy || !canIssue} onUpdate={handleUpdateLine} onRemove={handleRemoveLine} />
                  ))}
                </div>
              );
            })}

            {looseLines.length > 0 && (
              <div style={{ border: '1px solid var(--ak-line)', borderRadius: 10, padding: '0.8rem' }}>
                <div style={{ fontWeight: 700, marginBottom: '0.4rem' }}>Custom items</div>
                {looseLines.map((l) => (
                  <LineRow key={l.issuance_detail_id} line={l} disabled={busy || !canIssue} onUpdate={handleUpdateLine} onRemove={handleRemoveLine} />
                ))}
              </div>
            )}

            {/* Totals */}
            <div className="isp-totals">
              <div className="isp-total-row"><span>Subtotal</span><span>{fmtMoney(quote?.subtotal)}</span></div>
              {parseFloat(quote?.discount_percent) > 0 && (
                <div className="isp-total-row isp-total-discount">
                  <span>Discount ({quote?.discount_percent}%)</span><span>-{fmtMoney(quote?.discount_amount)}</span>
                </div>
              )}
              <div className="isp-total-divider" />
              <div className="isp-total-row isp-total-grand"><span>Total</span><span>{fmtMoney(quote?.total_amount)}</span></div>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
