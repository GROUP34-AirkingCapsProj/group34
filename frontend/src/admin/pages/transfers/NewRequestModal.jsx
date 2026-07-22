import React, { useState, useEffect, useMemo } from 'react';
import Modal from '../../components/Modal';
import ProductSearchSelect from '../../components/ProductSearchSelect';
import { transfersAPI, locationsAPI, itemsAPI } from '../../services/api';
import { packageBreakdown } from '../../utils/packageFormat';
import { useAuth, useUserLocation } from '../../context/AuthContext';
import { getRoleKey, ROLES } from '../../utils/roles';
import { toast } from '../../utils/toast';

const todayIso = () => new Date().toISOString().slice(0, 10);
const newLine = () => ({ uid: `l-${Date.now()}-${Math.random().toString(36).slice(2, 6)}`, productId: '', quantity: 1, packages: 0, pieces: 0 });

const INTENTS = [
  { key: 'pull', label: 'Pull', desc: 'Request stock into your location' },
  { key: 'offload', label: 'Offload', desc: 'Send stock out of your location' },
  { key: 'demand', label: 'IA-demand', desc: 'Analyst-directed rebalancing', iaOnly: true },
];

/**
 * New transfer request modal. Intent selector drives which location selects
 * are shown; products come from the master catalog. Submits via transfersAPI.create.
 */
const NewRequestModal = ({ isOpen, onClose, onCreated }) => {
  const { user } = useAuth();
  const roleKey = getRoleKey(user);
  const isAdmin = roleKey === ROLES.ADMIN;
  const isIA = roleKey === ROLES.INVENTORY_ANALYST;
  const canDirect = isAdmin || isIA; // may pick both locations / use IA-demand
  const { locationId: myLocationId } = useUserLocation();

  const [intent, setIntent] = useState('pull');
  const [fromLocationId, setFromLocationId] = useState('');
  const [toLocationId, setToLocationId] = useState('');
  const [transferDate, setTransferDate] = useState(todayIso());
  const [notes, setNotes] = useState('');
  const [lines, setLines] = useState([newLine()]);
  const [submitting, setSubmitting] = useState(false);

  const [locations, setLocations] = useState([]);
  const [products, setProducts] = useState([]);
  const [loadingRefs, setLoadingRefs] = useState(true);

  useEffect(() => {
    if (!isOpen) return;
    setLoadingRefs(true);
    Promise.all([
      locationsAPI.getAll({ per_page: 500 }),
      itemsAPI.getAll({ per_page: 1000 }),
    ])
      .then(([locRes, prodRes]) => {
        setLocations(Array.isArray(locRes?.data) ? locRes.data : []);
        const rows = Array.isArray(prodRes?.data) ? prodRes.data : [];
        setProducts(rows.map((p) => ({
          id: String(p.product_id ?? p.id ?? ''),
          name: p.product_name || p.name || '',
          code: p.product_code || p.code || '',
          type: p.product_type || '',
          categoryId: String(p.category_id ?? ''),
          categoryName: p.category?.category_name || '',
          brandId: String(p.brand_id ?? ''),
          brandName: p.brand_name || '',
          piecesPerPackage: Number(p.pieces_per_package ?? 0) || 0,
          packageUnit: p.package_unit || '',
        })));
      })
      .catch((e) => toast.error('Could not load references', e?.message))
      .finally(() => setLoadingRefs(false));
  }, [isOpen]);

  // Reset form each time the modal opens.
  useEffect(() => {
    if (!isOpen) return;
    setIntent('pull');
    setFromLocationId('');
    setToLocationId(myLocationId ? String(myLocationId) : '');
    setTransferDate(todayIso());
    setNotes('');
    setLines([newLine()]);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen]);

  // Keep the implicit location aligned to intent for non-directors.
  useEffect(() => {
    if (canDirect) return;
    if (intent === 'pull') { setToLocationId(myLocationId ? String(myLocationId) : ''); setFromLocationId(''); }
    if (intent === 'offload') { setFromLocationId(myLocationId ? String(myLocationId) : ''); }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [intent, canDirect, myLocationId]);

  const availableIntents = useMemo(() => INTENTS.filter((i) => !i.iaOnly || canDirect), [canDirect]);

  const setLineField = (uid, field, value) =>
    setLines((prev) => prev.map((l) => (l.uid === uid ? { ...l, [field]: value } : l)));

  const productById = (pid) => products.find((p) => String(p.id) === String(pid));
  // A consumable that ships in packages of >1 piece → offer packages + loose pieces.
  const packSize = (l) => {
    const p = productById(l.productId);
    return p && p.type !== 'appliance' && p.piecesPerPackage > 1 ? p : null;
  };
  // Total requested in PIECES (the stored unit), from either the pkg/pieces pair or the single qty.
  const lineTotalPieces = (l) => {
    const p = packSize(l);
    if (p) return (parseInt(l.packages, 10) || 0) * p.piecesPerPackage + (parseInt(l.pieces, 10) || 0);
    return parseInt(l.quantity, 10) || 0;
  };
  const addLine = () => setLines((prev) => [...prev, newLine()]);
  const removeLine = (uid) => setLines((prev) => (prev.length > 1 ? prev.filter((l) => l.uid !== uid) : prev));

  const showFrom = intent === 'demand' || (intent === 'offload' && canDirect);
  // Offload destination is decided by the analyst at verification, so only
  // admin/IA (canDirect) may pre-select it here.
  const showTo = intent === 'demand' || ((intent === 'pull' || intent === 'offload') && canDirect);

  const handleSubmit = async () => {
    if (intent !== 'offload' && !toLocationId) { toast.error("Can't submit", 'Select a destination location.'); return; }
    if (intent !== 'pull' && !fromLocationId && showFrom) { toast.error("Can't submit", 'Select a source location.'); return; }
    const validLines = lines.filter((l) => l.productId && lineTotalPieces(l) > 0);
    if (validLines.length === 0) { toast.error("Can't submit", 'Add at least one product with a quantity.'); return; }

    const payload = {
      intent,
      to_location_id: toLocationId ? Number(toLocationId) : undefined,
      transfer_date: transferDate,
      notes: notes.trim() || undefined,
      products: validLines.map((l) => ({ product_id: Number(l.productId), quantity_requested: Math.max(1, lineTotalPieces(l)) })),
    };
    if (fromLocationId && intent !== 'pull') payload.from_location_id = Number(fromLocationId);

    setSubmitting(true);
    try {
      const res = await transfersAPI.create(payload);
      toast.success('Transfer request created', res?.data?.transfer_number || '');
      onCreated?.();
      onClose?.();
    } catch (err) {
      const msg = err?.errors ? Object.values(err.errors).flat().join(' ') : (err?.message || 'Failed to create transfer.');
      toast.error("Couldn't create transfer", msg);
    } finally {
      setSubmitting(false);
    }
  };

  const locOptions = locations.map((l) => (
    <option key={l.location_id} value={String(l.location_id)}>{l.location_name}</option>
  ));

  return (
    <Modal isOpen={isOpen} onClose={onClose} title="New Transfer Request" maxWidth={640}>
      <div className="ak-page" style={{ padding: 0 }}>
        {/* Intent selector */}
        <div className="ak-field" style={{ marginBottom: '1rem' }}>
          <span className="ak-label">Intent</span>
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            {availableIntents.map((i) => (
              <button
                key={i.key}
                type="button"
                className={`ak-btn${intent === i.key ? ' ak-btn--primary' : ''}`}
                onClick={() => setIntent(i.key)}
                title={i.desc}
              >
                {i.label}
              </button>
            ))}
          </div>
        </div>

        {/* Locations */}
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0.9rem', marginBottom: '1rem' }}>
          {showFrom && (
            <div className="ak-field">
              <span className="ak-label">Source</span>
              <select className="ak-select" value={fromLocationId} onChange={(e) => setFromLocationId(e.target.value)} disabled={loadingRefs}>
                <option value="">Select source…</option>
                {locOptions}
              </select>
            </div>
          )}
          {showTo && (
            <div className="ak-field">
              <span className="ak-label">Destination{intent === 'offload' ? ' (optional — else analyst assigns)' : ''}</span>
              <select className="ak-select" value={toLocationId} onChange={(e) => setToLocationId(e.target.value)} disabled={loadingRefs || (intent === 'pull' && !canDirect)}>
                <option value="">Select destination…</option>
                {locOptions}
              </select>
            </div>
          )}
          {!showFrom && intent === 'pull' && !canDirect && (
            <div className="ak-field">
              <span className="ak-label">Destination</span>
              <div className="tf-meta-value" style={{ padding: '0.5rem 0' }}>Your location</div>
            </div>
          )}
          {intent === 'offload' && !canDirect && (
            <div className="ak-field">
              <span className="ak-label">Source</span>
              <div className="tf-meta-value" style={{ padding: '0.5rem 0' }}>
                Your location · destination assigned by analyst
              </div>
            </div>
          )}
          <div className="ak-field">
            <span className="ak-label">Transfer date</span>
            <input type="date" className="ak-input" value={transferDate} onChange={(e) => setTransferDate(e.target.value)} />
          </div>
        </div>

        {/* Product lines */}
        <div className="ak-field" style={{ marginBottom: '0.5rem' }}>
          <span className="ak-label">Products</span>
        </div>
        {lines.map((l) => {
          const pack = packSize(l);
          const total = lineTotalPieces(l);
          return (
            <div key={l.uid} style={{ marginBottom: 8 }}>
              <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                <div style={{ flex: '1 1 auto', minWidth: 0 }}>
                  <ProductSearchSelect
                    products={products}
                    value={l.productId}
                    onChange={(pid) => setLineField(l.uid, 'productId', pid)}
                    disabled={loadingRefs}
                    placeholder={loadingRefs ? 'Loading…' : 'Search product…'}
                  />
                </div>
                {pack ? (
                  <>
                    <label className="tf-qty-field" title={`${pack.piecesPerPackage} pcs per ${pack.packageUnit || 'package'}`}>
                      <input type="number" min={0} className="ak-input" style={{ width: 64 }}
                        value={l.packages}
                        onChange={(e) => setLineField(l.uid, 'packages', e.target.value)} />
                      <span className="tf-qty-unit">{pack.packageUnit || 'pkg'}×{pack.piecesPerPackage}</span>
                    </label>
                    <label className="tf-qty-field">
                      <input type="number" min={0} className="ak-input" style={{ width: 56 }}
                        value={l.pieces}
                        onChange={(e) => setLineField(l.uid, 'pieces', e.target.value)} />
                      <span className="tf-qty-unit">pcs</span>
                    </label>
                  </>
                ) : (
                  <input
                    type="number" min={1} className="ak-input" style={{ width: 80 }}
                    value={l.quantity}
                    onChange={(e) => setLineField(l.uid, 'quantity', e.target.value)}
                  />
                )}
                <button type="button" className="ak-btn ak-btn--sm ak-btn--danger" onClick={() => removeLine(l.uid)} disabled={lines.length === 1} title="Remove line">×</button>
              </div>
              {pack && total > 0 && (
                <div className="ak-muted" style={{ fontSize: 11.5, marginTop: 2 }}>
                  = {total} pcs{packageBreakdown(total, pack.piecesPerPackage, pack.packageUnit) ? ` · ${packageBreakdown(total, pack.piecesPerPackage, pack.packageUnit)}` : ''}
                </div>
              )}
            </div>
          );
        })}
        <button type="button" className="ak-btn ak-btn--sm" onClick={addLine} style={{ marginTop: 4 }}>+ Add product</button>

        {/* Notes */}
        <div className="ak-field" style={{ marginTop: '1rem' }}>
          <span className="ak-label">Notes (optional)</span>
          <textarea className="ak-input" style={{ minHeight: 56, resize: 'vertical', fontFamily: 'inherit' }} value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Reason / context for this transfer…" />
        </div>

        <div className="tf-actions-row">
          <button type="button" className="ak-btn" onClick={onClose} disabled={submitting}>Cancel</button>
          <button type="button" className="ak-btn ak-btn--primary" onClick={handleSubmit} disabled={submitting || loadingRefs}>
            {submitting ? 'Submitting…' : 'Submit Request'}
          </button>
        </div>
      </div>
    </Modal>
  );
};

export default NewRequestModal;
