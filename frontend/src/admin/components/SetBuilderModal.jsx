import React, { useEffect, useMemo, useState } from 'react';
import Modal from './Modal';
import { productsAPI, productSetsAPI } from '../services/api';
import { packageBreakdown, piecesToPackages } from '../utils/packageFormat';
import { toast } from '../utils/toast';

const formatMoney = (n) =>
  '₱' + (Number(n) || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

// Quick-pick capacities; free-entry still allowed via the datalist.
const CAPACITY_OPTIONS = ['1HP', '1.5HP', '2HP', '2.5HP', '3HP', '1TR', '1.5TR', '2TR', '2.5TR', '3TR'];

let lineSeq = 0;
const newLine = () => ({
  key: `ln-${Date.now()}-${lineSeq++}`,
  product_id: '',
  quantity: 1, // used when ppp <= 1 (single pieces input)
  packages: 0, // used when ppp > 1
  pieces: 0,   // used when ppp > 1 (loose pieces)
  spec_note: '',
});

/**
 * Header + line editor for authoring a Product Set (BOQ template).
 *
 * A Set = many brands + one capacity + one type (inverter/non-inverter).
 * Line quantities are stored in PIECES; the editor offers a pack/box entry
 * (packages × pieces_per_package + loose pieces) for products that ship in
 * packages, mirroring the Transfers request modal.
 *
 * Props:
 *   isOpen, onClose
 *   initialSet   {object|null}  set being edited, or a prefilled duplicate (set_id absent = new)
 *   brands       {array}        [{ brand_id, brand_name }]  all aircon brands
 *   onSaved      {() => void}   called after a successful create/update
 *   canSubmit    {boolean}      whether the form should render its Save button
 */
export default function SetBuilderModal({ isOpen, onClose, initialSet, brands, onSaved, canSubmit }) {
  const [brandIds, setBrandIds] = useState([]); // array of numbers
  const [variant, setVariant] = useState('');
  const [capacity, setCapacity] = useState('');
  const [lines, setLines] = useState([newLine()]);
  const [products, setProducts] = useState([]);
  const [loadingProducts, setLoadingProducts] = useState(true);
  const [productFilter, setProductFilter] = useState('');
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
      setBrandIds((initialSet.brands || [])
        .map((b) => Number(b.brand_id))
        .filter((n) => Number.isFinite(n)));
      setVariant(initialSet.variant || '');
      setCapacity(initialSet.capacity_rating || '');
      const initLines = (initialSet.lines || [])
        .slice()
        .sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0))
        .map((l) => {
          const ppp = Number(l.pieces_per_package) || 0;
          const totalPieces = Number(l.quantity) || 0;
          const { boxes, loose } = piecesToPackages(totalPieces, ppp);
          return {
            key: `ln-${l.set_line_id ?? `${Date.now()}-${lineSeq++}`}`,
            product_id: l.product_id != null ? String(l.product_id) : '',
            // Seed both representations from the stored piece count.
            quantity: totalPieces || 1,
            packages: ppp > 1 ? boxes : 0,
            pieces: ppp > 1 ? loose : 0,
            spec_note: l.spec_note || '',
            _product_name: l.product_name,
            _unit_price: l.unit_price,
            _ppp: ppp,
            _package_unit: l.package_unit,
          };
        });
      setLines(initLines.length ? initLines : [newLine()]);
    } else {
      setBrandIds([]);
      setVariant('');
      setCapacity('');
      setLines([newLine()]);
    }
    setProductFilter('');
  }, [isOpen, initialSet]); // eslint-disable-line react-hooks/exhaustive-deps

  const productById = useMemo(() => {
    const m = new Map();
    products.forEach((p) => m.set(String(p.product_id), p));
    return m;
  }, [products]);

  // pieces_per_package for a line: prefer the loaded product, fall back to the
  // value seeded from the set (so pack/box entry works before products load).
  const pppFor = (line) => {
    const p = productById.get(line.product_id);
    return Number(p?.pieces_per_package ?? line._ppp ?? 0) || 0;
  };
  const packageUnitFor = (line) => {
    const p = productById.get(line.product_id);
    return p?.package_unit ?? line._package_unit ?? '';
  };

  // Total quantity for a line in PIECES (the stored unit).
  const lineTotalPieces = (line) => {
    const ppp = pppFor(line);
    if (ppp > 1) return (parseInt(line.packages, 10) || 0) * ppp + (parseInt(line.pieces, 10) || 0);
    return parseInt(line.quantity, 10) || 0;
  };

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

  const toggleBrand = (id) =>
    setBrandIds((prev) => (prev.includes(id) ? prev.filter((b) => b !== id) : [...prev, id]));

  const addLine = () => setLines((prev) => [...prev, newLine()]);
  const removeLine = (key) => setLines((prev) => (prev.length > 1 ? prev.filter((l) => l.key !== key) : prev));
  const updateLine = (key, field, value) =>
    setLines((prev) => prev.map((l) => (l.key === key ? { ...l, [field]: value } : l)));

  const handleProductChange = (key, productId) => {
    setLines((prev) => prev.map((l) => (
      l.key === key ? { ...l, product_id: productId, quantity: 1, packages: 0, pieces: 0 } : l
    )));
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
    const packagePrice = Number(p?.unit_price ?? line._unit_price ?? 0);
    const piecesPerPackage = Math.max(1, pppFor(line));
    return (packagePrice / piecesPerPackage) * lineTotalPieces(line);
  };

  const runningTotal = useMemo(
    () => lines.reduce((sum, l) => sum + lineTotal(l), 0),
    [lines, products] // eslint-disable-line react-hooks/exhaustive-deps
  );

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!canSubmit) return;
    if (!brandIds.length) {
      toast.error('Select at least one brand for this set.');
      return;
    }
    // Never filter bad lines out silently. A BOQ runs to ~18 lines; dropping one
    // because its quantity was left blank would save an incomplete set with no
    // hint that anything was missing. Name the offending line instead.
    const complete = (l) => l.product_id && lineTotalPieces(l) > 0;
    const blank = (l) => !l.product_id && lineTotalPieces(l) <= 0;

    const filledLines = lines.filter((l) => !blank(l));
    if (!filledLines.length) {
      toast.error('Add at least one line with a product and quantity.');
      return;
    }

    const firstBadIndex = filledLines.findIndex((l) => !complete(l));
    if (firstBadIndex !== -1) {
      const bad = filledLines[firstBadIndex];
      const label = products.find((p) => String(p.product_id) === String(bad.product_id))?.product_name;
      toast.error(
        label
          ? `Line ${firstBadIndex + 1} (${label}) needs a quantity greater than zero.`
          : `Line ${firstBadIndex + 1} needs a product and a quantity greater than zero.`
      );
      return;
    }

    const validLines = filledLines;

    const payload = {
      brand_ids: brandIds.map((n) => Number(n)),
      capacity_rating: capacity.trim() || null,
      variant: variant || null,
      lines: validLines.map((l, i) => ({
        product_id: Number(l.product_id),
        quantity: Math.max(1, lineTotalPieces(l)),
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
      // Surface whichever field the server actually rejected. Falling back to
      // err.message alone would show Laravel's generic "The given data was
      // invalid." and hide the real reason.
      const firstFieldMessage = Object.values(fieldErrors).flat().find(Boolean);
      toast.error(firstFieldMessage || err.message || 'Failed to save the set.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Modal isOpen={isOpen} onClose={onClose} title={isEditing ? 'Edit Set' : 'New Set'} maxWidth={920}>
      <form onSubmit={handleSubmit} className="ak-field" style={{ gap: '1.1rem', paddingTop: '1rem' }}>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: '0.85rem', alignItems: 'start' }}>
          <div className="ak-field" style={{ gridColumn: '1 / -1' }}>
            <label className="ak-label">Brands</label>
            <div
              style={{
                display: 'flex', flexWrap: 'wrap', gap: '0.35rem 0.9rem',
                border: '1px solid var(--ak-line)', borderRadius: 8, padding: '0.55rem 0.7rem',
                maxHeight: 130, overflowY: 'auto',
              }}
            >
              {brands.length === 0 && <span className="ak-muted" style={{ fontSize: '0.82rem' }}>No brands available</span>}
              {brands.map((b) => (
                <label key={b.brand_id} style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', fontSize: '0.85rem', whiteSpace: 'nowrap' }}>
                  <input
                    type="checkbox"
                    checked={brandIds.includes(Number(b.brand_id))}
                    onChange={() => toggleBrand(Number(b.brand_id))}
                    disabled={!canSubmit}
                  />
                  {b.brand_name}
                </label>
              ))}
            </div>
            {errors.brand_ids?.[0] && <span style={{ color: 'var(--ink-red)', fontSize: '0.78rem' }}>{errors.brand_ids[0]}</span>}
          </div>

          <div className="ak-field">
            <label className="ak-label">Type</label>
            <select
              className="ak-select"
              value={variant}
              onChange={(e) => setVariant(e.target.value)}
              disabled={!canSubmit}
            >
              <option value="">Any type</option>
              <option value="inverter">Inverter</option>
              <option value="non-inverter">Non-inverter</option>
            </select>
          </div>

          <div className="ak-field">
            <label className="ak-label">Capacity</label>
            <input
              className="ak-input"
              list="set-capacity-options"
              value={capacity}
              maxLength={60}
              placeholder="e.g. 1.5HP"
              onChange={(e) => setCapacity(e.target.value)}
              disabled={!canSubmit}
            />
            <datalist id="set-capacity-options">
              {CAPACITY_OPTIONS.map((c) => <option key={c} value={c} />)}
            </datalist>
          </div>
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
                  <th style={{ width: 240 }}>Product</th>
                  <th style={{ width: 190 }}>Quantity</th>
                  <th>Spec note</th>
                  <th style={{ width: 90 }}>Line total</th>
                  <th style={{ width: 40 }}></th>
                </tr>
              </thead>
              <tbody>
                {lines.map((l, i) => {
                  const ppp = pppFor(l);
                  const packMode = ppp > 1;
                  const total = lineTotalPieces(l);
                  const unit = packageUnitFor(l);
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
                            aria-label={`Move line ${i + 1} up`}
                          >
                            <span aria-hidden="true">{'▲'}</span>
                          </button>
                          <button
                            type="button"
                            className="ak-btn ak-btn--ghost ak-btn--sm"
                            style={{ padding: '0 4px' }}
                            disabled={!canSubmit || i === lines.length - 1}
                            onClick={() => moveLine(i, 1)}
                            title="Move down"
                            aria-label={`Move line ${i + 1} down`}
                          >
                            <span aria-hidden="true">{'▼'}</span>
                          </button>
                        </div>
                      </td>
                      <td>
                        <select
                          className="ak-select"
                          style={{ width: '100%', minWidth: 160, maxWidth: 240 }}
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
                        {packMode ? (
                          <>
                            <div style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
                              <input
                                type="number"
                                className="ak-input"
                                style={{ width: 70 }}
                                min="0"
                                value={l.packages}
                                onChange={(e) => updateLine(l.key, 'packages', e.target.value)}
                                disabled={!canSubmit}
                                title={`${ppp} pcs per ${unit || 'package'}`}
                              />
                              <span className="ak-muted" style={{ fontSize: 11 }}>{unit || 'pkg'}×{ppp}</span>
                              <input
                                type="number"
                                className="ak-input"
                                style={{ width: 70 }}
                                min="0"
                                value={l.pieces}
                                onChange={(e) => updateLine(l.key, 'pieces', e.target.value)}
                                disabled={!canSubmit}
                                title="Loose pieces"
                              />
                              <span className="ak-muted" style={{ fontSize: 11 }}>pcs</span>
                            </div>
                            {total > 0 && (
                              <div className="ak-muted" style={{ fontSize: 11, marginTop: 2 }}>
                                = {total} pcs{packageBreakdown(total, ppp, unit) ? ` · ${packageBreakdown(total, ppp, unit)}` : ''}
                              </div>
                            )}
                          </>
                        ) : (
                          <input
                            type="number"
                            className="ak-input"
                            style={{ width: 90 }}
                            min="1"
                            value={l.quantity}
                            onChange={(e) => updateLine(l.key, 'quantity', e.target.value)}
                            disabled={!canSubmit}
                          />
                        )}
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
                            aria-label={`Remove line ${i + 1}`}
                          >
                            <span aria-hidden="true">{'✕'}</span>
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
