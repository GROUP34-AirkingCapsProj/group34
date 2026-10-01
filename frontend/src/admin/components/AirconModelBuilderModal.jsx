import React, { useEffect, useMemo, useState } from 'react';
import Modal from './Modal';
import { productsAPI, airconModelsAPI, acTypesAPI, capacitiesAPI, variantsAPI } from '../services/api';
import { toast } from '../utils/toast';

const formatMoney = (n) =>
  '₱' + (Number(n) || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const ROLE_LABEL = {
  indoor: 'indoor',
  outdoor: 'outdoor',
  complete: 'complete',
};

/**
 * First message from any `lines`-rooted validation key — `lines` itself
 * (piece-count rule) or `lines.N.product_id` (duplicate product). Both describe
 * the composition rather than a header field.
 */
const linesMessage = (fieldErrors) =>
  Object.entries(fieldErrors || {})
    .filter(([key]) => key === 'lines' || key.startsWith('lines.'))
    .flatMap(([, messages]) => (Array.isArray(messages) ? messages : [messages]))
    .find(Boolean);

let lineSeq = 0;
const newLine = () => ({
  key: `unit-${Date.now()}-${lineSeq++}`,
  product_id: '',
  quantity: 1,
});

/**
 * Header + unit-line editor for authoring an Aircon Model.
 *
 * An aircon model is the sellable machine: one brand, one AC type, one
 * capacity/variant, plus the appliance products that physically make it up.
 * A two-piece model is composed of an indoor and an outdoor product; a
 * one-piece model is a single 'complete' product.
 *
 * Props:
 *   isOpen, onClose
 *   initialModel  {object|null}  model being edited (aircon_model_id absent = new)
 *   brands        {array}        [{ brand_id, brand_name }]
 *   onSaved       {() => void}   called after a successful create/update
 *   canSubmit     {boolean}      whether the form should render its Save button
 */
export default function AirconModelBuilderModal({ isOpen, onClose, initialModel, brands, onSaved, canSubmit }) {
  const [modelName, setModelName] = useState('');
  const [brandId, setBrandId] = useState('');
  const [acTypeId, setAcTypeId] = useState('');
  const [capacity, setCapacity] = useState('');
  const [variant, setVariant] = useState('');
  const [isTwoPiece, setIsTwoPiece] = useState(true);
  const [isActive, setIsActive] = useState(true);
  const [lines, setLines] = useState([newLine()]);

  const [products, setProducts] = useState([]);
  const [loadingProducts, setLoadingProducts] = useState(true);
  const [acTypes, setAcTypes] = useState([]);
  const [capacities, setCapacities] = useState([]);
  const [variants, setVariants] = useState([]);
  const [productFilter, setProductFilter] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [errors, setErrors] = useState({});

  const isEditing = !!initialModel?.aircon_model_id;

  useEffect(() => {
    if (!isOpen) return;
    setLoadingProducts(true);
    productsAPI.getAll({ per_page: 1000, product_type: 'appliance' })
      .then((r) => setProducts(Array.isArray(r?.data) ? r.data : []))
      .catch(() => setProducts([]))
      .finally(() => setLoadingProducts(false));
    acTypesAPI.getAll()
      .then((r) => setAcTypes(Array.isArray(r?.data) ? r.data : []))
      .catch(() => setAcTypes([]));
    capacitiesAPI.getAll()
      .then((r) => setCapacities(Array.isArray(r?.data) ? r.data : []))
      .catch(() => setCapacities([]));
    variantsAPI.getAll()
      .then((r) => setVariants(Array.isArray(r?.data) ? r.data : []))
      .catch(() => setVariants([]));
  }, [isOpen]);

  useEffect(() => {
    if (!isOpen) return;
    setErrors({});
    if (initialModel) {
      setModelName(initialModel.model_name || '');
      setBrandId(initialModel.brand_id != null ? String(initialModel.brand_id) : '');
      setAcTypeId(initialModel.ac_type_id != null ? String(initialModel.ac_type_id) : '');
      setCapacity(initialModel.capacity_rating || '');
      setVariant(initialModel.variant || '');
      setIsTwoPiece(initialModel.is_two_piece !== false);
      setIsActive(initialModel.is_active !== false);
      const initLines = (initialModel.units || [])
        .slice()
        .sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0))
        .map((u) => ({
          key: `unit-${u.aircon_model_unit_id ?? `${Date.now()}-${lineSeq++}`}`,
          product_id: u.product_id != null ? String(u.product_id) : '',
          quantity: Number(u.quantity) || 1,
          _product_name: u.product_name,
          _unit_price: u.unit_price,
          _unit_role: u.unit_role,
        }));
      setLines(initLines.length ? initLines : [newLine()]);
    } else {
      setModelName('');
      setBrandId('');
      setAcTypeId('');
      setCapacity('');
      setVariant('');
      setIsTwoPiece(true);
      setIsActive(true);
      setLines([newLine()]);
    }
    setProductFilter('');
  }, [isOpen, initialModel]); // eslint-disable-line react-hooks/exhaustive-deps

  const productById = useMemo(() => {
    const m = new Map();
    products.forEach((p) => m.set(String(p.product_id), p));
    return m;
  }, [products]);

  // Only appliances can be members of an aircon model — an installation
  // material has no unit_role and could never satisfy the composer's rule.
  const applianceProducts = useMemo(
    () => products.filter((p) => p.product_type === 'appliance'),
    [products]
  );

  const filteredProducts = useMemo(() => {
    const q = productFilter.trim().toLowerCase();
    if (!q) return applianceProducts;
    return applianceProducts.filter((p) =>
      String(p.product_name || '').toLowerCase().includes(q) ||
      String(p.product_code || '').toLowerCase().includes(q) ||
      String(p.unit_role || '').toLowerCase().includes(q)
    );
  }, [applianceProducts, productFilter]);

  const roleOf = (line) => productById.get(line.product_id)?.unit_role ?? line._unit_role ?? '';

  /**
   * Options for one line's picker.
   *
   * Two things have to stay selectable that the plain list would drop: a
   * product the current filter text excludes, and — the case the filter cannot
   * fix — a member product archived after the model was composed, which
   * productsAPI.getAll never returns at all. Without the synthetic entry the
   * <select> falls back to its placeholder and the row reads as empty, which
   * looks like data loss and invites the user to "repair" it by picking a
   * different product.
   */
  const optionsForLine = (line) => {
    if (!line.product_id) return filteredProducts;
    if (filteredProducts.some((p) => String(p.product_id) === line.product_id)) return filteredProducts;

    const loaded = productById.get(line.product_id);
    if (loaded) return [loaded, ...filteredProducts];

    return [{
      product_id: line.product_id,
      product_name: line._product_name || `Product #${line.product_id}`,
      unit_role: line._unit_role,
      __missing: true,
    }, ...filteredProducts];
  };

  const unitPriceOf = (line) => Number(productById.get(line.product_id)?.unit_price ?? line._unit_price ?? 0);

  const lineTotal = (line) => unitPriceOf(line) * (parseInt(line.quantity, 10) || 0);

  const runningTotal = useMemo(
    () => lines.reduce((sum, l) => sum + lineTotal(l), 0),
    [lines, products] // eslint-disable-line react-hooks/exhaustive-deps
  );

  /**
   * Changing the AC type re-applies that type's `is_two_piece` default and
   * DISCARDS any manual toggle made before the change. Leaving a model marked
   * one-piece under Split Type would fail the composer's check on save anyway;
   * better to correct it when the type changes than to reject the save.
   * Do not "fix" this to preserve the manual toggle.
   */
  const handleAcTypeChange = (nextId) => {
    const chosen = acTypes.find((t) => String(t.ac_type_id) === String(nextId));
    setAcTypeId(nextId);
    if (chosen) setIsTwoPiece(!!chosen.is_two_piece);
  };

  const addLine = () => setLines((prev) => [...prev, newLine()]);
  const removeLine = (key) => setLines((prev) => (prev.length > 1 ? prev.filter((l) => l.key !== key) : prev));
  const updateLine = (key, field, value) =>
    setLines((prev) => prev.map((l) => (l.key === key ? { ...l, [field]: value } : l)));

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!canSubmit) return;

    if (!modelName.trim()) {
      toast.error('Give the aircon model a name.');
      return;
    }

    const filledLines = lines.filter((l) => l.product_id);
    if (!filledLines.length) {
      toast.error('Add at least one unit to this aircon model.');
      return;
    }

    // Mirror of the composer's piece-count rule, so the user hears about it
    // without a round trip. The server still enforces it — this is a courtesy,
    // not the guarantee.
    //
    // roleOf, NOT productById directly: productsAPI.getAll runs under the
    // SoftDeletes scope, so a member product archived after the model was
    // composed is absent from the loaded list. The server supports exactly that
    // case (AirconModelUnit::product() uses withTrashed()), so reading the role
    // off the product list alone would reject a save the composer accepts and
    // leave the model permanently uneditable here. roleOf falls back to the
    // role the model row already carried.
    const roles = filledLines.map((l) => roleOf(l)).filter(Boolean);

    if (isTwoPiece && !(roles.includes('indoor') && roles.includes('outdoor'))) {
      toast.error('A two-piece aircon model needs at least one indoor and one outdoor unit.');
      return;
    }

    if (!isTwoPiece && (filledLines.length !== 1 || !roles.includes('complete'))) {
      toast.error('A one-piece aircon model takes exactly one complete unit.');
      return;
    }

    const payload = {
      model_name: modelName.trim(),
      brand_id: brandId ? Number(brandId) : null,
      ac_type_id: acTypeId ? Number(acTypeId) : null,
      capacity_rating: capacity.trim() || null,
      variant: variant.trim() || null,
      is_two_piece: isTwoPiece,
      is_active: isActive,
      lines: filledLines.map((l) => ({
        product_id: Number(l.product_id),
        quantity: Math.max(1, parseInt(l.quantity, 10) || 1),
      })),
    };

    setSubmitting(true);
    setErrors({});
    try {
      if (isEditing) {
        await airconModelsAPI.update(initialModel.aircon_model_id, payload);
        toast.success('Aircon model updated.');
      } else {
        await airconModelsAPI.create(payload);
        toast.success('Aircon model created.');
      }
      onSaved?.();
    } catch (err) {
      const fieldErrors = err.errors || {};
      setErrors(fieldErrors);
      // The composer's 422s land on two different keys — `lines` for the
      // piece-count rule, `lines.0.product_id` for a duplicated product — and
      // both messages are already written for the user. Prefer whichever of
      // those came back over any header-field message: Laravel preserves rule
      // order and validatePayload() declares model_name/brand_id/ac_type_id
      // BEFORE the line rules, so a plain "first key wins" would show the
      // header error and bury the composition problem — and the header errors
      // already render inline beside their own field.
      const message = linesMessage(fieldErrors)
        || Object.values(fieldErrors).flat().find(Boolean);
      toast.error(message || err.message || 'Failed to save the aircon model.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={isEditing ? 'Edit Aircon Model' : 'New Aircon Model'}
      maxWidth={860}
    >
      <form onSubmit={handleSubmit} className="ak-field" style={{ gap: '1.1rem', paddingTop: '1rem' }}>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: '0.85rem', alignItems: 'start' }}>
          <div className="ak-field" style={{ gridColumn: '1 / -1' }}>
            <label className="ak-label" htmlFor="am-model-name">Model name</label>
            <input
              id="am-model-name"
              className="ak-input"
              value={modelName}
              maxLength={191}
              placeholder="e.g. Airking 1.5HP Inverter Split"
              onChange={(e) => setModelName(e.target.value)}
              disabled={!canSubmit}
            />
            {errors.model_name?.[0] && (
              <span style={{ color: 'var(--ink-red)', fontSize: '0.78rem' }}>{errors.model_name[0]}</span>
            )}
          </div>

          <div className="ak-field">
            <label className="ak-label" htmlFor="am-brand">Brand</label>
            <select
              id="am-brand"
              className="ak-select"
              value={brandId}
              onChange={(e) => setBrandId(e.target.value)}
              disabled={!canSubmit}
            >
              <option value="">No brand</option>
              {(brands || []).map((b) => (
                <option key={b.brand_id} value={String(b.brand_id)}>{b.brand_name}</option>
              ))}
            </select>
          </div>

          <div className="ak-field">
            <label className="ak-label" htmlFor="am-ac-type">AC type</label>
            <select
              id="am-ac-type"
              className="ak-select"
              value={acTypeId}
              onChange={(e) => handleAcTypeChange(e.target.value)}
              disabled={!canSubmit}
            >
              <option value="">No AC type</option>
              {acTypes.map((t) => (
                <option key={t.ac_type_id} value={String(t.ac_type_id)}>{t.ac_type_name}</option>
              ))}
            </select>
          </div>

          <div className="ak-field">
            <label className="ak-label" htmlFor="am-capacity">Capacity</label>
            <select
              id="am-capacity"
              className="ak-select"
              value={capacity}
              onChange={(e) => setCapacity(e.target.value)}
              disabled={!canSubmit}
            >
              <option value="">Any capacity</option>
              {capacities.map((c) => (
                <option key={c.capacity_id} value={c.capacity_value}>{c.capacity_value}</option>
              ))}
            </select>
          </div>

          <div className="ak-field">
            <label className="ak-label" htmlFor="am-variant">Variant</label>
            <select
              id="am-variant"
              className="ak-select"
              value={variant}
              onChange={(e) => setVariant(e.target.value)}
              disabled={!canSubmit}
            >
              <option value="">Any variant</option>
              {variants.map((v) => (
                <option key={v.variant_id} value={v.variant_name}>{v.variant_name}</option>
              ))}
            </select>
          </div>

          <div className="ak-field">
            <label className="ak-label">Composition</label>
            <label style={{ display: 'flex', alignItems: 'center', gap: '0.45rem', fontSize: '0.85rem' }}>
              <input
                type="checkbox"
                checked={isTwoPiece}
                onChange={(e) => setIsTwoPiece(e.target.checked)}
                disabled={!canSubmit}
              />
              Two-piece (indoor + outdoor)
            </label>
            <label style={{ display: 'flex', alignItems: 'center', gap: '0.45rem', fontSize: '0.85rem' }}>
              <input
                type="checkbox"
                checked={isActive}
                onChange={(e) => setIsActive(e.target.checked)}
                disabled={!canSubmit}
              />
              Active
            </label>
          </div>
        </div>

        <div>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '0.5rem' }}>
            <label className="ak-label" style={{ margin: 0 }}>Units</label>
            {/* The appliance catalogue runs to a couple of hundred rows and
                ProductController caps nothing, so every one of them lands in
                each native <select>. Same filter SetBuilderModal offers, for
                the same reason. */}
            <div className="ak-search" style={{ minWidth: 220 }}>
              <input
                className="ak-input"
                aria-label="Filter products"
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
                  <th style={{ width: 300 }}>Product</th>
                  <th style={{ width: 110 }}>Quantity</th>
                  <th style={{ width: 110 }}>Line total</th>
                  <th style={{ width: 40 }}></th>
                </tr>
              </thead>
              <tbody>
                {lines.map((l, i) => (
                  <tr key={l.key}>
                    <td>
                      <select
                        className="ak-select"
                        style={{ width: '100%', minWidth: 180 }}
                        aria-label={`Unit ${i + 1} product`}
                        value={l.product_id}
                        onChange={(e) => updateLine(l.key, 'product_id', e.target.value)}
                        disabled={!canSubmit || loadingProducts}
                      >
                        <option value="">{loadingProducts ? 'Loading products…' : 'Select appliance…'}</option>
                        {optionsForLine(l).map((p) => (
                          <option key={p.product_id} value={String(p.product_id)}>
                            {p.product_name}
                            {p.unit_role ? ` — ${ROLE_LABEL[p.unit_role] || p.unit_role}` : ''}
                            {p.product_code ? ` (${p.product_code})` : ''}
                            {p.__missing ? ' (archived)' : ''}
                          </option>
                        ))}
                      </select>
                      {roleOf(l) && (
                        <div className="ak-muted" style={{ fontSize: 11, marginTop: 2 }}>
                          Role: {ROLE_LABEL[roleOf(l)] || roleOf(l)}
                        </div>
                      )}
                    </td>
                    <td>
                      <input
                        type="number"
                        className="ak-input"
                        style={{ width: 90 }}
                        min="1"
                        aria-label={`Unit ${i + 1} quantity`}
                        value={l.quantity}
                        onChange={(e) => updateLine(l.key, 'quantity', e.target.value)}
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
                          title="Remove unit"
                          aria-label={`Remove unit ${i + 1}`}
                        >
                          <span aria-hidden="true">{'✕'}</span>
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {/* The composer's rejection is about the composition as a whole, not
              about one field, so it has no input to sit beside — it gets its
              own line under the table. Header-field errors render next to
              their own input; between the two, every key the server can
              realistically return reaches the user somewhere other than a
              toast that scrolls away. */}
          {linesMessage(errors) && (
            <p style={{ color: 'var(--ink-red)', fontSize: '0.78rem', margin: '0.5rem 0 0' }}>{linesMessage(errors)}</p>
          )}

          {canSubmit && (
            <button type="button" className="ak-btn ak-btn--sm" style={{ marginTop: '0.6rem' }} onClick={addLine}>
              + Add unit
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
              {submitting ? 'Saving…' : (isEditing ? 'Update Model' : 'Create Model')}
            </button>
          )}
        </div>
      </form>
    </Modal>
  );
}
