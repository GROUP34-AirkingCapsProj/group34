import React, { useState, useEffect, useCallback, useRef, useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import { barcodeAdjustmentAPI, productsAPI, locationsAPI, purchaseOrdersAPI, categoriesAPI } from '../services/api';
import { toast } from '../utils/toast';
import { useAuth } from '../context/AuthContext';
import '../styles/barcode_scan.css';
import '../styles/po_receiving.css';

const CORRECTION_TYPES = [
  { value: 'wrong_product',   label: 'Wrong Product' },
  { value: 'wrong_quantity',  label: 'Wrong Quantity' },
  { value: 'duplicate_scan',  label: 'Duplicate Scan' },
  { value: 'wrong_serial',    label: 'Wrong Serial' },
  { value: 'void_scan',       label: 'Void Scan' },
];

const PRESET_REASONS = [
  'Barcode scanned against wrong product during receiving.',
  'Serial number was misread — correct serial applied.',
  'Duplicate scan detected; one entry removed.',
  'Item quantity was entered incorrectly during receiving.',
  'Wrong product category selected at time of scan.',
];

const fmt = (ts) =>
  ts ? new Date(ts).toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true }) : '—';
const fmtDate = (d) =>
  d ? new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '—';

const TypeBadge = ({ type }) => {
  const isSerialized = type === 'appliance';
  return (
    <span style={{
      display: 'inline-block', padding: '2px 7px', borderRadius: 4, fontSize: 10, fontWeight: 700,
      background: isSerialized ? '#eff6ff' : '#f0fdf4',
      color: isSerialized ? '#2563eb' : '#15803d',
    }}>
      {isSerialized ? 'Appliance' : 'Consumable'}
    </span>
  );
};

const Label = ({ children }) => (
  <label style={{ display: 'block', fontSize: 11, fontWeight: 700, color: '#9ca3af', letterSpacing: 0.5, textTransform: 'uppercase', marginBottom: 6 }}>
    {children}
  </label>
);

const inputStyle = (highlight) => ({
  width: '100%', padding: '10px 13px', border: `1px solid ${highlight ? '#10b981' : '#d1d5db'}`,
  borderRadius: 8, fontSize: 13, background: '#fff', outline: 'none', boxSizing: 'border-box',
});

const roStyle = {
  width: '100%', padding: '10px 13px', border: '1px solid #e5e7eb',
  borderRadius: 8, fontSize: 13, background: '#f9fafb', color: '#6b7280', boxSizing: 'border-box',
};

const selectStyle = {
  width: '100%', padding: '10px 13px', border: '1px solid #d1d5db',
  borderRadius: 8, fontSize: 13, background: '#fff', outline: 'none', boxSizing: 'border-box', cursor: 'pointer',
};

/* ─── Product Picker ─────────────────────────────────────────────────── */
function ProductPicker({ products, categories, onSelect, selectedId }) {
  const [open, setOpen]             = useState(false);
  const [filterType, setFilterType] = useState('all');
  const [filterCat, setFilterCat]   = useState('');
  const [filterBrand, setFilterBrand] = useState('');
  const [filterText, setFilterText] = useState('');
  const panelRef = useRef(null);

  // Close on outside click
  useEffect(() => {
    const h = (e) => { if (panelRef.current && !panelRef.current.contains(e.target)) setOpen(false); };
    document.addEventListener('mousedown', h);
    return () => document.removeEventListener('mousedown', h);
  }, []);

  // Reset brand when type changes away from appliance
  useEffect(() => {
    if (filterType === 'consumable') setFilterBrand('');
  }, [filterType]);

  // Unique brands derived from loaded products (appliance only)
  const brandOptions = useMemo(() => {
    const map = {};
    products.forEach(p => {
      const b = p.appliance_detail?.brand;
      if (b?.brand_id) map[b.brand_id] = b.brand_name;
    });
    return Object.entries(map)
      .map(([id, name]) => ({ id: Number(id), name }))
      .sort((a, b) => a.name.localeCompare(b.name));
  }, [products]);

  const filtered = useMemo(() => {
    let list = products;
    if (filterType !== 'all') list = list.filter(p => p.product_type === filterType);
    if (filterCat)   list = list.filter(p => String(p.category_id) === String(filterCat));
    if (filterBrand) list = list.filter(p => String(p.appliance_detail?.brand?.brand_id ?? '') === String(filterBrand));
    if (filterText.trim()) {
      const q = filterText.toLowerCase();
      list = list.filter(p =>
        p.product_name?.toLowerCase().includes(q) ||
        p.appliance_detail?.brand?.brand_name?.toLowerCase().includes(q) ||
        p.appliance_detail?.capacity_rating?.toLowerCase().includes(q) ||
        p.appliance_detail?.variant?.toLowerCase().includes(q) ||
        p.consumable_detail?.unit?.unit_name?.toLowerCase().includes(q) ||
        String(p.product_id).includes(q)
      );
    }
    return list.slice(0, 60);
  }, [products, filterType, filterCat, filterBrand, filterText]);

  const selectedProduct = selectedId ? products.find(p => String(p.product_id) === String(selectedId)) : null;

  const pick = (p) => {
    onSelect(p);
    setOpen(false);
  };

  const clear = () => { onSelect(null); };

  const TAB_BTN = (key, label) => (
    <button
      key={key}
      type="button"
      onClick={() => setFilterType(key)}
      style={{
        padding: '5px 14px', borderRadius: 6, fontSize: 12, fontWeight: 600, cursor: 'pointer',
        border: '1px solid', transition: 'all 0.1s',
        background: filterType === key ? '#DC143C' : '#f9fafb',
        borderColor: filterType === key ? '#DC143C' : '#e5e7eb',
        color: filterType === key ? '#fff' : '#6b7280',
      }}
    >{label}</button>
  );

  return (
    <div ref={panelRef} style={{ position: 'relative' }}>
      <Label>Corrected Product <span style={{ color: '#dc2626' }}>*</span></Label>

      {/* Trigger / selected display */}
      {selectedProduct ? (
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '9px 13px', border: '1px solid #10b981', borderRadius: 8, background: '#f0fdf4' }}>
          <div style={{ flex: 1, minWidth: 0 }}>
            <div style={{ fontWeight: 700, fontSize: 13, color: '#111827', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
              {selectedProduct.product_name}
            </div>
            <div style={{ fontSize: 11, color: '#6b7280', marginTop: 1 }}>
              {selectedProduct.appliance_detail?.brand?.brand_name
                ? `${selectedProduct.appliance_detail.brand.brand_name}${selectedProduct.appliance_detail.capacity_rating ? ' · ' + selectedProduct.appliance_detail.capacity_rating : ''}`
                : selectedProduct.consumable_detail?.unit?.unit_name ?? ''}
              {' · '}
              <TypeBadge type={selectedProduct.product_type} />
            </div>
          </div>
          <button type="button" onClick={() => { clear(); setOpen(true); }}
            style={{ padding: '4px 10px', borderRadius: 6, fontSize: 11, fontWeight: 700, cursor: 'pointer', border: '1px solid #d1d5db', background: '#fff', color: '#374151', whiteSpace: 'nowrap' }}>
            Change
          </button>
        </div>
      ) : (
        <button
          type="button"
          onClick={() => setOpen(v => !v)}
          style={{ width: '100%', padding: '10px 13px', border: '1px dashed #d1d5db', borderRadius: 8, fontSize: 13, background: '#fafafa', color: '#9ca3af', cursor: 'pointer', textAlign: 'left', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}
        >
          <span>Click to select product...</span>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14">
            <path d="m6 9 6 6 6-6"/>
          </svg>
        </button>
      )}

      {/* Picker panel */}
      {open && (
        <div style={{
          position: 'absolute', top: 'calc(100% + 6px)', left: 0, right: 0,
          background: '#fff', border: '1px solid #e5e7eb', borderRadius: 10,
          boxShadow: '0 8px 24px rgba(0,0,0,0.12)', zIndex: 100, minWidth: 420,
        }}>
          {/* Filter controls */}
          <div style={{ padding: '14px 16px', borderBottom: '1px solid #f3f4f6' }}>
            {/* Type toggle */}
            <div style={{ display: 'flex', gap: 6, marginBottom: 12 }}>
              {TAB_BTN('all', 'All')}
              {TAB_BTN('appliance', 'Appliance')}
              {TAB_BTN('consumable', 'Consumable')}
            </div>

            {/* Category + Brand row */}
            <div style={{ display: 'grid', gridTemplateColumns: filterType !== 'consumable' ? '1fr 1fr' : '1fr', gap: 8, marginBottom: 8 }}>
              <div>
                <div style={{ fontSize: 10, fontWeight: 700, color: '#9ca3af', letterSpacing: 0.4, marginBottom: 4 }}>CATEGORY</div>
                <select value={filterCat} onChange={e => setFilterCat(e.target.value)} style={{ ...selectStyle, padding: '7px 10px', fontSize: 12 }}>
                  <option value="">All Categories</option>
                  {categories.map(c => (
                    <option key={c.category_id} value={c.category_id}>{c.category_name}</option>
                  ))}
                </select>
              </div>
              {filterType !== 'consumable' && (
                <div>
                  <div style={{ fontSize: 10, fontWeight: 700, color: '#9ca3af', letterSpacing: 0.4, marginBottom: 4 }}>BRAND</div>
                  <select value={filterBrand} onChange={e => setFilterBrand(e.target.value)} style={{ ...selectStyle, padding: '7px 10px', fontSize: 12 }}>
                    <option value="">All Brands</option>
                    {brandOptions.map(b => (
                      <option key={b.id} value={b.id}>{b.name}</option>
                    ))}
                  </select>
                </div>
              )}
            </div>

            {/* Text search */}
            <div style={{ position: 'relative' }}>
              <svg viewBox="0 0 24 24" fill="none" stroke="#9ca3af" strokeWidth="2" width="14" height="14"
                style={{ position: 'absolute', left: 10, top: '50%', transform: 'translateY(-50%)' }}>
                <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
              </svg>
              <input
                value={filterText}
                onChange={e => setFilterText(e.target.value)}
                placeholder="Search by name, brand, capacity, variant..."
                style={{ ...inputStyle(false), paddingLeft: 32, fontSize: 12 }}
                autoFocus
              />
            </div>
          </div>

          {/* Results list */}
          <div style={{ maxHeight: 280, overflowY: 'auto' }}>
            {filtered.length === 0 && (
              <div style={{ padding: '20px 16px', textAlign: 'center', color: '#9ca3af', fontSize: 13 }}>
                No products match the filters.
              </div>
            )}
            {filtered.map(p => {
              const brand = p.appliance_detail?.brand?.brand_name;
              const capacity = p.appliance_detail?.capacity_rating;
              const variant = p.appliance_detail?.variant;
              const unit = p.consumable_detail?.unit?.unit_name;
              const catName = categories.find(c => c.category_id === p.category_id)?.category_name;
              const subtitle = [brand, capacity, variant].filter(Boolean).join(' · ') || unit || '';

              return (
                <div
                  key={p.product_id}
                  onClick={() => pick(p)}
                  style={{
                    padding: '10px 16px', cursor: 'pointer', borderBottom: '1px solid #f9fafb',
                    display: 'flex', alignItems: 'center', gap: 12,
                  }}
                  onMouseEnter={e => e.currentTarget.style.background = '#f9fafb'}
                  onMouseLeave={e => e.currentTarget.style.background = 'transparent'}
                >
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{ fontWeight: 600, fontSize: 13, color: '#111827', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                      {p.product_name}
                    </div>
                    {subtitle && (
                      <div style={{ fontSize: 11, color: '#6b7280', marginTop: 1 }}>{subtitle}</div>
                    )}
                    {catName && (
                      <div style={{ fontSize: 10, color: '#9ca3af', marginTop: 1 }}>{catName}</div>
                    )}
                  </div>
                  <TypeBadge type={p.product_type} />
                </div>
              );
            })}
          </div>

          {filtered.length === 60 && (
            <div style={{ padding: '8px 16px', borderTop: '1px solid #f3f4f6', fontSize: 11, color: '#9ca3af', textAlign: 'center' }}>
              Showing first 60 results — refine filters to narrow down
            </div>
          )}
        </div>
      )}
    </div>
  );
}

/* ─── Main page ──────────────────────────────────────────────────────── */
export default function BarcodeAdjustmentPage() {
  const { user } = useAuth();
  const navigate = useNavigate();

  // Reference data
  const [allPOs, setAllPOs]         = useState([]);
  const [locations, setLocations]   = useState([]);
  const [products, setProducts]     = useState([]);
  const [categories, setCategories] = useState([]);

  // PO searchable dropdown
  const [poSearch, setPoSearch]       = useState('');
  const [showPoDrop, setShowPoDrop]   = useState(false);
  const [selectedPO, setSelectedPO]   = useState(null);
  const poDropRef                     = useRef(null);

  // Loaded PO data
  const [poData, setPoData]       = useState(null);
  const [poLoading, setPoLoading] = useState(false);
  const [poError, setPoError]     = useState(null);

  // Selected row
  const [selectedRow, setSelectedRow] = useState(null);

  // Form fields
  const [locationId, setLocationId]             = useState('');
  const [correctedBarcode, setCorrectedBarcode] = useState('');
  const [customBarcode, setCustomBarcode]       = useState('');
  const [correctedProduct, setCorrectedProduct] = useState(null); // full product object
  const [correctionType, setCorrectionType]     = useState('wrong_product');
  const [reason, setReason]                     = useState('');

  // Preview / submit
  const [preview, setPreview]             = useState(null);
  const [previewLoading, setPreviewLoading] = useState(false);
  const [submitting, setSubmitting]       = useState(false);
  const [lastSubmitted, setLastSubmitted] = useState(null);

  // History
  const [history, setHistory]           = useState([]);
  const [historyLoading, setHistoryLoading] = useState(false);

  // Load all reference data on mount
  useEffect(() => {
    purchaseOrdersAPI.getAll({ per_page: 500 })
      .then(res => setAllPOs(Array.isArray(res?.data) ? res.data : []))
      .catch(() => {});
    locationsAPI.getAll()
      .then(res => setLocations(Array.isArray(res?.data) ? res.data : []))
      .catch(() => {});
    productsAPI.getAll({ per_page: 500 })
      .then(res => setProducts(Array.isArray(res?.data) ? res.data : []))
      .catch(() => {});
    categoriesAPI.getAll()
      .then(res => {
        const list = Array.isArray(res?.data) ? res.data : (Array.isArray(res) ? res : []);
        setCategories(list.map(c => ({ category_id: c.category_id, category_name: c.category_name })));
      })
      .catch(() => {});
  }, []);

  // Auto-set location from user profile
  useEffect(() => {
    if (!locationId && user?.location_id) setLocationId(String(user.location_id));
  }, [user, locationId]);

  // Close PO dropdown on outside click
  useEffect(() => {
    const h = (e) => { if (poDropRef.current && !poDropRef.current.contains(e.target)) setShowPoDrop(false); };
    document.addEventListener('mousedown', h);
    return () => document.removeEventListener('mousedown', h);
  }, []);

  // Filtered PO list
  const filteredPOs = useMemo(() => {
    if (!poSearch.trim()) return allPOs.slice(0, 12);
    const q = poSearch.toLowerCase();
    return allPOs.filter(po =>
      String(po.po_number ?? '').toLowerCase().includes(q) ||
      String(po.supplier?.supplier_name ?? '').toLowerCase().includes(q) ||
      String(po.po_id).includes(q)
    ).slice(0, 12);
  }, [allPOs, poSearch]);

  // Barcode options from loaded PO
  const barcodeOptions = useMemo(() => {
    if (!poData) return [];
    const seen = new Set();
    const opts = [];
    (poData.receiving_details ?? []).forEach(rd => {
      if (rd.scanned_barcode && !seen.has(rd.scanned_barcode)) {
        seen.add(rd.scanned_barcode);
        opts.push({ value: rd.scanned_barcode, label: `${rd.scanned_barcode}  —  ${rd.product_name ?? ''}` });
      }
    });
    (poData.serial_records ?? []).forEach(s => {
      if (s.serial_number && !seen.has(s.serial_number)) {
        seen.add(s.serial_number);
        opts.push({ value: s.serial_number, label: `${s.serial_number}  —  ${s.product_name ?? ''} (serial)` });
      }
    });
    return opts;
  }, [poData]);

  const loadPo = useCallback(async (po) => {
    if (!po) return;
    setPoLoading(true);
    setPoError(null);
    setPoData(null);
    setSelectedRow(null);
    setPreview(null);
    setHistory([]);
    try {
      const res = await barcodeAdjustmentAPI.getReceivingHistory(po.po_id);
      if (res.success) {
        setPoData({ ...res, poId: po.po_id });
        loadHistory(po.po_id);
      } else {
        setPoError(res.message ?? 'PO not found.');
      }
    } catch (e) {
      setPoError(e.message ?? 'Failed to load PO.');
    } finally {
      setPoLoading(false);
    }
  }, []);

  const loadHistory = async (poId) => {
    setHistoryLoading(true);
    try {
      const res = await barcodeAdjustmentAPI.getCorrectionHistory({ po_id: poId });
      setHistory(res.success ? (res.data?.data ?? []) : []);
    } catch {
      setHistory([]);
    } finally {
      setHistoryLoading(false);
    }
  };

  const selectRow = (row) => {
    setSelectedRow(row);
    setPreview(null);
    setCorrectedBarcode('');
    setCustomBarcode('');
    setCorrectedProduct(null);
    setCorrectionType('wrong_product');
    setReason('');
  };

  const effectiveBarcode = correctedBarcode === '__custom__' ? customBarcode : correctedBarcode;

  const handlePreview = async () => {
    if (!selectedRow || !correctedProduct || !reason.trim()) {
      toast.error('Fill in all required fields before previewing.');
      return;
    }
    if (reason.trim().length < 10) {
      toast.error('Reason must be at least 10 characters.');
      return;
    }
    setPreviewLoading(true);
    setPreview(null);
    try {
      const res = await barcodeAdjustmentAPI.preview({
        po_id:                poData.poId,
        receiving_detail_id:  selectedRow.receiving_detail_id,
        corrected_product_id: correctedProduct.product_id,
        corrected_barcode:    effectiveBarcode || undefined,
        correction_type:      correctionType,
        reason:               reason.trim(),
      });
      if (res.success) setPreview(res.preview);
      else toast.error(res.message ?? 'Preview failed.');
    } catch (e) {
      toast.error(e.message ?? 'Preview failed.');
    } finally {
      setPreviewLoading(false);
    }
  };

  const handleSubmit = async () => {
    if (!preview) { toast.error('Run a preview first.'); return; }
    if (!locationId) { toast.error('Select a location.'); return; }
    if (!window.confirm('This will permanently modify inventory records.\n\nConfirm?')) return;
    setSubmitting(true);
    try {
      const res = await barcodeAdjustmentAPI.submit({
        po_id:                poData.poId,
        receiving_detail_id:  selectedRow.receiving_detail_id,
        corrected_product_id: correctedProduct.product_id,
        corrected_barcode:    effectiveBarcode || undefined,
        correction_type:      correctionType,
        reason:               reason.trim(),
        location_id:          parseInt(locationId, 10),
      });
      if (res.success) {
        toast.success(`Correction applied — ${res.adjustment_number}`);
        setLastSubmitted(res);
        setSelectedRow(null);
        setPreview(null);
        const fresh = await barcodeAdjustmentAPI.getReceivingHistory(poData.poId);
        if (fresh.success) setPoData({ ...fresh, poId: poData.poId });
        loadHistory(poData.poId);
      } else {
        toast.error(res.message ?? 'Submission failed.');
      }
    } catch (e) {
      toast.error(e.message ?? 'Submission failed.');
    } finally {
      setSubmitting(false);
    }
  };

  const secStyle = { background: '#fff', border: '1px solid #e5e7eb', borderRadius: 12, padding: '20px 24px', marginBottom: 20 };
  const secHead  = { fontWeight: 700, fontSize: 12, color: '#374151', marginBottom: 16, display: 'flex', alignItems: 'center', gap: 8, letterSpacing: 0.3 };

  return (
    <AdminLayout>
      <div className="bs-page">

        {/* Header */}
        <div className="bs-page-header" style={{ marginBottom: 24 }}>
          <button type="button" onClick={() => navigate('/admin/inventory-operation')}
            style={{ display: 'inline-flex', alignItems: 'center', gap: 6, background: 'none', border: '1px solid #e5e7eb', borderRadius: 7, padding: '5px 12px', fontSize: 12, fontWeight: 600, color: '#374151', cursor: 'pointer', marginBottom: 12 }}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg>
            Back to Scan
          </button>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
            <div>
              <h1 style={{ fontSize: '1.3rem', fontWeight: 700, color: '#111827', margin: '0 0 3px' }}>Barcode Adjustment</h1>
              <p style={{ fontSize: '0.78rem', color: '#6b7280', margin: 0 }}>Correct scan mistakes made during PO receiving — full compliance trail included</p>
            </div>
            {lastSubmitted && (
              <div style={{ background: '#ecfdf5', border: '1px solid #a7f3d0', borderRadius: 8, padding: '8px 14px', fontSize: 12, color: '#065f46' }}>
                Last correction: <strong>{lastSubmitted.adjustment_number}</strong>
              </div>
            )}
          </div>
        </div>

        {/* STEP 1 — PO + LOCATION */}
        <div style={secStyle}>
          <div style={secHead}>
            <svg viewBox="0 0 24 24" fill="none" stroke="#DC143C" strokeWidth="2" width="15" height="15">
              <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
              <polyline points="14 2 14 8 20 8"/>
            </svg>
            STEP 1 — SELECT PURCHASE ORDER &amp; LOCATION
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>

            {/* PO dropdown */}
            <div ref={poDropRef} style={{ position: 'relative' }}>
              <Label>Purchase Order <span style={{ color: '#dc2626' }}>*</span></Label>
              <div style={{ position: 'relative' }}>
                <input
                  value={selectedPO ? selectedPO.po_number : poSearch}
                  onChange={e => { setPoSearch(e.target.value); setSelectedPO(null); setPoData(null); setSelectedRow(null); setPreview(null); setHistory([]); setShowPoDrop(true); }}
                  onFocus={() => setShowPoDrop(true)}
                  placeholder="Search PO number or supplier..."
                  style={{ ...inputStyle(!!selectedPO), paddingRight: 36 }}
                />
                {selectedPO && (
                  <button type="button" onClick={() => { setSelectedPO(null); setPoSearch(''); setPoData(null); setSelectedRow(null); setPreview(null); setHistory([]); }}
                    style={{ position: 'absolute', right: 10, top: '50%', transform: 'translateY(-50%)', background: 'none', border: 'none', cursor: 'pointer', color: '#9ca3af', fontSize: 18, lineHeight: 1 }}>×</button>
                )}
              </div>
              {showPoDrop && !selectedPO && (
                <div style={{ position: 'absolute', top: 'calc(100% + 2px)', left: 0, right: 0, background: '#fff', border: '1px solid #e5e7eb', borderRadius: 8, boxShadow: '0 4px 16px rgba(0,0,0,0.10)', zIndex: 50, maxHeight: 240, overflowY: 'auto' }}>
                  {filteredPOs.length === 0 && <div style={{ padding: '12px 14px', fontSize: 13, color: '#9ca3af' }}>No POs found.</div>}
                  {filteredPOs.map(po => (
                    <div key={po.po_id}
                      onClick={() => { setSelectedPO(po); setPoSearch(''); setShowPoDrop(false); loadPo(po); }}
                      style={{ padding: '10px 14px', cursor: 'pointer', borderBottom: '1px solid #f3f4f6' }}
                      onMouseEnter={e => e.currentTarget.style.background = '#f9fafb'}
                      onMouseLeave={e => e.currentTarget.style.background = 'transparent'}>
                      <div style={{ fontWeight: 700, fontSize: 13, color: '#111827' }}>{po.po_number}</div>
                      <div style={{ fontSize: 11, color: '#9ca3af', marginTop: 1 }}>
                        {po.supplier?.supplier_name ?? 'Unknown supplier'} · ID {po.po_id}
                      </div>
                    </div>
                  ))}
                </div>
              )}
              {poLoading && <div style={{ marginTop: 8, fontSize: 12, color: '#6b7280' }}>Loading PO records...</div>}
              {poError  && <div style={{ marginTop: 8, fontSize: 12, color: '#dc2626' }}>{poError}</div>}
              {poData && !poLoading && (
                <div style={{ marginTop: 8, padding: '6px 10px', background: '#f0fdf4', borderRadius: 6, fontSize: 12, color: '#065f46', fontWeight: 600 }}>
                  {poData.receiving_details?.length ?? 0} scan record(s) loaded
                </div>
              )}
            </div>

            {/* Location */}
            <div>
              <Label>Location <span style={{ color: '#dc2626' }}>*</span></Label>
              <select value={locationId} onChange={e => setLocationId(e.target.value)} style={selectStyle}>
                <option value="">Select location...</option>
                {locations.map(l => (
                  <option key={l.location_id ?? l.id} value={l.location_id ?? l.id}>{l.location_name ?? l.name}</option>
                ))}
              </select>
            </div>
          </div>
        </div>

        {/* STEP 2 — SELECT ITEM */}
        {poData && (
          <div style={{ ...secStyle, padding: 0, overflow: 'hidden' }}>
            <div style={{ ...secHead, padding: '14px 20px', marginBottom: 0, borderBottom: '1px solid #f3f4f6' }}>
              <svg viewBox="0 0 24 24" fill="none" stroke="#DC143C" strokeWidth="2" width="15" height="15">
                <polyline points="9 11 12 14 22 4"/>
                <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
              </svg>
              STEP 2 — SELECT ITEM TO CORRECT
              <span style={{ fontSize: 11, fontWeight: 400, color: '#9ca3af' }}>Click a row to select it</span>
            </div>
            <div style={{ overflowX: 'auto' }}>
              <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
                <thead>
                  <tr style={{ background: '#fafafa', borderBottom: '1px solid #f3f4f6' }}>
                    {['', 'Scanned Barcode', 'Product', 'Type', 'Qty', 'Received At'].map((h, i) => (
                      <th key={i} style={{ textAlign: 'left', padding: '9px 14px', fontSize: 10, fontWeight: 700, color: '#9ca3af', letterSpacing: 0.5, textTransform: 'uppercase', whiteSpace: 'nowrap' }}>{h}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {poData.receiving_details?.length === 0 && (
                    <tr><td colSpan={6} style={{ padding: '24px 14px', textAlign: 'center', color: '#9ca3af' }}>No scan records for this PO.</td></tr>
                  )}
                  {poData.receiving_details?.map(row => {
                    const isSel = selectedRow?.receiving_detail_id === row.receiving_detail_id;
                    return (
                      <tr key={row.receiving_detail_id} onClick={() => selectRow(row)}
                        style={{ borderBottom: '1px solid #f9fafb', background: isSel ? '#fef2f2' : 'transparent', cursor: 'pointer' }}
                        onMouseEnter={e => { if (!isSel) e.currentTarget.style.background = '#f9fafb'; }}
                        onMouseLeave={e => { if (!isSel) e.currentTarget.style.background = 'transparent'; }}>
                        <td style={{ padding: '10px 14px', width: 32 }}>
                          <div style={{ width: 16, height: 16, borderRadius: '50%', border: `2px solid ${isSel ? '#DC143C' : '#d1d5db'}`, background: isSel ? '#DC143C' : 'transparent', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                            {isSel && <div style={{ width: 6, height: 6, borderRadius: '50%', background: '#fff' }} />}
                          </div>
                        </td>
                        <td style={{ padding: '10px 14px' }}>
                          <code style={{ fontSize: 12, background: '#f3f4f6', borderRadius: 4, padding: '2px 6px', color: '#374151' }}>
                            {row.scanned_barcode ?? '—'}
                          </code>
                        </td>
                        <td style={{ padding: '10px 14px' }}>
                          <div style={{ fontWeight: 600, color: '#111827' }}>{row.product_name ?? '—'}</div>
                          {row.brand_name && <div style={{ fontSize: 11, color: '#9ca3af' }}>{row.brand_name}</div>}
                        </td>
                        <td style={{ padding: '10px 14px' }}><TypeBadge type={row.product_type} /></td>
                        <td style={{ padding: '10px 14px', fontWeight: 600 }}>{row.quantity_amount}</td>
                        <td style={{ padding: '10px 14px', color: '#9ca3af', fontSize: 11, whiteSpace: 'nowrap' }}>{fmt(row.created_at)}</td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          </div>
        )}

        {/* STEP 3 — CORRECTION FORM */}
        {selectedRow && (
          <div style={{ ...secStyle, border: '2px solid #DC143C' }}>
            <div style={secHead}>
              <svg viewBox="0 0 24 24" fill="none" stroke="#DC143C" strokeWidth="2" width="15" height="15">
                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
              </svg>
              STEP 3 — CORRECTION DETAILS
            </div>

            {/* Read-only originals */}
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16, marginBottom: 16 }}>
              <div>
                <Label>Original Barcode (read-only)</Label>
                <div style={roStyle}>{selectedRow.scanned_barcode ?? '—'}</div>
              </div>
              <div>
                <Label>Original Product (read-only)</Label>
                <div style={roStyle}>{selectedRow.product_name ?? '—'}</div>
              </div>
            </div>

            {/* Corrected barcode + correction type */}
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16, marginBottom: 16 }}>
              <div>
                <Label>Corrected Barcode <span style={{ fontWeight: 400, color: '#d1d5db', textTransform: 'none' }}>(optional)</span></Label>
                <select value={correctedBarcode} onChange={e => { setCorrectedBarcode(e.target.value); setCustomBarcode(''); setPreview(null); }} style={selectStyle}>
                  <option value="">Keep original barcode</option>
                  {barcodeOptions.map(b => (
                    <option key={b.value} value={b.value}>{b.label}</option>
                  ))}
                  <option value="__custom__">— Enter custom barcode —</option>
                </select>
                {correctedBarcode === '__custom__' && (
                  <input value={customBarcode} onChange={e => { setCustomBarcode(e.target.value); setPreview(null); }}
                    placeholder="Type barcode..."
                    style={{ ...inputStyle(false), marginTop: 8 }} />
                )}
              </div>
              <div>
                <Label>Correction Type <span style={{ color: '#dc2626' }}>*</span></Label>
                <select value={correctionType} onChange={e => { setCorrectionType(e.target.value); setPreview(null); }} style={selectStyle}>
                  {CORRECTION_TYPES.map(ct => (
                    <option key={ct.value} value={ct.value}>{ct.label}</option>
                  ))}
                </select>
              </div>
            </div>

            {/* Product picker — full width */}
            <div style={{ marginBottom: 16 }}>
              <ProductPicker
                products={products}
                categories={categories}
                selectedId={correctedProduct?.product_id ?? null}
                onSelect={(p) => { setCorrectedProduct(p); setPreview(null); }}
              />
            </div>

            {/* Reason with presets */}
            <div style={{ marginBottom: 16 }}>
              <Label>Reason <span style={{ color: '#dc2626' }}>*</span></Label>
              <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, marginBottom: 8 }}>
                {PRESET_REASONS.map((pr, i) => (
                  <button key={i} type="button" onClick={() => { setReason(pr); setPreview(null); }}
                    style={{
                      padding: '4px 10px', borderRadius: 20, fontSize: 11, fontWeight: 600, cursor: 'pointer', border: '1px solid',
                      background: reason === pr ? '#fef2f2' : '#f9fafb',
                      borderColor: reason === pr ? '#DC143C' : '#e5e7eb',
                      color: reason === pr ? '#DC143C' : '#6b7280',
                    }}>
                    {pr.length > 50 ? pr.slice(0, 50) + '…' : pr}
                  </button>
                ))}
              </div>
              <textarea value={reason} onChange={e => { setReason(e.target.value); setPreview(null); }}
                rows={2} placeholder="Or type a custom reason (min 10 characters)..."
                style={{ width: '100%', padding: '9px 12px', border: `1px solid ${reason.length > 0 && reason.length < 10 ? '#dc2626' : '#d1d5db'}`, borderRadius: 8, fontSize: 13, outline: 'none', resize: 'vertical', boxSizing: 'border-box', fontFamily: 'inherit' }} />
              <div style={{ fontSize: 11, color: reason.length < 10 ? '#dc2626' : '#10b981', marginTop: 3 }}>
                {reason.length < 10 ? `${reason.length} / 10 min characters` : '✓ Reason accepted'}
              </div>
            </div>

            {/* Preview */}
            <div style={{ marginBottom: preview ? 16 : 0 }}>
              <button onClick={handlePreview} disabled={previewLoading || !correctedProduct || reason.length < 10}
                style={{ padding: '10px 24px', background: (!correctedProduct || reason.length < 10) ? '#d1d5db' : '#2563eb', color: '#fff', border: 'none', borderRadius: 8, fontSize: 13, fontWeight: 700, cursor: (!correctedProduct || reason.length < 10) ? 'not-allowed' : 'pointer' }}>
                {previewLoading ? 'Previewing...' : 'Preview Changes'}
              </button>
            </div>

            {/* Preview result */}
            {preview && (
              <div style={{ background: '#fffbeb', border: '1px solid #fde68a', borderRadius: 10, padding: '16px 20px' }}>
                <div style={{ fontWeight: 700, fontSize: 12, color: '#92400e', marginBottom: 12, display: 'flex', alignItems: 'center', gap: 8 }}>
                  <svg viewBox="0 0 24 24" fill="none" stroke="#b45309" strokeWidth="2" width="15" height="15">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                    <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                  </svg>
                  CHANGES THAT WILL OCCUR — REVIEW CAREFULLY
                </div>
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12, marginBottom: 14, fontSize: 12 }}>
                  <div style={{ background: '#fef2f2', borderRadius: 8, padding: '10px 12px' }}>
                    <div style={{ fontWeight: 700, color: '#dc2626', marginBottom: 6, fontSize: 10, letterSpacing: 0.5 }}>ORIGINAL (BEING REMOVED)</div>
                    <div style={{ fontWeight: 600 }}>{preview.original?.product_name}</div>
                    <div style={{ color: '#6b7280', marginTop: 2 }}>Barcode: {preview.original?.barcode ?? '—'}</div>
                    <div style={{ color: '#6b7280' }}>Qty: {preview.original?.quantity}</div>
                  </div>
                  <div style={{ background: '#f0fdf4', borderRadius: 8, padding: '10px 12px' }}>
                    <div style={{ fontWeight: 700, color: '#059669', marginBottom: 6, fontSize: 10, letterSpacing: 0.5 }}>CORRECTED (WILL BE APPLIED)</div>
                    <div style={{ fontWeight: 600 }}>{preview.corrected?.product_name}</div>
                    <div style={{ color: '#6b7280', marginTop: 2 }}>Type: {preview.corrected?.is_serialized ? 'Appliance (serialized)' : 'Consumable'}</div>
                    <div style={{ color: '#6b7280' }}>Qty after: {preview.corrected?.quantity_after}</div>
                  </div>
                </div>
                <ul style={{ margin: '0 0 16px', paddingLeft: 18 }}>
                  {preview.changes_that_will_occur?.map((c, i) => (
                    <li key={i} style={{ fontSize: 12, color: '#374151', marginBottom: 4 }}>{c}</li>
                  ))}
                </ul>
                <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                  <button onClick={handleSubmit} disabled={submitting || !locationId}
                    style={{ padding: '10px 28px', background: submitting ? '#d1d5db' : '#DC143C', color: '#fff', border: 'none', borderRadius: 8, fontSize: 13, fontWeight: 700, cursor: submitting || !locationId ? 'not-allowed' : 'pointer' }}>
                    {submitting ? 'Submitting...' : 'Confirm & Submit Correction'}
                  </button>
                  {!locationId && <span style={{ fontSize: 12, color: '#dc2626' }}>Select a location in Step 1 first.</span>}
                </div>
              </div>
            )}
          </div>
        )}

        {/* CORRECTION HISTORY */}
        {poData && (
          <div style={{ ...secStyle, padding: 0, overflow: 'hidden' }}>
            <div style={{ ...secHead, padding: '14px 20px', marginBottom: 0, borderBottom: '1px solid #f3f4f6' }}>
              <svg viewBox="0 0 24 24" fill="none" stroke="#6b7280" strokeWidth="2" width="15" height="15">
                <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/><path d="M11 8v4l2.5 2.5"/>
              </svg>
              CORRECTION HISTORY FOR THIS PO
              {historyLoading && <span style={{ fontSize: 11, fontWeight: 400, color: '#9ca3af' }}>Loading...</span>}
            </div>
            {!historyLoading && history.length === 0 ? (
              <div style={{ padding: 24, textAlign: 'center', color: '#9ca3af', fontSize: 13 }}>No corrections made for this PO yet.</div>
            ) : (
              <div style={{ overflowX: 'auto' }}>
                <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
                  <thead>
                    <tr style={{ background: '#fafafa', borderBottom: '1px solid #f3f4f6' }}>
                      {['Adjustment #', 'Date', 'Type', 'Original → Corrected', 'By', 'Status'].map(h => (
                        <th key={h} style={{ textAlign: 'left', padding: '9px 14px', fontSize: 10, fontWeight: 700, color: '#9ca3af', letterSpacing: 0.5, textTransform: 'uppercase', whiteSpace: 'nowrap' }}>{h}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {history.map(adj => (
                      <tr key={adj.adjustment_id} style={{ borderBottom: '1px solid #f9fafb' }}>
                        <td style={{ padding: '10px 14px' }}>
                          <code style={{ fontSize: 11, background: '#f3f4f6', borderRadius: 4, padding: '2px 6px', color: '#374151' }}>{adj.adjustment_number}</code>
                        </td>
                        <td style={{ padding: '10px 14px', color: '#6b7280', whiteSpace: 'nowrap' }}>{fmtDate(adj.adjustment_date)}</td>
                        <td style={{ padding: '10px 14px' }}>
                          <span style={{ fontSize: 10, fontWeight: 700, padding: '2px 7px', borderRadius: 4, background: '#fef2f2', color: '#dc2626' }}>
                            {adj.adjustment_type === 'barcode_correction' ? 'Barcode Fix' : adj.adjustment_type}
                          </span>
                        </td>
                        <td style={{ padding: '10px 14px' }}>
                          {adj.corrections?.map(c => (
                            <div key={c.correction_id} style={{ fontSize: 12, display: 'flex', alignItems: 'center', gap: 6 }}>
                              <span style={{ color: '#dc2626' }}>{c.original_product?.product_name ?? c.original_barcode ?? '—'}</span>
                              <svg viewBox="0 0 24 24" fill="none" stroke="#9ca3af" strokeWidth="2" width="12" height="12"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                              <span style={{ color: '#059669' }}>{c.corrected_product?.product_name ?? c.corrected_barcode ?? '—'}</span>
                            </div>
                          )) ?? <span style={{ color: '#9ca3af' }}>—</span>}
                        </td>
                        <td style={{ padding: '10px 14px', color: '#374151' }}>
                          {adj.created_by_user?.first_name
                            ? `${adj.created_by_user.first_name} ${adj.created_by_user.last_name ?? ''}`.trim()
                            : adj.created_by_user?.username ?? '—'}
                        </td>
                        <td style={{ padding: '10px 14px' }}>
                          <span style={{ fontSize: 10, fontWeight: 700, padding: '2px 7px', borderRadius: 4,
                            background: adj.status?.status_name === 'Approved' ? '#ecfdf5' : '#fff7ed',
                            color: adj.status?.status_name === 'Approved' ? '#059669' : '#b45309' }}>
                            {adj.status?.status_name ?? 'Pending'}
                          </span>
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
}
