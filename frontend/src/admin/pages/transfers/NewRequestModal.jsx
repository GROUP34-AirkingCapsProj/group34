import React, { useState, useEffect, useMemo } from 'react';
import Modal from '../../components/Modal';
import ProductSearchSelect from '../../components/ProductSearchSelect';
import { transfersAPI, locationsAPI, itemsAPI } from '../../services/api';
import { packageBreakdown } from '../../utils/packageFormat';
import { useAuth, useUserLocation } from '../../context/AuthContext';
import { getRoleKey, ROLES } from '../../utils/roles';
import { toast } from '../../utils/toast';
import useTransferDraft, { readTransferDraft } from '../../hooks/useTransferDraft';

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
const seedLinesFromDetails = (dets, prods) => (dets || []).map((d) => {
  const p = prods.find((x) => String(x.id) === String(d.product_id));
  const ppp = p?.piecesPerPackage || 0;
  const total = Number(d.quantity_requested || 0);
  const base = { uid: `l-${d.transfer_detail_id}-${Math.random().toString(36).slice(2, 5)}`, productId: String(d.product_id) };
  if (p && p.type !== 'appliance' && ppp > 1) {
    return { ...base, quantity: 1, packages: Math.floor(total / ppp), pieces: total % ppp };
  }
  return { ...base, quantity: total || 1, packages: 0, pieces: 0 };
});

const NewRequestModal = ({ isOpen, onClose, onCreated, editTransfer = null, onSaved, onDraftSaved }) => {
  const isEdit = !!editTransfer;
  const resumable = isEdit;
  const { user } = useAuth();
  const roleKey = getRoleKey(user);
  const isAdmin = roleKey === ROLES.ADMIN;
  const isIA = roleKey === ROLES.INVENTORY_ANALYST;
  const canDirect = isAdmin || isIA; // may pick both locations / use IA-demand
  const { locationId: myLocationId } = useUserLocation();
  const userId = user?.user_id ?? user?.id ?? roleKey;
  const cachedDraft = useMemo(() => readTransferDraft(userId, editTransfer?.transfer_id), [userId, editTransfer?.transfer_id, isOpen]);

  const [intent, setIntent] = useState(canDirect ? 'demand' : 'pull');
  const [fromLocationId, setFromLocationId] = useState('');
  const [toLocationId, setToLocationId] = useState('');
  const [transferDate, setTransferDate] = useState(todayIso());
  const [notes, setNotes] = useState('');
  const [lines, setLines] = useState([newLine()]);
  const [submitting, setSubmitting] = useState(false);

  const [locations, setLocations] = useState([]);
  const [products, setProducts] = useState([]);
  const [loadingRefs, setLoadingRefs] = useState(true);
  const [stock, setStock] = useState(null);
  const [stockKey, setStockKey] = useState('');
  const [stockError, setStockError] = useState(false);
  const [stockRefresh, setStockRefresh] = useState(0);
  const demand = intent === 'demand' && (!isEdit || resumable);
  const productKey = JSON.stringify([...new Set(lines.map((l) => Number(l.productId)).filter(Boolean))].sort((a, b) => a - b));
  const stockReady = stock !== null && stockKey === productKey && !stockError;
  const form = useMemo(() => ({ intent, fromLocationId, toLocationId, transferDate, notes, lines }),
    [intent, fromLocationId, toLocationId, transferDate, notes, lines]);
  const draft = useTransferDraft({ userId, recordId: editTransfer?.transfer_id, isOpen,
    ready: !loadingRefs, form, recovered: cachedDraft, onSaved: onDraftSaved });
  const restoreForm = (saved) => {
    setIntent(saved.intent);
    setFromLocationId(saved.fromLocationId || '');
    setToLocationId(saved.toLocationId || '');
    setTransferDate(saved.transferDate || '');
    setNotes(saved.notes || '');
    setLines(saved.lines.map((line) => ({ ...line, productId: line.productId || '',
      quantity: line.quantity ?? '', packages: line.packages ?? '', pieces: line.pieces ?? '' })));
  };

  useEffect(() => {
    if (!isOpen || !demand || productKey === '[]') return;
    let active = true;
    setStock(null);
    setStockError(false);
    transfersAPI.demandAvailability(JSON.parse(productKey)).then((res) => {
      if (active) { setStock(res?.data || {}); setStockKey(productKey); }
    }).catch(() => { if (active) setStockError(true); });
    return () => { active = false; };
  }, [isOpen, demand, productKey, stockRefresh]);

  useEffect(() => {
    if (!isOpen) return;
    let active = true;
    setLoadingRefs(true);
    const savedForm = (cachedDraft?.dirty || !isEdit) ? cachedDraft?.form || editTransfer?.draft_form : editTransfer?.draft_form;
    if (savedForm) restoreForm(savedForm);
    Promise.all([
      locationsAPI.getAll({ per_page: 500 }),
      itemsAPI.getAll({ per_page: 1000 }),
    ])
      .then(([locRes, prodRes]) => {
        if (!active) return;
        setLocations(Array.isArray(locRes?.data) ? locRes.data : []);
        const rows = Array.isArray(prodRes?.data) ? prodRes.data : [];
        const mapped = rows.map((p) => ({
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
        }));
        setProducts(mapped);
        // Editing an existing request: seed lines/notes from it (locations/intent fixed).
        if (isEdit) {
          setIntent(editTransfer.intent || 'pull');
          setFromLocationId(editTransfer.from_location_id ? String(editTransfer.from_location_id) : '');
          setToLocationId(editTransfer.to_location_id ? String(editTransfer.to_location_id) : '');
          setTransferDate(editTransfer.transfer_date ? String(editTransfer.transfer_date).slice(0, 10) : todayIso());
          setNotes(editTransfer.notes || '');
          setLines(seedLinesFromDetails(editTransfer.details, mapped));
        }
        if (savedForm) restoreForm(savedForm);
      })
      .catch((e) => { if (active) toast.error('Could not load references', e?.message); })
      .finally(() => { if (active) setLoadingRefs(false); });
    return () => { active = false; };
  }, [isOpen, editTransfer?.transfer_id]); // eslint-disable-line react-hooks/exhaustive-deps

  // Reset form each time the modal opens (create mode only; edit seeds from the record).
  useEffect(() => {
    if (!isOpen || isEdit) return;
    setIntent(canDirect ? 'demand' : 'pull');
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

  const availableIntents = useMemo(
    () => INTENTS.filter((i) => isIA ? i.key === 'demand' : !i.iaOnly || canDirect),
    [isIA, canDirect],
  );

  const setLineField = (uid, field, value) =>
    setLines((prev) => prev.map((l) => {
      if (l.uid !== uid) return l;
      const updated = { ...l, [field]: value };
      if (demand && !isEdit && stockReady && fromLocationId && field !== 'productId') {
        const otherQuantity = prev.filter((other) => other.uid !== uid && other.productId === l.productId)
          .reduce((sum, other) => sum + lineTotalPieces(other), 0);
        const limit = Math.max(0, Number(stock[fromLocationId]?.[l.productId]?.available || 0) - otherQuantity);
        if (lineTotalPieces(updated) > limit) {
          if (limit === 0) {
            toast.error('No available stock', 'No stock is available for this product from the selected source.');
          } else {
            toast.error('Quantity limited', `Only ${limit} more pieces are available from this source.`);
          }
          return withTotal(updated, limit);
        }
      }
      return updated;
    }));

  const productById = (pid) => products.find((p) => String(p.id) === String(pid));
  // An installation material that ships in packages of >1 piece → offer packages + loose pieces.
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
  const withTotal = (l, total) => {
    const pack = packSize(l);
    return pack ? { ...l, packages: Math.floor(total / pack.piecesPerPackage), pieces: total % pack.piecesPerPackage }
      : { ...l, quantity: total };
  };
  useEffect(() => {
    if (!demand || isEdit || cachedDraft?.form || !stockReady || !fromLocationId) return;
    setLines((prev) => {
      const remaining = {};
      return prev.map((l) => {
        if (!l.productId) return l;
        if (!(l.productId in remaining)) remaining[l.productId] = Number(stock[fromLocationId]?.[l.productId]?.available || 0);
        const total = Math.max(0, Math.min(lineTotalPieces(l), remaining[l.productId]));
        remaining[l.productId] -= total;
        return withTotal(l, total);
      });
    });
    // Quantities are capped on input; this effect handles source/product/stock changes.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [demand, stockReady, stock, fromLocationId, productKey, products]);
  const demandBlocked = demand && (!stockReady || !fromLocationId || !toLocationId || fromLocationId === toLocationId
    || !lines.some((l) => l.productId && lineTotalPieces(l) > 0)
    || JSON.parse(productKey).some((pid) => lines.filter((l) => Number(l.productId) === pid)
      .reduce((sum, l) => sum + lineTotalPieces(l), 0) > Number(stock?.[fromLocationId]?.[pid]?.available || 0)));
  const addLine = () => setLines((prev) => [...prev, newLine()]);
  const removeLine = (uid) => setLines((prev) => (prev.length > 1 ? prev.filter((l) => l.uid !== uid) : prev));

  const showFrom = intent === 'demand' || (intent === 'offload' && canDirect);
  // Offload destination is decided by the analyst at verification, so only
  // admin/IA (canDirect) may pre-select it here.
  const showTo = intent === 'demand' || ((intent === 'pull' || intent === 'offload') && canDirect);

  const handleSubmit = async (resubmit = false) => {
    if (!transferDate) { toast.error("Can't submit", 'Choose a transfer date.'); return; }
    if (demandBlocked && (!isEdit || resubmit)) { toast.error("Can't submit", 'Select different locations and check available stock first.'); return; }
    if (!isEdit && intent !== 'offload' && !toLocationId) { toast.error("Can't submit", 'Select a destination location.'); return; }
    if (!isEdit && intent !== 'pull' && !fromLocationId && showFrom) { toast.error("Can't submit", 'Select a source location.'); return; }
    const validLines = lines.filter((l) => l.productId && lineTotalPieces(l) > 0);
    if (validLines.length === 0) { toast.error("Can't submit", 'Add at least one product with a quantity.'); return; }

    const productsPayload = validLines.map((l) => ({ product_id: Number(l.productId), quantity_requested: Math.max(1, lineTotalPieces(l)) }));
    draft.pause();
    setSubmitting(true);
    const draftId = await draft.flush();

    if (isEdit) {
      setSubmitting(true);
      try {
        await transfersAPI.updateRequest(editTransfer.transfer_id, { notes: notes.trim() || undefined, products: productsPayload,
          transfer_date: transferDate || undefined,
          ...(resumable && intent === 'demand' ? { from_location_id: Number(fromLocationId), to_location_id: Number(toLocationId) } : {}),
          ...(resubmit ? { resubmit: true } : {}),
        });
        draft.complete();
        toast.success('Request updated', editTransfer.transfer_number || '');
        onSaved?.();
        onClose?.();
      } catch (err) {
        const msg = err?.errors ? Object.values(err.errors).flat().join(' ') : (err?.message || 'Failed to update request.');
        toast.error("Couldn't update request", msg);
      } finally {
        draft.resume();
        setSubmitting(false);
      }
      return;
    }

    const payload = {
      intent,
      to_location_id: toLocationId ? Number(toLocationId) : undefined,
      transfer_date: transferDate,
      notes: notes.trim() || undefined,
      products: productsPayload,
      ...(draftId ? { draft_id: draftId } : {}),
    };
    if (fromLocationId && intent !== 'pull') payload.from_location_id = Number(fromLocationId);

    setSubmitting(true);
    try {
      const res = await transfersAPI.create(payload);
      draft.complete();
      toast.success('Transfer request created', res?.data?.transfer_number || '');
      onCreated?.();
      onClose?.();
    } catch (err) {
      const msg = err?.errors ? Object.values(err.errors).flat().join(' ') : (err?.message || 'Failed to create transfer.');
      toast.error("Couldn't create transfer", msg);
    } finally {
      draft.resume();
      setSubmitting(false);
    }
  };

  const locOptions = locations.map((l) => (
    <option key={l.location_id} value={String(l.location_id)}>{l.location_name}</option>
  ));

  return (
    <Modal isOpen={isOpen} onClose={async () => { await draft.flush(); onClose?.(); }} title={isEdit ? `Edit ${editTransfer.transfer_number || 'Request'}` : 'New Transfer Request'} maxWidth={640}>
      <div className="ak-page" style={{ padding: 0 }}>
        <fieldset disabled={submitting || loadingRefs} style={{ border: 0, padding: 0, margin: 0, minWidth: 0 }}>
        {(!isEdit || (resumable && (intent === 'demand' || editTransfer.draft_form))) && (<>
        {/* Intent selector */}
        {!canDirect && (
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
        )}

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
        </>)}

        {/* Product lines */}
        {demand && productKey !== '[]' && (
          <div style={{ marginBottom: 12, overflowX: 'auto' }}>
            {stockError ? <p>Availability unavailable. Refresh stock to retry.</p> : !stockReady ? <p>Checking stock…</p> : (
              <table className="ak-table">
                <thead><tr><th>Product</th>{locations.filter((loc) => loc.location_type === 'warehouse' && String(loc.location_id) !== toLocationId)
                  .map((loc) => <th key={loc.location_id}>{loc.location_name}</th>)}</tr></thead>
                <tbody>{JSON.parse(productKey).map((pid) => <tr key={pid}>
                  <td>{productById(pid)?.name}</td>
                  {locations.filter((loc) => loc.location_type === 'warehouse' && String(loc.location_id) !== toLocationId)
                    .map((loc) => <td key={loc.location_id}>{Number(stock[loc.location_id]?.[pid]?.available || 0)} available</td>)}
                </tr>)}</tbody>
              </table>
            )}
            <p className="ak-muted">Quantities are limited to source stock. Items with zero available stock are excluded.</p>
            <button type="button" className="ak-btn ak-btn--sm" disabled={submitting}
              onClick={() => setStockRefresh((value) => value + 1)}>Refresh stock</button>
          </div>
        )}
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
                    type="number" min={demand ? 0 : 1} className="ak-input" style={{ width: 80 }}
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
          <span role="status" className="ak-muted">{draft.status || 'Changes are saved automatically as a draft.'}</span>
          <button type="button" className="ak-btn" onClick={async () => { await draft.flush(); onClose?.(); }} disabled={submitting}>Close</button>
          <button type="button" className="ak-btn ak-btn--primary" onClick={() => handleSubmit(isEdit)} disabled={submitting || loadingRefs || demandBlocked}>
            {submitting ? 'Submitting…' : (isEdit ? 'Submit again' : 'Submit Request')}
          </button>
        </div>
        </fieldset>
      </div>
    </Modal>
  );
};

export default NewRequestModal;
