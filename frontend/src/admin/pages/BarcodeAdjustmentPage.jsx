import React, { useState, useEffect, useCallback, useRef, useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import {
  BinaryBitmap,
  DecodeHintType,
  HTMLCanvasElementLuminanceSource,
  HybridBinarizer,
  MultiFormatReader,
  NotFoundException,
} from '@zxing/library';
import { Html5Qrcode, Html5QrcodeSupportedFormats } from 'html5-qrcode';
import { barcodeAdjustmentAPI, productsAPI, locationsAPI, purchaseOrdersAPI, categoriesAPI } from '../services/api';
import { toast } from '../utils/toast';
import { useAuth, useUserLocation } from '../context/AuthContext';
import LocationBadge    from '../components/LocationBadge';
import LocationSelector from '../components/LocationSelector';
import '../styles/barcode_scan.css';
import '../styles/po_receiving.css';
import '../styles/barcode_adjustment.css';

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

/* ─── Camera / scanner utilities ──────────────────────────────────────── */
const BA_HTML5_QR_MOUNT_ID  = 'ba-html5-qrcode-mount';
const BA_CAMERA_STORAGE_KEY = 'ba_camera_device_id';
const BA_CAMERA_SAME_CODE_MS = 450;

const BA_HTML5_FORMATS = [
  Html5QrcodeSupportedFormats.QR_CODE,  Html5QrcodeSupportedFormats.EAN_13,
  Html5QrcodeSupportedFormats.EAN_8,    Html5QrcodeSupportedFormats.CODE_128,
  Html5QrcodeSupportedFormats.CODE_39,  Html5QrcodeSupportedFormats.UPC_A,
  Html5QrcodeSupportedFormats.UPC_E,    Html5QrcodeSupportedFormats.CODE_93,
  Html5QrcodeSupportedFormats.ITF,      Html5QrcodeSupportedFormats.CODABAR,
  Html5QrcodeSupportedFormats.DATA_MATRIX, Html5QrcodeSupportedFormats.PDF_417,
];

function getBaHtml5ScanConfig() {
  return {
    fps: 10,
    qrbox: (w, h) => {
      const m = Math.min(w, h);
      return { width: Math.max(160, Math.floor(Math.min(w * 0.88, m * 0.92))), height: Math.max(88, Math.floor(Math.min(h * 0.42, m * 0.38))) };
    },
  };
}

const BA_NATIVE_FORMATS = ['qr_code','ean_13','ean_8','code_128','code_39','codabar','itf','upc_a','upc_e','data_matrix','pdf417'];
const baHasNativeBarcodeDetector = () => typeof window !== 'undefined' && typeof window.BarcodeDetector === 'function';

let baCropCanvas = null;
let baCropCtx = null;
let baCropReader = null;

function getBaCropReader() {
  if (!baCropReader) {
    baCropReader = new MultiFormatReader();
    const h = new Map();
    h.set(DecodeHintType.TRY_HARDER, true);
    baCropReader.setHints(h);
  }
  return baCropReader;
}

function baTryDecodeZxing(video) {
  const vw = video.videoWidth;
  const vh = video.videoHeight;
  if (vw < 48 || vh < 48) return null;
  const cropW = Math.floor(vw * 0.74);
  const cropH = Math.floor(vh * 0.42);
  const sx = Math.floor((vw - cropW) / 2);
  const sy = Math.floor((vh - cropH) / 2);
  const scale = Math.max(2, Math.min(3.2, 1280 / cropW));
  const outW = Math.floor(cropW * scale);
  const outH = Math.floor(cropH * scale);
  if (!baCropCanvas) {
    baCropCanvas = document.createElement('canvas');
    baCropCtx = baCropCanvas.getContext('2d', { willReadFrequently: true });
  }
  if (!baCropCtx) return null;
  baCropCanvas.width = outW;
  baCropCanvas.height = outH;
  baCropCtx.filter = 'contrast(1.12) saturate(0.85)';
  baCropCtx.drawImage(video, sx, sy, cropW, cropH, 0, 0, outW, outH);
  baCropCtx.filter = 'none';
  try {
    const result = getBaCropReader().decode(new BinaryBitmap(new HybridBinarizer(new HTMLCanvasElementLuminanceSource(baCropCanvas, true))));
    const code = String(typeof result.getText === 'function' ? result.getText() : '').replace(/\s+/g, '').trim();
    return code || null;
  } catch (e) {
    if (e instanceof NotFoundException) return null;
    return null;
  }
}

const baIsVirtualCamera = (label) => {
  const s = String(label || '').toLowerCase();
  return s ? /\bobs\b|open\s*broadcaster|streamlabs|webcamoid|manycam|epocam|snap\s*camera|droidcam|\bivcam\b|xsplit|chromacam|newtek|unitycapture|screen\s*capture|fake|simulator|reincubate|iriun|\bndi\b|nvidia\s*broadcast|virtual\s*camera|virtual\s*webcam|\(virtual\)/.test(s) : false;
};

const baSortPhysicalFirst = (all) => [...(all || [])].sort((a, b) => (baIsVirtualCamera(a.label) === baIsVirtualCamera(b.label) ? 0 : baIsVirtualCamera(a.label) ? 1 : -1));

const baSortDefaultFirst = (devices) => {
  const rank = (l) => { const s = String(l || '').toLowerCase(); let n = 0; if (/integrated|built-?in|internal/.test(s)) n += 10; if (/^usb|usb2|\busb\b.*(camera|video)|usb video device/.test(s)) n += 8; if (/hd user facing|front-facing|facing\s*user/.test(s)) n += 5; if (/\b(hp|lenovo|dell|logitech|microsoft|surface|acer|asus|msi|realtek)\b/.test(s)) n += 3; if (/webcam|true\s*vision|easy\s*camera/.test(s)) n += 2; return n; };
  return [...(devices || [])].sort((a, b) => rank(b.label) - rank(a.label));
};

function baAssertCameraEnv() {
  if (typeof window === 'undefined') return;
  if (!window.isSecureContext) {
    const host = window.location?.hostname || '';
    if (!['localhost','127.0.0.1','[::1]','::1'].includes(host)) throw new Error('INSECURE_ORIGIN: Camera is blocked on plain HTTP unless you use localhost.');
  }
  if (!navigator.mediaDevices?.getUserMedia || !navigator.mediaDevices?.enumerateDevices) throw new Error('Camera API unavailable. Use Chrome/Edge on https:// or http://localhost.');
}

function baCameraErrMsg(err) {
  const name = err?.name || '';
  const msg = String(err?.message || err || '');
  if (/INSECURE_ORIGIN/i.test(msg)) return msg.replace(/^INSECURE_ORIGIN:\s*/i, '');
  if (name === 'NotAllowedError' || /denied|not allowed|Permission/i.test(msg)) return 'Camera permission denied. Click the lock icon in the address bar and allow camera access.';
  if (name === 'NotFoundError' || /Could not start video source|DevicesNotFound/i.test(msg)) return 'No camera found. Plug in a webcam and close other apps using it.';
  if (name === 'NotReadableError' || name === 'TrackStartError' || /Could not start|busy|in use/i.test(msg)) return 'Camera in use by another app. Close other camera apps and try again.';
  if (name === 'OverconstrainedError') return 'Camera does not support the requested settings.';
  if (name === 'SecurityError' || /secure context/i.test(msg)) return 'Camera requires HTTPS or localhost.';
  return msg || 'Unknown camera error.';
}

async function baEnumerateCameras() {
  baAssertCameraEnv();
  let tmp;
  let lastErr;
  for (const c of [{ video: true }, { video: { facingMode: { ideal: 'environment' } } }]) {
    try { tmp = await navigator.mediaDevices.getUserMedia(c); break; } catch (e) { lastErr = e; }
  }
  if (!tmp) throw new Error(baCameraErrMsg(lastErr));
  tmp.getTracks().forEach((t) => t.stop());
  await new Promise((r) => setTimeout(r, 280));
  const inputs = (await navigator.mediaDevices.enumerateDevices()).filter((d) => d.kind === 'videoinput');
  if (!inputs.length) throw new Error('No camera found.');
  return inputs.map((d, i) => ({ deviceId: d.deviceId, label: d.label?.trim() || `Camera ${i + 1}` }));
}

function baPickCamera(devices, savedId) {
  if (!devices?.length) return null;
  const saved = devices.find((d) => d.deviceId === savedId && !baIsVirtualCamera(d.label));
  if (saved) return saved.deviceId;
  const physical = devices.find((d) => !baIsVirtualCamera(d.label));
  return physical ? physical.deviceId : devices[0].deviceId;
}

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
  <label className="baj-label">{children}</label>
);

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
        <div className="ba-picker-panel">
          {/* Filter controls */}
          <div style={{ padding: '12px 14px', borderBottom: '1px solid #f3f4f6' }}>
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
                <select value={filterCat} onChange={e => setFilterCat(e.target.value)} className="baj-select" style={{ padding: '7px 10px', fontSize: 12 }}>
                  <option value="">All Categories</option>
                  {categories.map(c => (
                    <option key={c.category_id} value={c.category_id}>{c.category_name}</option>
                  ))}
                </select>
              </div>
              {filterType !== 'consumable' && (
                <div>
                  <div style={{ fontSize: 10, fontWeight: 700, color: '#9ca3af', letterSpacing: 0.4, marginBottom: 4 }}>BRAND</div>
                  <select value={filterBrand} onChange={e => setFilterBrand(e.target.value)} className="baj-select" style={{ padding: '7px 10px', fontSize: 12 }}>
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
                className="baj-input"
                style={{ paddingLeft: 32, fontSize: 12 }}
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
  const { user, refreshUser } = useAuth();
  const { locationId: enforcedLocationId, locationName, locationType, isStrictLocation, allowedLocations, canChooseLocation } = useUserLocation();
  const navigate = useNavigate();

  // Reference data
  const [allPOs, setAllPOs]         = useState([]);
  const [locations, setLocations]   = useState([]);
  const [products, setProducts]     = useState([]);
  const [categories, setCategories] = useState([]);

  // PO selector
  const [poSearch, setPoSearch]     = useState('');
  const [selectedPO, setSelectedPO] = useState(null);

  // Barcode scan (Step 1 — finds PO + item automatically)
  const [barcodeScanInput, setBarcodeScanInput] = useState('');
  const [barcodeScanMsg, setBarcodeScanMsg]     = useState(null); // { type: 'ok'|'err', text }
  const [barcodeScanLoading, setBarcodeScanLoading] = useState(false);
  const barcodeScanRef                            = useRef(null);

  // Camera state
  const [cameraActive, setCameraActive]     = useState(false);
  const [cameraError, setCameraError]       = useState('');
  const [cameraDevices, setCameraDevices]   = useState([]);
  const [selectedCameraId, setSelectedCameraId] = useState(() => {
    try { return localStorage.getItem(BA_CAMERA_STORAGE_KEY) || ''; } catch { return ''; }
  });

  // Camera refs
  const html5QrRef      = useRef(null);
  const lastCodeRef     = useRef('');
  const lastTimeRef     = useRef(0);
  const handleScanRef   = useRef(null); // updated each render, used inside camera callback

  const cameraBlockedPlainHttp = useMemo(() => {
    if (typeof window === 'undefined') return false;
    if (window.isSecureContext) return false;
    const host = window.location?.hostname || '';
    return !['localhost','127.0.0.1','[::1]','::1'].includes(host);
  }, []);

  // Loaded PO data
  const [poData, setPoData]       = useState(null);
  const [poLoading, setPoLoading] = useState(false);
  const [poError, setPoError]     = useState(null);

  // Selected row
  const [selectedRow, setSelectedRow] = useState(null);

  // Form fields
  // For admin/BM: user-chosen location_id. Strict roles use enforcedLocationId from the hook.
  const [locationId, setLocationId]             = useState('');
  // The value sent to the backend — strict roles use enforced value so validation/submit work
  const effectiveLocationId = isStrictLocation ? (enforcedLocationId ?? '') : locationId;
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

  // Hydrate full location data (enforced_location_id, allowed_locations, etc.)
  useEffect(() => { refreshUser(); }, [refreshUser]);

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

  const handleBarcodeScan = async (value) => {
    const q = value.trim();
    if (!q) return;
    setBarcodeScanLoading(true);
    setBarcodeScanMsg(null);
    try {
      const res = await barcodeAdjustmentAPI.lookupBarcode(q);
      if (!res.success) {
        setBarcodeScanMsg({ type: 'err', text: res.message ?? `No received item found for barcode "${q}".` });
        return;
      }
      // If PO differs from currently loaded one, load it
      const needsLoad = !poData || poData.poId !== res.po_id;
      const matchingPO = allPOs.find(p => p.po_id === res.po_id) ?? { po_id: res.po_id, po_number: res.po_number };
      setSelectedPO(matchingPO);

      let details;
      if (needsLoad) {
        setPoLoading(true);
        setPoData(null);
        setSelectedRow(null);
        setPreview(null);
        setHistory([]);
        const poRes = await barcodeAdjustmentAPI.getReceivingHistory(res.po_id);
        if (!poRes.success) {
          setBarcodeScanMsg({ type: 'err', text: 'Could not load PO data.' });
          setPoLoading(false);
          return;
        }
        setPoData({ ...poRes, poId: res.po_id });
        loadHistory(res.po_id);
        setPoLoading(false);
        details = poRes.receiving_details ?? [];
      } else {
        details = poData.receiving_details ?? [];
      }

      // Auto-select the matched row
      const match = details.find(rd => rd.receiving_detail_id === res.receiving_detail_id);
      if (match) {
        selectRow(match);
        setBarcodeScanMsg({ type: 'ok', text: `Found in ${res.po_number}: ${res.product_name}` });
      } else {
        setBarcodeScanMsg({ type: 'ok', text: `PO ${res.po_number} loaded — select the item in the table below.` });
      }
      setBarcodeScanInput('');
    } catch (e) {
      setBarcodeScanMsg({ type: 'err', text: e.message ?? 'Lookup failed.' });
    } finally {
      setBarcodeScanLoading(false);
    }
  };

  // Always keep ref in sync so the camera callback always calls the latest handleBarcodeScan
  handleScanRef.current = handleBarcodeScan;

  const cameraSelectValue = useMemo(() => {
    if (!cameraDevices.length) return '';
    return cameraDevices.some((d) => d.deviceId === selectedCameraId) ? selectedCameraId : cameraDevices[0].deviceId;
  }, [cameraDevices, selectedCameraId]);

  /* ── Camera functions ──────────────────────────────── */
  const stopReaderAndTracks = useCallback(async () => {
    const h = html5QrRef.current;
    html5QrRef.current = null;
    if (h) {
      try { await h.stop(); } catch { /* not running */ }
      try { h.clear(); } catch { /* ignore */ }
    }
  }, []);

  const startHtml5OnDevice = useCallback(async (deviceId) => {
    await stopReaderAndTracks();
    await new Promise((r) => setTimeout(r, 80));
    const mount = typeof document !== 'undefined' ? document.getElementById(BA_HTML5_QR_MOUNT_ID) : null;
    if (!mount) throw new Error('Scanner mount not found.');
    const h5 = new Html5Qrcode(BA_HTML5_QR_MOUNT_ID, {
      formatsToSupport: BA_HTML5_FORMATS,
      useBarCodeDetectorIfSupported: true,
      verbose: false,
    });
    html5QrRef.current = h5;
    // Manual-only mode: camera shows live preview but never auto-fires.
    // The Scan button (manualFrameScan) is the only way to decode a frame.
    await h5.start(
      { deviceId: { exact: deviceId } },
      getBaHtml5ScanConfig(),
      () => {},
      () => {},
    );
  }, [stopReaderAndTracks]);

  const startCamera = async () => {
    setCameraError('');
    if (typeof document === 'undefined' || !document.getElementById(BA_HTML5_QR_MOUNT_ID)) {
      setCameraError('Scanner not ready. Try closing and reopening this page.');
      return;
    }
    await stopReaderAndTracks();
    setCameraActive(true);
    await new Promise((r) => setTimeout(r, 100));
    try {
      const allDevices = await baEnumerateCameras();
      const devices = baSortPhysicalFirst(allDevices);
      setCameraDevices(devices);
      const physicalOnly = allDevices.filter((d) => !baIsVirtualCamera(d.label));
      const virtualOnly  = allDevices.filter((d) => baIsVirtualCamera(d.label));
      const pool = physicalOnly.length > 0 ? baSortDefaultFirst(physicalOnly) : baSortPhysicalFirst(allDevices);
      const resolvedId = baPickCamera(pool, selectedCameraId);
      if (!resolvedId) throw new Error('No usable camera device.');
      setSelectedCameraId(resolvedId);
      try { localStorage.setItem(BA_CAMERA_STORAGE_KEY, resolvedId); } catch { /* ignore */ }
      const physSorted = physicalOnly.length > 0 ? baSortDefaultFirst(physicalOnly) : [];
      const tryOrder = physicalOnly.length > 0
        ? [resolvedId, ...physSorted.map((d) => d.deviceId).filter((id) => id !== resolvedId), ...virtualOnly.map((d) => d.deviceId)]
        : [resolvedId, ...allDevices.map((d) => d.deviceId).filter((id) => id !== resolvedId)];
      const uniqueTry = [...new Set(tryOrder)];
      let lastErr;
      let opened = false;
      for (const id of uniqueTry) {
        for (let attempt = 0; attempt < 2; attempt += 1) {
          try {
            if (attempt > 0) await new Promise((r) => setTimeout(r, 400));
            await startHtml5OnDevice(id);
            opened = true;
            break;
          } catch (err) {
            lastErr = err;
            await stopReaderAndTracks();
            if (!(attempt === 0 && (err?.name === 'NotReadableError' || err?.name === 'TrackStartError'))) break;
          }
        }
        if (opened) break;
      }
      if (!opened) throw lastErr || new Error('Could not open any camera.');
    } catch (e) {
      await stopReaderAndTracks();
      setCameraActive(false);
      setCameraError(baCameraErrMsg(e) + ' You can still type or use a USB barcode scanner below.');
    }
  };

  const stopCamera = async () => {
    await stopReaderAndTracks();
    setCameraActive(false);
  };

  const onCameraDeviceChange = async (e) => {
    const id = e.target.value;
    setSelectedCameraId(id);
    try { localStorage.setItem(BA_CAMERA_STORAGE_KEY, id); } catch { /* ignore */ }
    if (!cameraActive) return;
    try {
      await startHtml5OnDevice(id);
    } catch (err) {
      setCameraError(`Could not switch camera: ${baCameraErrMsg(err)}`);
      await stopReaderAndTracks();
      setCameraActive(false);
    }
  };

  const manualFrameScan = useCallback(async () => {
    const mountEl = typeof document !== 'undefined' ? document.getElementById(BA_HTML5_QR_MOUNT_ID) : null;
    const v = mountEl?.querySelector('video');
    if (!cameraActive || !v || v.readyState < 2 || v.videoWidth < 48) {
      toast.info('Start the camera and wait for a live preview.');
      return;
    }
    let code = null;
    if (baHasNativeBarcodeDetector()) {
      try {
        const detector = new window.BarcodeDetector({ formats: BA_NATIVE_FORMATS });
        const codes = await detector.detect(v);
        const raw = codes?.[0]?.rawValue;
        if (raw) code = String(raw).replace(/\s+/g, '').trim();
      } catch { /* ignore */ }
    }
    if (!code) code = baTryDecodeZxing(v);
    if (!code || code.length < 2) {
      toast.warning('No barcode found. Center the code in the frame and tap Scan again.');
      return;
    }
    lastCodeRef.current = code;
    lastTimeRef.current = Date.now();
    handleScanRef.current?.(code);
  }, [cameraActive]);

  // Stop camera on unmount
  useEffect(() => () => { stopReaderAndTracks(); }, [stopReaderAndTracks]);

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
    if (!effectiveLocationId) { toast.error('Select a location.'); return; }
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
        location_id:          parseInt(effectiveLocationId, 10) || undefined,
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

  return (
    <AdminLayout>
      <div className="baj-page">

        {/* Header */}
        <div className="baj-header-card">
          <div className="baj-header-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="#DC143C" strokeWidth="2" width="26" height="26">
              <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
              <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
            </svg>
          </div>
          <div className="baj-header-text">
            <h1>Barcode Adjustment</h1>
            <p>Correct scan mistakes made during PO receiving — full compliance trail included</p>
          </div>
          <button type="button" className="baj-back-btn" onClick={() => navigate('/admin/inventory-operation')}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg>
            Back
          </button>
          {lastSubmitted && (
            <div className="baj-last-badge">
              Last: <strong>{lastSubmitted.adjustment_number}</strong>
            </div>
          )}
        </div>

        {/* STEP 1 — PO + LOCATION */}
        <div className="baj-card">
          <div className="baj-card-head">
            <div className="baj-card-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="#DC143C" strokeWidth="2" width="17" height="17">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                <polyline points="14 2 14 8 20 8"/>
              </svg>
            </div>
            <div className="baj-card-title">STEP 1 — SELECT PURCHASE ORDER &amp; LOCATION</div>
          </div>

          {/* ── Camera box — same structure as Receive PO scanner ── */}
          <div className="ba-adj-camera bs-camera-box">
            <div className="bs-cam-modebar" style={{ justifyContent: 'center' }}>
              <div className="bs-cam-modename">
                <span>ADJUST</span>
                <span className="bs-cam-modesub">SCAN THE WRONG BARCODE</span>
              </div>
            </div>
            <div
              id={BA_HTML5_QR_MOUNT_ID}
              className="bs-html5qrcode-mount"
              style={{ display: cameraActive ? 'block' : 'none' }}
            />
            {!cameraActive && (
              <div className="bs-cam-idle">
                <div className="bs-cam-icon-wrap">
                  <svg viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.6)" strokeWidth="1.5" width="38" height="38">
                    <path d="M23 7l-7 5 7 5V7z"/>
                    <rect x="1" y="5" width="15" height="14" rx="2" ry="2"/>
                  </svg>
                </div>
              </div>
            )}
            <div className="bs-bracket bs-tl"/><div className="bs-bracket bs-tr"/>
            <div className="bs-bracket bs-bl"/><div className="bs-bracket bs-br"/>
            {cameraActive && <div className="bs-scan-line"/>}
            {cameraActive && (
              <p className="bs-cam-hint">Center the barcode in the frame, then tap the Scan button to capture.</p>
            )}
            {cameraError && <p className="bs-cam-err">{cameraError}</p>}
            {cameraActive && (
              <button type="button" className="bs-cam-shutter" onClick={manualFrameScan} aria-label="Scan barcode from current camera frame">
                <span className="bs-cam-shutter-inner"/>
                <span className="bs-cam-shutter-label">Scan</span>
              </button>
            )}
            {cameraActive
              ? <button className="bs-cam-btn bs-cam-stop" onClick={stopCamera} type="button">Stop Camera</button>
              : <button className="bs-cam-btn bs-cam-start" onClick={startCamera} type="button" disabled={cameraBlockedPlainHttp}>
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14">
                    <path d="M23 7l-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/>
                  </svg>
                  Start Camera
                </button>
            }
          </div>

          {/* Camera device selector */}
          {cameraDevices.length > 1 && (
            <div className="bs-cam-select-bar">
              <label htmlFor="ba-camera-device" className="bs-cam-select-bar-label">Camera</label>
              <select
                id="ba-camera-device"
                className="bs-cam-select-bar-input"
                value={cameraSelectValue}
                onChange={onCameraDeviceChange}
              >
                {cameraDevices.map((d) => (
                  <option key={d.deviceId} value={d.deviceId}>
                    {d.label}{baIsVirtualCamera(d.label) ? ' (virtual)' : ''}
                  </option>
                ))}
              </select>
            </div>
          )}

          {cameraBlockedPlainHttp && (
            <div className="baj-hint-card" style={{ margin: '0 0 0 0', borderRadius: 0, border: 'none', borderTop: '1px solid #FECDD3', background: '#fffbeb' }}>
              <span style={{ fontSize: 12, color: '#b45309' }}>Camera is only available on localhost or HTTPS. Use the text input below.</span>
            </div>
          )}

          {/* Manual / USB wedge input */}
          <div className="baj-scan-row">
            <div style={{ position: 'relative', flex: 1, minWidth: 0 }}>
              <svg viewBox="0 0 24 24" fill="none" stroke="#FDA4AF" strokeWidth="2" width="15" height="15"
                style={{ position: 'absolute', left: 12, top: '50%', transform: 'translateY(-50%)', pointerEvents: 'none' }}>
                <rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/>
              </svg>
              <input
                ref={barcodeScanRef}
                value={barcodeScanInput}
                onChange={e => { setBarcodeScanInput(e.target.value); setBarcodeScanMsg(null); }}
                onKeyDown={e => { if (e.key === 'Enter') handleBarcodeScan(barcodeScanInput); }}
                placeholder="Type barcode or use USB wedge scanner..."
                className="baj-scan-input"
                style={{ paddingLeft: 36, width: '100%' }}
                disabled={barcodeScanLoading}
              />
            </div>
            <button type="button" className="baj-capture-btn"
              onClick={() => handleBarcodeScan(barcodeScanInput)}
              disabled={barcodeScanLoading || !barcodeScanInput.trim()}>
              {barcodeScanLoading ? 'Searching…' : 'Lookup'}
            </button>
          </div>

          {barcodeScanMsg && (
            <div className={`baj-scan-result baj-scan-result--${barcodeScanMsg.type}`}>
              {barcodeScanMsg.type === 'ok' ? '✓ ' : '✕ '}{barcodeScanMsg.text}
            </div>
          )}

          <hr className="baj-divider" style={{ margin: '14px 18px' }} />

          <div className="baj-card-body" style={{ paddingTop: 0 }}>
            <div className="ba-grid-2">

              {/* PO visible list selector */}
              <div>
                <Label>Purchase Order <span style={{ color: '#dc2626' }}>*</span></Label>

                {selectedPO && (
                  <div className="baj-po-badge">
                    <svg viewBox="0 0 24 24" fill="none" stroke="#DC143C" strokeWidth="2" width="14" height="14"><polyline points="20 6 9 17 4 12"/></svg>
                    <div className="baj-po-badge-info">
                      <div className="baj-po-badge-number">{selectedPO.po_number}</div>
                      <div className="baj-po-badge-supplier">{selectedPO.supplier?.supplier_name ?? ''}</div>
                    </div>
                    <button type="button" className="baj-po-badge-clear"
                      onClick={() => { setSelectedPO(null); setPoSearch(''); setPoData(null); setSelectedRow(null); setPreview(null); setHistory([]); setBarcodeScanInput(''); setBarcodeScanMsg(null); }}>
                      ×
                    </button>
                  </div>
                )}

                <div className="baj-search-wrap">
                  <svg viewBox="0 0 24 24" fill="none" stroke="#9ca3af" strokeWidth="2" width="13" height="13" className="baj-search-icon">
                    <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
                  </svg>
                  <input
                    value={poSearch}
                    onChange={e => setPoSearch(e.target.value)}
                    placeholder="Filter by PO number or supplier..."
                    className="baj-search-input"
                  />
                </div>

                <div className="baj-po-list">
                  {filteredPOs.length === 0 && (
                    <div style={{ padding: '16px 14px', textAlign: 'center', color: '#9ca3af', fontSize: 13 }}>No purchase orders found.</div>
                  )}
                  {filteredPOs.map(po => {
                    const isSel = selectedPO?.po_id === po.po_id;
                    return (
                      <div key={po.po_id}
                        className={`baj-po-item${isSel ? ' baj-po-item--selected' : ''}`}
                        onClick={() => { setSelectedPO(po); setPoSearch(''); loadPo(po); }}>
                        {isSel
                          ? <svg viewBox="0 0 24 24" fill="none" stroke="#DC143C" strokeWidth="2.5" width="13" height="13"><polyline points="20 6 9 17 4 12"/></svg>
                          : <div style={{ width: 13 }} />}
                        <div style={{ flex: 1 }}>
                          <div className="baj-po-item-number">{po.po_number}</div>
                          <div className="baj-po-item-meta">
                            {po.supplier?.supplier_name ?? 'Unknown supplier'} · ID {po.po_id}
                          </div>
                        </div>
                      </div>
                    );
                  })}
                </div>

                {poLoading && <div className="baj-po-status--loading">Loading PO records…</div>}
                {poError   && <div className="baj-po-status--err">{poError}</div>}
                {poData && !poLoading && (
                  <div className="baj-po-status baj-po-status--ok">
                    ✓ {poData.receiving_details?.length ?? 0} scan record(s) loaded
                  </div>
                )}
              </div>

              {/* Location */}
              <div>
                <Label>Location <span style={{ color: '#dc2626' }}>*</span></Label>
                {isStrictLocation ? (
                  <LocationBadge locationName={locationName} locationType={locationType} />
                ) : (
                  <LocationSelector
                    value={locationId ? Number(locationId) : null}
                    onChange={(val) => setLocationId(val != null ? String(val) : '')}
                    allowedLocations={allowedLocations}
                  />
                )}
              </div>
            </div>
          </div>
        </div>

        {/* STEP 2 — SELECT ITEM */}
        {poData && (
          <div className="baj-card">
            <div className="baj-card-head">
              <div className="baj-card-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="#DC143C" strokeWidth="2" width="17" height="17">
                  <polyline points="9 11 12 14 22 4"/>
                  <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
                </svg>
              </div>
              <div>
                <div className="baj-card-title">STEP 2 — SELECT ITEM TO CORRECT</div>
                <div className="baj-card-subtitle">Click a row to select it</div>
              </div>
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
                        style={{ borderBottom: '1px solid #f9fafb', background: isSel ? '#FFF0F2' : 'transparent', cursor: 'pointer' }}
                        onMouseEnter={e => { if (!isSel) e.currentTarget.style.background = '#f9fafb'; }}
                        onMouseLeave={e => { if (!isSel) e.currentTarget.style.background = isSel ? '#FFF0F2' : 'transparent'; }}>
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
          <div className="baj-card baj-card--active">
            <div className="baj-card-head baj-card-head--active">
              <div className="baj-card-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="#DC143C" strokeWidth="2" width="17" height="17">
                  <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                  <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                </svg>
              </div>
              <div className="baj-card-title">STEP 3 — CORRECTION DETAILS</div>
            </div>

            <div className="baj-card-body">
              {/* Read-only originals */}
              <div className="ba-grid-2-sm">
                <div>
                  <Label>Original Barcode (read-only)</Label>
                  <div className="baj-readonly">{selectedRow.scanned_barcode ?? '—'}</div>
                </div>
                <div>
                  <Label>Original Product (read-only)</Label>
                  <div className="baj-readonly">{selectedRow.product_name ?? '—'}</div>
                </div>
              </div>

              {/* Corrected barcode + correction type */}
              <div className="ba-grid-2-sm">
                <div>
                  <Label>Corrected Barcode <span style={{ fontWeight: 400, color: '#d1d5db', textTransform: 'none' }}>(optional)</span></Label>
                  <select className="baj-select" value={correctedBarcode} onChange={e => { setCorrectedBarcode(e.target.value); setCustomBarcode(''); setPreview(null); }}>
                    <option value="">Keep original barcode</option>
                    {barcodeOptions.map(b => (
                      <option key={b.value} value={b.value}>{b.label}</option>
                    ))}
                    <option value="__custom__">— Enter custom barcode —</option>
                  </select>
                  {correctedBarcode === '__custom__' && (
                    <input className="baj-input" value={customBarcode} onChange={e => { setCustomBarcode(e.target.value); setPreview(null); }}
                      placeholder="Type barcode…" style={{ marginTop: 8 }} />
                  )}
                </div>
                <div>
                  <Label>Correction Type <span style={{ color: '#dc2626' }}>*</span></Label>
                  <select className="baj-select" value={correctionType} onChange={e => { setCorrectionType(e.target.value); setPreview(null); }}>
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
                    <button key={i} type="button"
                      className={`baj-chip${reason === pr ? ' baj-chip--active' : ''}`}
                      onClick={() => { setReason(pr); setPreview(null); }}>
                      {pr.length > 50 ? pr.slice(0, 50) + '…' : pr}
                    </button>
                  ))}
                </div>
                <textarea
                  className={`baj-textarea${reason.length > 0 && reason.length < 10 ? ' baj-textarea--err' : ''}`}
                  value={reason} onChange={e => { setReason(e.target.value); setPreview(null); }}
                  rows={2} placeholder="Or type a custom reason (min 10 characters)…"
                />
                <div style={{ fontSize: 11, color: reason.length < 10 ? '#dc2626' : '#10b981', marginTop: 3 }}>
                  {reason.length < 10 ? `${reason.length} / 10 min characters` : '✓ Reason accepted'}
                </div>
              </div>

              {/* Preview button */}
              <div style={{ marginBottom: preview ? 16 : 0 }}>
                <button className="baj-btn-secondary" onClick={handlePreview}
                  disabled={previewLoading || !correctedProduct || reason.length < 10}>
                  {previewLoading ? 'Previewing…' : 'Preview Changes'}
                </button>
              </div>

              {/* Preview result */}
              {preview && (
                <div className="baj-preview-card">
                  <div className="baj-preview-head">
                    <svg viewBox="0 0 24 24" fill="none" stroke="#b45309" strokeWidth="2" width="15" height="15">
                      <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                      <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                    </svg>
                    CHANGES THAT WILL OCCUR — REVIEW CAREFULLY
                  </div>
                  <div className="ba-preview-grid">
                    <div className="baj-preview-before">
                      <div className="baj-preview-badge baj-preview-badge--rm">ORIGINAL (BEING REMOVED)</div>
                      <div style={{ fontWeight: 600 }}>{preview.original?.product_name}</div>
                      <div style={{ color: '#6b7280', marginTop: 2 }}>Barcode: {preview.original?.barcode ?? '—'}</div>
                      <div style={{ color: '#6b7280' }}>Qty: {preview.original?.quantity}</div>
                    </div>
                    <div className="baj-preview-after">
                      <div className="baj-preview-badge baj-preview-badge--add">CORRECTED (WILL BE APPLIED)</div>
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
                  <div className="ba-submit-row">
                    <button className="baj-btn-primary" onClick={handleSubmit} disabled={submitting || !effectiveLocationId}>
                      {submitting ? 'Submitting…' : 'Confirm & Submit Correction'}
                    </button>
                    {!effectiveLocationId && <span style={{ fontSize: 12, color: '#dc2626' }}>Select a location in Step 1 first.</span>}
                  </div>
                </div>
              )}
            </div>
          </div>
        )}

        {/* CORRECTION HISTORY */}
        {poData && (
          <div className="baj-card">
            <div className="baj-card-head">
              <div className="baj-card-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="#6b7280" strokeWidth="2" width="17" height="17">
                  <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                </svg>
              </div>
              <div>
                <div className="baj-card-title">CORRECTION HISTORY FOR THIS PO</div>
                {historyLoading && <div className="baj-card-subtitle">Loading…</div>}
              </div>
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
                          <span style={{ fontSize: 10, fontWeight: 700, padding: '2px 7px', borderRadius: 4, background: '#FFF0F2', color: '#DC143C' }}>
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
