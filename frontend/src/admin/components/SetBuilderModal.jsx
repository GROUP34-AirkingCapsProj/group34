import React, { useEffect, useMemo, useState } from 'react';
import Modal from './Modal';
import { productsAPI, productSetsAPI } from '../services/api';
import { toast } from '../utils/toast';

const formatMoney = (n) =>
  '₱' + (Number(n) || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

let lineSeq = 0;
const newLine = () => ({
  key: `ln-${Date.now()}-${lineSeq++}`,
  product_id: '',
  quantity: 1,
  issuable_unit_id: '',
  spec_note: '',
});

/**
 * Header + line editor for authoring a Product Set (BOQ template).
 *
 * Props:
 *   isOpen, onClose
 *   initialSet   {object|null}  set being edited, or a prefilled duplicate (set_id absent = new)
 *   brands       {array}        [{ brand_id, brand_name }]
 *   onSaved      {() => void}   called after a successful create/update
 *   canSubmit    {boolean}      whether the form should render its Save button
 */
export default function SetBuilderModal({ isOpen, onClose, initialSet, brands, onSaved, canSubmit }) {
  const [form, setForm] = useState({
    set_code: '',
    set_name: '',
    description: '',
    brand_id: '',
    capacity_rating: '',
    variant: '',
    is_active: true,
  });
  const [lines, setLines] = useState([newLine()]);
  const [products, setProducts] = useState([]);
  const [loadingProducts, setLoadingProducts] = useState(true);
  const [productFilter, setProductFilter] = useState('');
  const [issuableUnitsByProduct, setIssuableUnitsByProduct] = useState({});
  const [submitting, setSubmitting] = useState(false);
  const [errors, setErrors] = useState({});

  const isEditing = !!initialSet?.set_id;

  useEffect(() => {
    if (!isOpen) return;
    productsAPI.getAll({ per_page: 1000 })
      .then((r) => setProducts(Array.isArray(r?.data) ? r.data : []))
      .catch(() => setProducts([]))
      .finally(() => setLoadingProducts(false));
  }, [isOpen]);

  useEffect(() => {
    if (!isOpen) return;
    setErrors({});
    if (initialSet) {
      setForm({
        set_code: initialSet.set_code || '',
        set_name: initialSet.set_name || '',
        description: initialSet.description || '',
        brand_id: initialSet.brand_id != null ? String(initialSet.brand_id) : '',
        capacity_rating: initialSet.capacity_rating || '',
        variant: initialSet.variant || '',
        is_active: initialSet.is_active !== false,
      });
      const initLines = (initialSet.lines || [])
        .slice()
        .sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0))
        .map((l) => ({
          key: `ln-${l.set_line_id ?? `${Date.now()}-${lineSeq++}`}`,
          product_id: l.product_id != null ? String(l.product_id) : '',
          quantity: l.quantity ?? 1,
          issuable_unit_id: l.issuable_unit_id != null ? String(l.issuable_unit_id) : '',
          spec_note: l.spec_note || '',
          _product_name: l.product_name,
          _unit_price: l.unit_price,
        }));
      setLines(initLines.length ? initLines : [newLine()]);
      // Preload issuable units for every product already on the set so their
      // unit selects render immediately instead of showing "pieces" first.
      initLines.forEach((l) => {
        if (l.product_id) loadIssuableUnits(l.product_id);
      });
    } else {
      setForm({
        set_code: '',
        set_name: '',
        description: '',
        brand_id: '',
        capacity_rating: '',
        variant: '',
        is_active: true,
      });
      setLines([newLine()]);
    }
    setProductFilter('');
  }, [isOpen, initialSet]); // eslint-disable-line react-hooks/exhaustive-deps

  const loadIssuableUnits = (productId) => {
    if (!productId || issuableUnitsByProduct[productId] !== undefined) return;
    productsAPI.getIssuableUnits(productId)
      .then((r) => {
        setIssuableUnitsByProduct((prev) => ({ ...prev, [productId]: Array.isArray(r?.data) ? r.data : [] }));
      })
      .catch(() => {
        setIssuableUnitsByProduct((prev) => ({ ...prev, [productId]: [] }));
      });
  };

  const productById = useMemo(() => {
    const m = new Map();
    products.forEach((p) => m.set(String(p.product_id), p));
    return m;
  }, [products]);

  const filteredProducts = useMemo(() => {
    const q = productFilter.trim().toLowerCase();
    if (!q) return products;
    return products.filter((p) =>
      String(p.product_name || '').toLowerCase().includes(q) ||
      String(p.product_code || '').toLowerCase().includes(q) ||
      String(p.barcode || '').toLowerCase().includes(q)
    );
  }, [products, productFilter]);

  // Make sure a line's already-selected product stays visible in its <select>
  // even when the current filter text would otherwise exclude it.
  const optionsForLine = (line) => {
    if (!line.product_id) return filteredProducts;
    if (filteredProducts.some((p) => String(p.product_id) === line.product_id)) return filteredProducts;
    const selected = productById.get(line.product_id);
    return selected ? [selected, ...filteredProducts] : filteredProducts;
  };

  const addLine = () => setLines((prev) => [...prev, newLine()]);
  const removeLine = (key) => setLines((prev) => (prev.length > 1 ? prev.filter((l) => l.key !== key) : prev));
  const updateLine = (key, field, value) =>
    setLines((prev) => prev.map((l) => (l.key === key ? { ...l, [field]: value } : l)));

  const handleProductChange = (key, productId) => {
    updateLine(key, 'product_id', productId);
    updateLine(key, 'issuable_unit_id', '');
    if (productId) loadIssuableUnits(productId);
  };

  const moveLine = (index, dir) => {
    setLines((prev) => {
      const next = prev.slice();
      const target = index + dir;
      if (target < 0 || target >= next.length) return prev;
      [next[index], next[target]] = [next[target], next[index]];
      return next;
    });
  };

  const lineTotal = (line) => {
    const p = productById.get(line.product_id);
    const price = Number(p?.unit_price ?? line._unit_price ?? 0);
    const qty = Number(line.quantity) || 0;
    return price * qty;
  };

  const runningTotal = useMemo(
    () => lines.reduce((sum, l) => sum + lineTotal(l), 0),
    [lines, products] // eslint-disable-line react-hooks/exhaustive-deps
  );

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!canSubmit) return;
    if (!form.set_code.trim() || !form.set_name.trim()) {
      toast.error('Set code and set name are required.');
      return;
    }
    const validLines = lines.filter((l) => l.product_id && Number(l.quantity) > 0);
    if (!validLines.length) {
      toast.error('Add at least one line with a product and quantity.');
      return;
    }

    const payload = {
      set_code: form.set_code.trim(),
      set_name: form.set_name.trim(),
      description: form.description.trim() || null,
      brand_id: form.brand_id ? Number(form.brand_id) : null,
      capacity_rating: form.capacity_rating.trim() || null,
      variant: form.variant.trim() || null,
      is_active: !!form.is_active,
      lines: validLines.map((l, i) => ({
        product_id: Number(l.product_id),
        quantity: Number(l.quantity),
        issuable_unit_id: l.issuable_unit_id ? Number(l.issuable_unit_id) : null,
        spec_note: l.spec_note.trim() || null,
        sort_order: i,
      })),
    };

    setSubmitting(true);
    setErrors({});
    try {
      if (isEditing) {
        await productSetsAPI.update(initialSet.set_id, payload);
        toast.success('Set updated.');
      } else {
        await productSetsAPI.create(payload);
        toast.success('Set created.');
      }
      onSaved?.();
    } catch (err) {
      const fieldErrors = err.errors || {};
      setErrors(fieldErrors);
      const msg = fieldErrors.set_code?.[0] || err.message || 'Failed to save the set.';
      toast.error(msg);
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Modal isOpen={isOpen} onClose={onClose} title={isEditing ? 'Edit Set' : 'New Set'} maxWidth={920}>
      <form onSubmit={handleSubmit} className="ak-field" style={{ gap: '1.1rem', paddingTop: '1rem' }}>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: '0.85rem' }}>
          <div className="ak-field">
            <label className="ak-label">Set code</label>
            <input
              className="ak-input"
              value={form.set_code}
              maxLength={60}
              onChange={(e) => setForm((f) => ({ ...f, set_code: e.target.value }))}
              disabled={!canSubmit}
              required
            />
            {errors.set_code?.[0] && <span style={{ color: '#b91c1c', fontSize: '0.78rem' }}>{errors.set_code[0]}</span>}
          </div>
          <div className="ak-field">
            <label className="ak-label">Set name</label>
            <input
              className="ak-input"
              value={form.set_name}
              maxLength={191}
              onChange={(e) => setForm((f) => ({ ...f, set_name: e.target.value }))}
              disabled={!canSubmit}
              required
            />
          </div>
          <div className="ak-field">
            <label className="ak-label">Brand</label>
            <select
              className="ak-select"
              value={form.brand_id}
              onChange={(e) => setForm((f) => ({ ...f, brand_id: e.target.value }))}
              disabled={!canSubmit}
            >
              <option value="">Any brand</option>
              {brands.map((b) => (
                <option key={b.brand_id} value={String(b.brand_id)}>{b.brand_name}</option>
              ))}
            </select>
          </div>
          <div className="ak-field">
            <label className="ak-label">Capacity rating</label>
            <input
              className="ak-input"
              value={form.capacity_rating}
              maxLength={60}
              placeholder="e.g. 1.0 HP"
              onChange={(e) => setForm((f) => ({ ...f, capacity_rating: e.target.value }))}
              disabled={!canSubmit}
            />
          </div>
          <div className="ak-field">
            <label className="ak-label">Variant</label>
            <input
              className="ak-input"
              value={form.variant}
              maxLength={100}
              placeholder="e.g. Inverter"
              onChange={(e) => setForm((f) => ({ ...f, variant: e.target.value }))}
              disabled={!canSubmit}
            />
          </div>
          <div className="ak-field">
            <label className="ak-label">Status</label>
            <label style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', fontSize: '0.88rem' }}>
              <input
                type="checkbox"
                checked={form.is_active}
                onChange={(e) => setForm((f) => ({ ...f, is_active: e.target.checked }))}
                disabled={!canSubmit}
              />
              Active
            </label>
          </div>
        </div>

        <div className="ak-field">
          <label className="ak-label">Description</label>
          <textarea
            className="ak-input"
            rows={2}
            maxLength={2000}
            value={form.description}
            onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))}
            disabled={!canSubmit}
          />
        </div>

        <div>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '0.5rem' }}>
            <label className="ak-label" style={{ margin: 0 }}>Lines</label>
            <div className="ak-search" style={{ minWidth: 220 }}>
              <input
                className="ak-input"
                placeholder="Filter products…"
                value={productFilter}
                onChange={(e) => setProductFilter(e.target.value)}
              />
            </div>
          </div>

          <div className="ak-table-wrap">
            <table className="ak-table">
              <thead>
                <tr>
                  <th style={{ width: 32 }}></th>
                  <th>Product</th>
                  <th style={{ width: 90 }}>Qty</th>
                  <th style={{ width: 160 }}>Unit</th>
                  <th>Spec note</th>
                  <th style={{ width: 90 }}>Line total</th>
                  <th style={{ width: 40 }}></th>
                </tr>
              </thead>
              <tbody>
                {lines.map((l, i) => {
                  const units = issuableUnitsByProduct[l.product_id] || [];
                  return (
                    <tr key={l.key}>
                      <td>
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
                          <button
                            type="button"
                            className="ak-btn ak-btn--ghost ak-btn--sm"
                            style={{ padding: '0 4px' }}
                            disabled={!canSubmit || i === 0}
                            onClick={() => moveLine(i, -1)}
                            title="Move up"
                          >
                            {'▲'}
                          </button>
                          <button
                            type="button"
                            className="ak-btn ak-btn--ghost ak-btn--sm"
                            style={{ padding: '0 4px' }}
                            disabled={!canSubmit || i === lines.length - 1}
                            onClick={() => moveLine(i, 1)}
                            title="Move down"
                          >
                            {'▼'}
                          </button>
                        </div>
                      </td>
                      <td>
                        <select
                          className="ak-select"
                          style={{ minWidth: 200 }}
                          value={l.product_id}
                          onChange={(e) => handleProductChange(l.key, e.target.value)}
                          disabled={!canSubmit || loadingProducts}
                        >
                          <option value="">{loadingProducts ? 'Loading products…' : 'Select product…'}</option>
                          {optionsForLine(l).map((p) => (
                            <option key={p.product_id} value={String(p.product_id)}>
                              {p.product_name}{p.product_code ? ` (${p.product_code})` : ''}
                            </option>
                          ))}
                        </select>
                      </td>
                      <td>
                        <input
                          type="number"
                          className="ak-input"
                          min="0.001"
                          step="0.001"
                          value={l.quantity}
                          onChange={(e) => updateLine(l.key, 'quantity', e.target.value)}
                          disabled={!canSubmit}
                        />
                      </td>
                      <td>
                        <select
                          className="ak-select"
                          value={l.issuable_unit_id}
                          onChange={(e) => updateLine(l.key, 'issuable_unit_id', e.target.value)}
                          disabled={!canSubmit || !l.product_id}
                        >
                          <option value="">Pieces (default)</option>
                          {units.map((u) => (
                            <option key={u.issuable_unit_id} value={String(u.issuable_unit_id)}>{u.label}</option>
                          ))}
                        </select>
                      </td>
                      <td>
                        <input
                          type="text"
                          className="ak-input"
                          maxLength={191}
                          value={l.spec_note}
                          onChange={(e) => updateLine(l.key, 'spec_note', e.target.value)}
                          disabled={!canSubmit}
                        />
                      </td>
                      <td className="ak-mono">{formatMoney(lineTotal(l))}</td>
                      <td>
                        {canSubmit && (
                          <button
                            type="button"
                            className="ak-btn ak-btn--ghost ak-btn--sm"
                            onClick={() => removeLine(l.key)}
                            disabled={lines.length <= 1}
                            title="Remove line"
                          >
                            {'✕'}
                          </button>
                        )}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>

          {canSubmit && (
            <button type="button" className="ak-btn ak-btn--sm" style={{ marginTop: '0.6rem' }} onClick={addLine}>
              + Add line
            </button>
          )}
        </div>

        <div style={{ display: 'flex', justifyContent: 'flex-end', alignItems: 'center', gap: '0.75rem', borderTop: '1px solid var(--ak-line)', paddingTop: '0.9rem' }}>
          <span className="ak-label" style={{ margin: 0 }}>Running total</span>
          <span className="ak-mono" style={{ fontSize: '1.05rem', fontWeight: 700 }}>{formatMoney(runningTotal)}</span>
        </div>

        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '0.6rem' }}>
          <button type="button" className="ak-btn" onClick={onClose}>
            {canSubmit ? 'Cancel' : 'Close'}
          </button>
          {canSubmit && (
            <button type="submit" className="ak-btn ak-btn--primary" disabled={submitting}>
              {submitting ? 'Saving…' : (isEditing ? 'Update Set' : 'Create Set')}
            </button>
          )}
        </div>
      </form>
    </Modal>
  );
}
