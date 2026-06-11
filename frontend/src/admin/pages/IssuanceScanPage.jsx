import React, { useState, useEffect, useRef, useCallback, useMemo } from 'react';
import AdminLayout from '../components/AdminLayout';
import Modal from '../components/Modal';
import { useAuth, useUserLocation } from '../context/AuthContext';
import { getRoleKey, ROLES } from '../utils/roles';
import LocationBadge    from '../components/LocationBadge';
import LocationSelector from '../components/LocationSelector';
import {
  BinaryBitmap,
  DecodeHintType,
  HTMLCanvasElementLuminanceSource,
  HybridBinarizer,
  MultiFormatReader,
  NotFoundException,
} from '@zxing/library';
import { Html5Qrcode, Html5QrcodeSupportedFormats } from 'html5-qrcode';
import {
  customersAPI,
  locationsAPI,
  issuanceScanAPI,
  barcodeScanAPI,
  unitsAPI,
} from '../services/api';
import { toast } from '../utils/toast';
import { confirmAction } from '../utils/confirm';
import '../styles/dashboard_air.css';
import '../styles/barcode_scan.css';
import '../styles/issuance_scan.css';

const STEPS = { SETUP: 1, SCAN: 2, REVIEW: 3, COMPLETE: 4 };

const fmt = (val) => {
  const n = parseFloat(val);
  if (val === null || val === undefined || Number.isNaN(n)) return '—';
  return '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
};

const fmtDate = (d) => {
  if (!d) return '—';
  return new Date(d).toLocaleDateString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric' });
};

const EMPTY_FORM = {
  customer_id: '',
  customer: null,
  issuance_type: 'individual',
  discount_type: 'none',
  discount_percent: 0,
  location_id: '',
  notes: '',
};

// ─── Camera scanning utilities ───────────────────────────────────────────────

function playScanFeedback(found) {
  try {
    const Ctx = window.AudioContext || window.webkitAudioContext;
    if (!Ctx) return;
    const ctx = new Ctx();
    const osc = ctx.createOscillator();
    const g = ctx.createGain();
    osc.type = 'sine';
    osc.frequency.value = found ? 740 : 185;
    g.gain.value = 0.07;
    osc.connect(g);
    g.connect(ctx.destination);
    osc.start();
    osc.stop(ctx.currentTime + 0.11);
  } catch {
    /* ignore */
  }
}

const ISP_HTML5_QR_MOUNT_ID = 'isp-html5-qrcode-mount';
const CAMERA_STORAGE_KEY    = 'bs_inventory_camera_device_id';
const CAMERA_SAME_CODE_MS   = 450;

const HTML5_FORMATS = [
  Html5QrcodeSupportedFormats.QR_CODE,
  Html5QrcodeSupportedFormats.EAN_13,
  Html5QrcodeSupportedFormats.EAN_8,
  Html5QrcodeSupportedFormats.CODE_128,
  Html5QrcodeSupportedFormats.CODE_39,
  Html5QrcodeSupportedFormats.UPC_A,
  Html5QrcodeSupportedFormats.UPC_E,
  Html5QrcodeSupportedFormats.CODE_93,
  Html5QrcodeSupportedFormats.ITF,
  Html5QrcodeSupportedFormats.CODABAR,
  Html5QrcodeSupportedFormats.DATA_MATRIX,
  Html5QrcodeSupportedFormats.PDF_417,
];

function getHtml5CameraScanConfig() {
  return {
    fps: 10,
    qrbox: (viewfinderWidth, viewfinderHeight) => {
      const minDim = Math.min(viewfinderWidth, viewfinderHeight);
      const w = Math.max(160, Math.floor(Math.min(viewfinderWidth * 0.88, minDim * 0.92)));
      const h = Math.max(88, Math.floor(Math.min(viewfinderHeight * 0.42, minDim * 0.38)));
      return { width: w, height: h };
    },
  };
}

function buildBarcodeReaderHints() {
  const hints = new Map();
  hints.set(DecodeHintType.TRY_HARDER, true);
  return hints;
}

let ispCropReader = null;
function getCropReader() {
  if (!ispCropReader) {
    ispCropReader = new MultiFormatReader();
    ispCropReader.setHints(buildBarcodeReaderHints());
  }
  return ispCropReader;
}

let ispCropCanvas = null;
let ispCropCtx    = null;

function tryDecodeVideoCenterCropZxing(video) {
  const vw = video.videoWidth;
  const vh = video.videoHeight;
  if (vw < 48 || vh < 48) return null;
  const fracW = 0.74;
  const fracH = 0.42;
  const cropW = Math.floor(vw * fracW);
  const cropH = Math.floor(vh * fracH);
  const sx = Math.floor((vw - cropW) / 2);
  const sy = Math.floor((vh - cropH) / 2);
  const scale = Math.max(2, Math.min(3.2, 1280 / cropW));
  const outW  = Math.floor(cropW * scale);
  const outH  = Math.floor(cropH * scale);
  if (!ispCropCanvas) {
    ispCropCanvas = document.createElement('canvas');
    ispCropCtx    = ispCropCanvas.getContext('2d', { willReadFrequently: true });
  }
  if (!ispCropCtx) return null;
  ispCropCanvas.width  = outW;
  ispCropCanvas.height = outH;
  ispCropCtx.filter = 'contrast(1.12) saturate(0.85)';
  ispCropCtx.drawImage(video, sx, sy, cropW, cropH, 0, 0, outW, outH);
  ispCropCtx.filter = 'none';
  try {
    const luminance = new HTMLCanvasElementLuminanceSource(ispCropCanvas, true);
    const bitmap    = new BinaryBitmap(new HybridBinarizer(luminance));
    const result    = getCropReader().decode(bitmap);
    const text      = typeof result.getText === 'function' ? result.getText() : '';
    const code      = String(text || '').replace(/\s+/g, '').trim();
    return code || null;
  } catch (e) {
    if (e instanceof NotFoundException) return null;
    return null;
  }
}

const NATIVE_BARCODE_FORMATS = [
  'qr_code', 'ean_13', 'ean_8', 'code_128', 'code_39',
  'codabar', 'itf', 'upc_a', 'upc_e', 'data_matrix', 'pdf417',
];

function hasNativeBarcodeDetector() {
  return typeof window !== 'undefined' && typeof window.BarcodeDetector === 'function';
}

const isLikelyVirtualCameraLabel = (label) => {
  const s = String(label || '').toLowerCase();
  if (!s) return false;
  return /\bobs\b|\bobs\s*virtual\b|open\s*broadcaster|obs\s*studio|streamlabs|webcamoid|manycam|epocam|snap\s*camera|snapchat|droidcam|\bivcam\b|e2esoft|xsplit|chromacam|camtwist|newtek|unitycapture|screen\s*capture|desktop\s*video|fake|simulator|v\s*mixer|reincubate|iriun|iriun\s*webcam|\bndi\b|nvidia\s*broadcast|mmhmm|virtual\s*camera|virtual\s*webcam|virtual\s*device|\(virtual\)|^\s*obs\s*camera\s*$/.test(s);
};

const sortVideoDevicesPhysicalFirst = (all) => {
  const list = Array.isArray(all) ? [...all] : [];
  return list.sort((a, b) => {
    const va = isLikelyVirtualCameraLabel(a.label);
    const vb = isLikelyVirtualCameraLabel(b.label);
    if (va === vb) return 0;
    return va ? 1 : -1;
  });
};

const sortPhysicalCamerasDefaultFirst = (devices) => {
  const list = Array.isArray(devices) ? [...devices] : [];
  const rank = (label) => {
    const s = String(label || '').toLowerCase();
    let n = 0;
    if (/integrated|built-?in|internal/.test(s)) n += 10;
    if (/^usb|usb2|\busb\b.*(camera|video)|usb video device/.test(s)) n += 8;
    if (/hd user facing|user-facing|front-facing|facing\s*user/.test(s)) n += 5;
    if (/\b(hp|lenovo|dell|logitech|microsoft|surface|acer|asus|msi|realtek)\b/.test(s)) n += 3;
    if (/webcam|web\s*cam|true\s*vision|easy\s*camera/.test(s)) n += 2;
    return n;
  };
  return list.sort((a, b) => rank(b.label) - rank(a.label));
};

function assertCameraEnvironment() {
  if (typeof window === 'undefined') return;
  if (!window.isSecureContext) {
    const host = window.location?.hostname || '';
    const isLocal = host === 'localhost' || host === '127.0.0.1' || host === '[::1]' || host === '::1';
    if (!isLocal) {
      throw new Error(
        'INSECURE_ORIGIN: Camera is blocked on plain HTTP unless you use localhost. Open this app as http://localhost:PORT (or use HTTPS).',
      );
    }
  }
  if (!navigator.mediaDevices?.getUserMedia || !navigator.mediaDevices?.enumerateDevices) {
    throw new Error('Camera API is not available. Use a current Chrome or Edge browser.');
  }
}

function cameraAccessErrorMessage(err) {
  const name = err?.name || '';
  const msg  = String(err?.message || err || '');
  if (/INSECURE_ORIGIN/i.test(msg)) return msg.replace(/^INSECURE_ORIGIN:\s*/i, '');
  if (name === 'NotAllowedError' || /denied|not allowed|Permission/i.test(msg))
    return 'Camera permission denied. Click the lock icon in the address bar, set Camera to Allow, then reload.';
  if (name === 'NotFoundError' || /Could not start video source|DevicesNotFound/i.test(msg))
    return 'No camera found. Plug in a webcam and close other apps (Zoom, Teams, OBS) using it.';
  if (name === 'NotReadableError' || name === 'TrackStartError' || /Could not start|busy|in use/i.test(msg))
    return 'Camera could not start (likely in use). Close other apps using the camera, then try again.';
  if (name === 'OverconstrainedError')
    return 'Camera does not support these settings. Try a different camera.';
  if (name === 'SecurityError' || /secure context/i.test(msg))
    return 'Camera requires https:// or http://localhost.';
  return msg || 'Unknown camera error.';
}

async function enumerateVideoInputsWithLabels() {
  assertCameraEnvironment();
  let tmp;
  const constraints = [
    { video: true },
    { video: { facingMode: { ideal: 'environment' } } },
    { video: { facingMode: { ideal: 'user' } } },
  ];
  let lastErr;
  for (const c of constraints) {
    try { tmp = await navigator.mediaDevices.getUserMedia(c); break; }
    catch (e) { lastErr = e; }
  }
  if (!tmp) throw new Error(cameraAccessErrorMessage(lastErr));
  tmp.getTracks().forEach((t) => t.stop());
  await new Promise((r) => setTimeout(r, 280));
  const inputs = (await navigator.mediaDevices.enumerateDevices()).filter((d) => d.kind === 'videoinput');
  if (!inputs.length) throw new Error('No camera found.');
  return inputs.map((d, i) => ({ deviceId: d.deviceId, label: d.label?.trim() || `Camera ${i + 1}` }));
}

function pickPreferredCameraDeviceId(devices, savedId) {
  if (!devices?.length) return null;
  const saved = devices.find((d) => d.deviceId === savedId && !isLikelyVirtualCameraLabel(d.label));
  if (saved) return saved.deviceId;
  const physical = devices.find((d) => !isLikelyVirtualCameraLabel(d.label));
  if (physical) return physical.deviceId;
  return devices[0].deviceId;
}

// ─── QuickAddModal ────────────────────────────────────────────────────────────

function QuickAddModal({ onSave, onClose }) {
  const [form, setForm]     = useState({ customer_type: 'walk_in', customer_name: '', contact_number: '', email: '' });
  const [saving, setSaving] = useState(false);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setSaving(true);
    try {
      const res  = await customersAPI.create(form);
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
          <input
            value={form.customer_name}
            onChange={(e) => setForm((f) => ({ ...f, customer_name: e.target.value }))}
            required
            autoFocus
          />
        </div>
        <div className="isp-form-group">
          <label>Type</label>
          <select
            value={form.customer_type}
            onChange={(e) => setForm((f) => ({ ...f, customer_type: e.target.value }))}
          >
            <option value="registered">Registered</option>
            <option value="walk_in">Walk-in</option>
          </select>
        </div>
        <div className="isp-form-group">
          <label>Contact Number</label>
          <input
            value={form.contact_number}
            onChange={(e) => setForm((f) => ({ ...f, contact_number: e.target.value }))}
          />
        </div>
        <div className="isp-form-group">
          <label>Email</label>
          <input
            type="email"
            value={form.email}
            onChange={(e) => setForm((f) => ({ ...f, email: e.target.value }))}
          />
        </div>
        <div className="isp-form-actions">
          <button type="button" className="isp-btn-secondary" onClick={onClose}>Cancel</button>
          <button type="submit" className="isp-btn-primary" disabled={saving}>
            {saving ? 'Saving...' : 'Save Customer'}
          </button>
        </div>
      </form>
    </Modal>
  );
}

// ─── Main page ────────────────────────────────────────────────────────────────

export default function IssuanceScanPage() {
  const { user, refreshUser, can } = useAuth();
  const { locationId: enforcedLocationId, locationName, locationType, isStrictLocation, allowedLocations } = useUserLocation();

  const isAdmin = getRoleKey(user || {}) === ROLES.ADMIN;
  // Admin always; anyone else granted the live inventory_issuance/create permission.
  const canIssue = isAdmin || (typeof can === 'function' && can('inventory_issuance', 'create'));

  const [step, setStep]               = useState(STEPS.SETUP);
  const [form, setForm]               = useState({ ...EMPTY_FORM });
  const [locations, setLocations]     = useState([]);
  const [session, setSession]         = useState(null);
  const [items, setItems]             = useState([]);

  // Customer mode: 'registered' | 'walkin'
  const [customerMode, setCustomerMode]   = useState('registered');
  const [walkInMode, setWalkInMode]       = useState('registry');
  const [walkInName, setWalkInName]       = useState('');

  // Customer search
  const [customerQuery, setCustomerQuery]     = useState('');
  const [customerResults, setCustomerResults] = useState([]);
  const [customerLoading, setCustomerLoading] = useState(false);
  const [showDropdown, setShowDropdown]       = useState(false);
  const [quickAddOpen, setQuickAddOpen]       = useState(false);

  // ── Scan state ───────────────────────────────────────────────
  const scanInputRef                              = useRef(null);
  const [scanValue, setScanValue]                 = useState('');
  const [scanProcessing, setScanProcessing]       = useState(false);
  const [scanFeedback, setScanFeedback]           = useState({ type: null, message: '' });

  // ── Camera state ─────────────────────────────────────────────
  const [cameraActive, setCameraActive]     = useState(false);
  const [cameraError, setCameraError]       = useState('');
  const [cameraDevices, setCameraDevices]   = useState([]);
  const [selectedCameraId, setSelectedCameraId] = useState(() => {
    try { return localStorage.getItem(CAMERA_STORAGE_KEY) || ''; } catch { return ''; }
  });

  // ── Consumable quantity panel ────────────────────────────────
  const [pendingScan, setPendingScan]       = useState(null);
  const [selectedAction, setSelectedAction] = useState(null);
  const [showCustomInput, setShowCustomInput] = useState(false);
  const [customQty, setCustomQty]           = useState('');
  const [customUnit, setCustomUnit]         = useState('');
  const customQtyRef                        = useRef(null);

  const computedPieces = useMemo(() => {
    if (selectedAction?.quantity_in_pieces != null) return selectedAction.quantity_in_pieces;
    const qty = parseFloat(customQty) || 0;
    if (!qty || !pendingScan) return 0;
    const unitName = customUnit || pendingScan.base_unit_name;
    const match = (pendingScan.quick_actions ?? [])
      .find(a => a.unit_name === unitName && !a.is_custom);
    if (match) return Math.max(1, Math.ceil(qty * (match.conversion_factor ?? 1)));
    return Math.max(1, Math.ceil(qty));
  }, [selectedAction, customQty, customUnit, pendingScan]);

  const computedSubtotal = useMemo(() => {
    if (!pendingScan) return 0;
    return Math.round(pendingScan.price_per_piece * computedPieces * 100) / 100;
  }, [pendingScan, computedPieces]);

  // ── Units for dropdown ───────────────────────────────────────
  const [units, setUnits] = useState([]);

  // ── Completion ───────────────────────────────────────────────
  const [completing, setCompleting] = useState(false);
  const [receipt, setReceipt]       = useState(null);

  // ── Setup submitting ─────────────────────────────────────────
  const [setupSaving, setSetupSaving] = useState(false);

  // ── Camera refs ──────────────────────────────────────────────
  const html5QrRef    = useRef(null);
  const lastCodeRef   = useRef('');
  const lastTimeRef   = useRef(0);
  const handleScanRef = useRef(null);

  // Hydrate full location data (enforced_location_id, allowed_locations, etc.)
  useEffect(() => { refreshUser(); }, [refreshUser]);

  // ── Load locations (still needed for admin/BM fallback if allowedLocations is empty) ──
  useEffect(() => {
    locationsAPI.getAll({ per_page: 200 }).then((res) => {
      const list = Array.isArray(res?.data) ? res.data : [];
      setLocations(list);
    }).catch(() => {});
  }, []);

  // ── Load units for consumable qty dropdown ───────────────────
  useEffect(() => {
    unitsAPI.getAll({ per_page: 100 }).then((res) => {
      const list = res?.data?.data ?? res?.data ?? [];
      setUnits(Array.isArray(list) ? list : []);
    }).catch(() => {
      setUnits([
        { unit_id: 1, unit_name: 'Unit',     unit_abbreviation: 'pc' },
        { unit_id: 2, unit_name: 'Piece',    unit_abbreviation: 'pc' },
        { unit_id: 3, unit_name: 'Roll',     unit_abbreviation: 'roll' },
        { unit_id: 4, unit_name: 'Meter',    unit_abbreviation: 'm' },
        { unit_id: 6, unit_name: 'Box',      unit_abbreviation: 'box' },
        { unit_id: 7, unit_name: 'Kilogram', unit_abbreviation: 'kg' },
        { unit_id: 8, unit_name: 'Liter',    unit_abbreviation: 'L' },
      ]);
    });
  }, []);

  // ── Refresh session when entering Review step ────────────────
  useEffect(() => {
    if (step === STEPS.REVIEW) {
      refreshSession();
    }
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [step]);

  // ── Stop camera when leaving Scan step ───────────────────────
  useEffect(() => {
    if (step !== STEPS.SCAN && cameraActive) {
      stopReaderAndTracks().then(() => setCameraActive(false));
    }
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [step]);

  // ── Auto-focus scan input when Step 2 is active ─────────────
  useEffect(() => {
    if (step === STEPS.SCAN && scanInputRef.current && !pendingScan && !cameraActive) {
      scanInputRef.current.focus();
    }
  }, [step, pendingScan, cameraActive]);

  // ── Re-focus scan input after quantity panel is dismissed ────
  useEffect(() => {
    if (!pendingScan && step === STEPS.SCAN && scanInputRef.current && !cameraActive) {
      const timer = setTimeout(() => scanInputRef.current?.focus(), 100);
      return () => clearTimeout(timer);
    }
  }, [pendingScan, step, cameraActive]);

  // ── Auto-clear feedback after 3 seconds ─────────────────────
  useEffect(() => {
    if (scanFeedback.type !== null) {
      const timer = setTimeout(() => {
        setScanFeedback({ type: null, message: '' });
        if (!pendingScan && !cameraActive) scanInputRef.current?.focus();
      }, 3000);
      return () => clearTimeout(timer);
    }
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [scanFeedback]);

  // ── Camera cleanup on unmount ────────────────────────────────
  useEffect(() => () => {
    const h = html5QrRef.current;
    html5QrRef.current = null;
    if (h) { h.stop().catch(() => {}); }
  }, []);

  // ── Customer search ──────────────────────────────────────────
  const customerSearchTimer = useRef(null);
  useEffect(() => {
    clearTimeout(customerSearchTimer.current);
    if (!customerQuery.trim() || customerQuery.trim().length < 2) {
      setCustomerResults([]);
      return;
    }
    customerSearchTimer.current = setTimeout(async () => {
      setCustomerLoading(true);
      try {
        const res = await customersAPI.search(customerQuery);
        const list = Array.isArray(res?.data) ? res.data : [];
        setCustomerResults(list);
        setShowDropdown(true);
      } catch {
        setCustomerResults([]);
      } finally {
        setCustomerLoading(false);
      }
    }, 300);
    return () => clearTimeout(customerSearchTimer.current);
  }, [customerQuery]);

  const selectCustomer = (c) => {
    setForm((f) => ({ ...f, customer_id: c.customer_id, customer: c }));
    setCustomerQuery(c.customer_name);
    setShowDropdown(false);
  };

  // ── Step 1 submit ────────────────────────────────────────────
  const handleSetupSubmit = async (e) => {
    e.preventDefault();
    const isOneTime  = customerMode === 'walkin' && walkInMode === 'onetime';
    const isRegistry = customerMode === 'walkin' && walkInMode === 'registry';

    if (customerMode === 'registered' && !form.customer_id) {
      toast.error('Select a customer.'); return;
    }
    if (isRegistry && !form.customer_id) {
      toast.error('Add or select a walk-in customer first.'); return;
    }
    if (isOneTime && !walkInName.trim()) {
      toast.error('Enter the walk-in customer name.'); return;
    }
    const effectiveLocationId = isStrictLocation
      ? (enforcedLocationId ?? null)
      : (form.location_id ? parseInt(form.location_id, 10) : null);
    if (!effectiveLocationId) { toast.error('Select a location.'); return; }

    setSetupSaving(true);
    try {
      const baseNotes = form.notes ? form.notes.trim() : null;
      const notesVal  = isOneTime
        ? `Walk-in: ${walkInName.trim()}${baseNotes ? '. ' + baseNotes : ''}`
        : baseNotes;

      const payload = {
        issuance_type:    form.issuance_type,
        discount_type:    form.discount_type || 'none',
        discount_percent: form.issuance_type === 'business' ? parseFloat(form.discount_percent) || 0 : 0,
        location_id:      effectiveLocationId,
        notes:            notesVal || null,
      };
      if (!isOneTime) payload.customer_id = parseInt(form.customer_id);

      const res = await issuanceScanAPI.initSession(payload);
      setSession(res);
      setItems([]);
      setStep(STEPS.SCAN);
    } catch (err) {
      toast.error(err.message || 'Failed to start session.');
    } finally {
      setSetupSaving(false);
    }
  };

  // ── Session refresh ──────────────────────────────────────────
  const refreshSession = useCallback(async () => {
    if (!session?.issuance_id) return;
    try {
      const res = await issuanceScanAPI.getSession(session.issuance_id);
      const iss = res?.issuance ?? res?.data ?? res;
      if (iss?.items) setItems(iss.items);
      if (iss) setSession((prev) => ({ ...prev, ...iss }));
    } catch { /* non-fatal */ }
  }, [session?.issuance_id]);

  // ── Scan logic ───────────────────────────────────────────────

  const handleScanResponse = useCallback((data, barcode) => {
    if (data?.success && data?.action === 'applied') {
      refreshSession();
      playScanFeedback(true);
      if (data.product_type === 'appliance') {
        setScanFeedback({
          type: 'success',
          message: `${data.product_name} — SN: ${data.serial_number}`,
          detail: `${fmt(data.subtotal)}`,
        });
      } else {
        setScanFeedback({
          type: 'success',
          message: `${data.product_name} — ${data.quantity_issued} ${data.quantity_unit}`,
          detail: `${fmt(data.subtotal)} (${'₱'}${Number(data.price_per_piece).toFixed(4)} per ${data.quantity_unit})`,
        });
      }
    } else {
      playScanFeedback(false);
      setScanFeedback({
        type: 'error',
        message: data?.reason ?? data?.message ?? 'Scan rejected.',
        detail: `Barcode: ${barcode}`,
      });
    }
  }, [refreshSession]);

  const applyApplianceScan = useCallback(async (barcode) => {
    try {
      const res = await issuanceScanAPI.scanItem({
        issuance_id: session.issuance_id,
        barcode,
        location_id: parseInt(form.location_id),
      });
      handleScanResponse(res, barcode);
    } catch (err) {
      playScanFeedback(false);
      setScanFeedback({
        type: 'error',
        message: err.message || 'Request failed.',
        detail: 'Barcode: ' + barcode,
      });
    }
  }, [session, form.location_id, handleScanResponse]);

  const handleScan = useCallback(async (barcode) => {
    const trimmed = barcode.trim();
    if (!trimmed || scanProcessing) return;

    setScanProcessing(true);
    setScanValue('');

    try {
      const lookupRes = await barcodeScanAPI.lookup(trimmed);
      const ld     = lookupRes?.data ?? lookupRes;
      const status = ld?.status;

      if (status === 'PENDING_CONSUMABLE') {
        playScanFeedback(false);
        setScanFeedback({
          type: 'error',
          message: 'Product registration pending approval.',
          detail: `Barcode: ${trimmed}`,
        });
        return;
      }

      const product      = ld?.product;
      const isSerialized = ld?.is_serialized ?? false;

      if (status !== 'FOUND' || !product || isSerialized) {
        await applyApplianceScan(trimmed);
        return;
      }

      const piecesPerPackage = ld.pieces_per_package ?? product.getPiecesPerPackage?.() ?? 1;
      const packagePrice     = parseFloat(product.unit_price) || 0;
      const pricePerPiece    = piecesPerPackage > 0
        ? Math.round((packagePrice / piecesPerPackage) * 10000) / 10000
        : packagePrice;
      const cd          = product.consumableDetail ?? product.consumable_detail ?? {};
      const packageUnit = cd.package_unit ?? ld.unit_name ?? '';
      const unitName    = ld.unit_name ?? cd.unit?.unit_name ?? 'Piece';

      setSelectedAction(null);
      setShowCustomInput(false);
      setCustomQty('');
      setCustomUnit('');
      setPendingScan({
        barcode:            trimmed,
        product_id:         product.product_id,
        product_name:       product.product_name,
        product_code:       product.product_code,
        base_unit_name:     ld.base_unit_name  ?? unitName,
        base_unit_abbr:     ld.base_unit_abbr  ?? ld.unit_abbr ?? cd.unit?.unit_abbreviation ?? 'pc',
        pieces_per_package: piecesPerPackage,
        package_unit:       ld.package_unit    ?? packageUnit ?? 'package',
        package_price:      ld.package_price   ?? packagePrice,
        price_per_piece:    ld.price_per_piece ?? pricePerPiece,
        quick_actions:      ld.quick_actions      ?? [],
        dropdown_units:     ld.dropdown_units     ?? [],
        has_issuable_units: ld.has_issuable_units ?? false,
      });

    } catch (err) {
      playScanFeedback(false);
      setScanFeedback({
        type: 'error',
        message: err.message || 'Scan failed.',
        detail: 'Check your connection and try again.',
      });
    } finally {
      setScanProcessing(false);
    }
  }, [scanProcessing, applyApplianceScan]);

  // Keep handleScanRef in sync so camera callbacks always call the latest version
  useEffect(() => {
    handleScanRef.current = handleScan;
  }, [handleScan]);

  // ── Camera functions ─────────────────────────────────────────

  const stopReaderAndTracks = useCallback(async () => {
    const h = html5QrRef.current;
    html5QrRef.current = null;
    if (h) {
      try { await h.stop(); } catch { /* not running */ }
      try { h.clear(); } catch { /* ignore */ }
    }
  }, []);

  const applyDecodedBarcode = useCallback((decodedText) => {
    const code = String(decodedText || '').replace(/\s+/g, '').trim();
    if (!code) return;
    const now = Date.now();
    if (code === lastCodeRef.current && now - lastTimeRef.current < CAMERA_SAME_CODE_MS) return;
    lastCodeRef.current = code;
    lastTimeRef.current = now;
    handleScanRef.current?.(code);
  }, []);

  const startHtml5OnDevice = useCallback(async (deviceId) => {
    await stopReaderAndTracks();
    await new Promise((r) => setTimeout(r, 80));
    const mount = document.getElementById(ISP_HTML5_QR_MOUNT_ID);
    if (!mount) throw new Error('Scanner mount not found.');
    const h5 = new Html5Qrcode(ISP_HTML5_QR_MOUNT_ID, {
      formatsToSupport: HTML5_FORMATS,
      useBarCodeDetectorIfSupported: true,
      verbose: false,
    });
    html5QrRef.current = h5;
    await h5.start(
      { deviceId: { exact: deviceId } },
      getHtml5CameraScanConfig(),
      applyDecodedBarcode,
      () => {},
    );
  }, [applyDecodedBarcode, stopReaderAndTracks]);

  const startCamera = async () => {
    setCameraError('');
    if (!document.getElementById(ISP_HTML5_QR_MOUNT_ID)) {
      setCameraError('Scanner not ready. Try closing and reopening this page.');
      return;
    }
    try {
      const savedId = localStorage.getItem(CAMERA_STORAGE_KEY) || '';
      if (savedId) {
        const known = cameraDevices.find((d) => d.deviceId === savedId);
        if (known && isLikelyVirtualCameraLabel(known.label)) {
          localStorage.removeItem(CAMERA_STORAGE_KEY);
          setSelectedCameraId('');
        }
      }
    } catch { /* ignore */ }

    await stopReaderAndTracks();
    setCameraActive(true);
    await new Promise((r) => setTimeout(r, 100));

    try {
      const allDevices = await enumerateVideoInputsWithLabels();
      const devices    = sortVideoDevicesPhysicalFirst(allDevices);
      setCameraDevices(devices);

      const physicalOnly  = allDevices.filter((d) => !isLikelyVirtualCameraLabel(d.label));
      const virtualOnly   = allDevices.filter((d) => isLikelyVirtualCameraLabel(d.label));
      const selectionPool = physicalOnly.length > 0
        ? sortPhysicalCamerasDefaultFirst(physicalOnly)
        : sortVideoDevicesPhysicalFirst(allDevices);

      const resolvedId = pickPreferredCameraDeviceId(selectionPool, selectedCameraId);
      if (!resolvedId) throw new Error('No usable camera device.');

      setSelectedCameraId(resolvedId);
      try { localStorage.setItem(CAMERA_STORAGE_KEY, resolvedId); } catch { /* ignore */ }

      const physicalSorted = physicalOnly.length > 0 ? sortPhysicalCamerasDefaultFirst(physicalOnly) : [];
      const tryOrder = physicalOnly.length > 0
        ? [resolvedId, ...physicalSorted.map((d) => d.deviceId).filter((id) => id !== resolvedId), ...virtualOnly.map((d) => d.deviceId)]
        : [resolvedId, ...allDevices.map((d) => d.deviceId).filter((id) => id !== resolvedId)];
      const uniqueTry = [...new Set(tryOrder)];

      let opened = false;
      let lastErr;
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
            const retryable = attempt === 0 && (err?.name === 'NotReadableError' || err?.name === 'TrackStartError');
            if (!retryable) break;
          }
        }
        if (opened) break;
      }
      if (!opened) throw lastErr || new Error('Could not open any camera.');
    } catch (e) {
      await stopReaderAndTracks();
      setCameraActive(false);
      setCameraError(cameraAccessErrorMessage(e) + ' You can still use a USB barcode scanner or type below.');
    }
  };

  const onCameraDeviceChange = async (e) => {
    const id = e.target.value;
    setSelectedCameraId(id);
    try { localStorage.setItem(CAMERA_STORAGE_KEY, id); } catch { /* ignore */ }
    if (!cameraActive) return;
    try {
      await startHtml5OnDevice(id);
    } catch (err) {
      setCameraError(`Could not switch camera: ${cameraAccessErrorMessage(err)}`);
      await stopReaderAndTracks();
      setCameraActive(false);
    }
  };

  const stopCamera = useCallback(async () => {
    await stopReaderAndTracks();
    setCameraActive(false);
  }, [stopReaderAndTracks]);

  const manualFrameScan = useCallback(async () => {
    const mountEl = document.getElementById(ISP_HTML5_QR_MOUNT_ID);
    const v = mountEl?.querySelector('video');
    if (!cameraActive || !v || v.readyState < 2 || v.videoWidth < 48) {
      toast.info('Start the camera and wait for the live preview, then tap Scan.');
      return;
    }
    let code = null;
    if (hasNativeBarcodeDetector()) {
      try {
        const detector = new window.BarcodeDetector({ formats: NATIVE_BARCODE_FORMATS });
        const codes    = await detector.detect(v);
        const raw      = codes?.[0]?.rawValue;
        if (raw) code  = String(raw).replace(/\s+/g, '').trim();
      } catch { /* ignore */ }
    }
    if (!code) code = tryDecodeVideoCenterCropZxing(v);
    if (!code || code.length < 2) {
      toast.warning('No barcode found. Center the code in the frame, add light, and tap Scan again.');
      return;
    }
    const now = Date.now();
    lastCodeRef.current = code;
    lastTimeRef.current = now;
    handleScanRef.current?.(code);
  }, [cameraActive]);

  // ── applyConsumableScan ──────────────────────────────────────
  const applyConsumableScan = useCallback(async () => {
    if (!pendingScan) return;

    let finalUnitName, finalPieces;

    if (selectedAction && !selectedAction.is_custom) {
      // Quick action — use pre-computed values from the API
      finalUnitName = selectedAction.unit_name;
      finalPieces   = selectedAction.quantity_in_pieces
        ?? Math.max(1, Math.ceil(selectedAction.conversion_factor ?? 1));
    } else if (showCustomInput && parseFloat(customQty) > 0) {
      // Custom entry — qty is in the selected unit; derive pieces via conversion_factor
      const enteredQty = parseFloat(customQty);
      finalUnitName = customUnit || pendingScan.base_unit_name;
      const match = (pendingScan.quick_actions ?? [])
        .find(a => a.unit_name === finalUnitName && !a.is_custom);
      if (match) {
        finalPieces = Math.max(1, Math.ceil(enteredQty * (match.conversion_factor ?? 1)));
      } else {
        finalPieces = Math.max(1, Math.ceil(enteredQty));
      }
    } else {
      customQtyRef.current?.focus();
      return;
    }

    setScanProcessing(true);
    const capturedBarcode = pendingScan.barcode;
    try {
      const res = await issuanceScanAPI.scanItem({
        issuance_id:   session.issuance_id,
        barcode:       capturedBarcode,
        quantity:      finalPieces,
        quantity_unit: finalUnitName,
        location_id:   parseInt(form.location_id),
      });
      setPendingScan(null);
      setSelectedAction(null);
      setShowCustomInput(false);
      setCustomQty('');
      setCustomUnit('');
      handleScanResponse(res, capturedBarcode);
    } catch (err) {
      playScanFeedback(false);
      setScanFeedback({
        type: 'error',
        message: err.message || 'Request failed.',
        detail: 'Failed to apply scan. Try again.',
      });
    } finally {
      setScanProcessing(false);
    }
  }, [pendingScan, selectedAction, showCustomInput, customQty, customUnit,
      session, form.location_id, handleScanResponse]);

  // ── Remove item ──────────────────────────────────────────────
  const handleRemoveItem = async (detailId, productName) => {
    if (!(await confirmAction({ title: `Remove ${productName} from this issuance?`, confirmText: 'Remove', tone: 'danger' }))) return;
    try {
      await issuanceScanAPI.removeItem(detailId);
      await refreshSession();
      toast.success('Item removed.');
    } catch (err) {
      toast.error(err.message || 'Failed to remove item.');
    }
  };

  // ── Complete ─────────────────────────────────────────────────
  const handleComplete = async () => {
    setCompleting(true);
    try {
      const res = await issuanceScanAPI.complete(session.issuance_id);
      const iss = res?.issuance ?? res?.data ?? res;
      setReceipt(iss);
    } catch (err) {
      toast.error(err.message || 'Failed to complete issuance.');
    } finally {
      setCompleting(false);
    }
  };

  const handleNewIssuance = () => {
    stopCamera();
    setStep(STEPS.SETUP);
    setForm({ ...EMPTY_FORM });
    setCustomerMode('registered');
    setWalkInMode('registry');
    setWalkInName('');
    setCustomerQuery('');
    setSession(null);
    setItems([]);
    setScanValue('');
    setScanFeedback({ type: null, message: '' });
    setPendingScan(null);
    setSelectedAction(null);
    setShowCustomInput(false);
    setCustomQty('');
    setCustomUnit('');
    setReceipt(null);
    setCameraError('');
    setCameraDevices([]);
  };

  const handleReset = async () => {
    const msg = itemCount > 0
      ? `Reset this issuance? ${itemCount} scanned item${itemCount !== 1 ? 's' : ''} will be discarded and you will start over.`
      : 'Reset this issuance and start over?';
    if (!(await confirmAction({ title: msg, confirmText: 'Confirm', tone: 'danger' }))) return;
    handleNewIssuance();
  };

  // ── Computed totals ──────────────────────────────────────────
  const subtotal       = session?.subtotal        ?? 0;
  const discountAmount = session?.discount_amount ?? 0;
  const totalAmount    = session?.total_amount    ?? 0;
  const discountPct    = session?.discount_percent ?? 0;
  const itemCount      = items.length;

  const warrantyItems = (receipt?.items ?? []).filter((it) => it.warranty);
  const customerName  = session?.customer?.customer_name ?? form.customer?.customer_name ?? '—';

  const cameraSelectValue = cameraDevices.length > 0
    ? (cameraDevices.some((d) => d.deviceId === selectedCameraId) ? selectedCameraId : cameraDevices[0].deviceId)
    : '';

  // ─────────────────────────────────────────────────────────────
  // RENDER
  // ─────────────────────────────────────────────────────────────
  return (
    <AdminLayout>
      <div className="isp-page">

        {/* ── Step indicator ── */}
        <div className="isp-steps">
          {['Session Setup', 'Scan Items', 'Review', 'Complete'].map((label, idx) => {
            const s      = idx + 1;
            const active = step === s;
            const done   = step > s;
            return (
              <div key={s} className={`isp-step ${active ? 'isp-step-active' : ''} ${done ? 'isp-step-done' : ''}`}>
                <div className="isp-step-dot">
                  {done ? (
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" width="14" height="14">
                      <polyline points="20 6 9 17 4 12" />
                    </svg>
                  ) : s}
                </div>
                <span className="isp-step-label">{label}</span>
                {idx < 3 && <div className={`isp-step-line ${done ? 'isp-step-line-done' : ''}`} />}
              </div>
            );
          })}
        </div>

        {/* ════════════ STEP 1: SESSION SETUP ════════════ */}
        {step === STEPS.SETUP && (
          <div className="isp-card">
            <h2 className="isp-card-title">Session Setup</h2>
            <form onSubmit={handleSetupSubmit} className="isp-form" autoComplete="off">

              {/* Customer mode toggle */}
              <div className="isp-form-group">
                <label>Customer *</label>
                <div className="isp-mode-toggle">
                  <button
                    type="button"
                    className={`isp-mode-btn ${customerMode === 'registered' ? 'isp-mode-btn-active' : ''}`}
                    onClick={() => { setCustomerMode('registered'); setWalkInName(''); setForm((f) => ({ ...f, customer_id: '', customer: null })); setCustomerQuery(''); }}
                  >
                    Registered Customer
                  </button>
                  <button
                    type="button"
                    className={`isp-mode-btn ${customerMode === 'walkin' ? 'isp-mode-btn-active' : ''}`}
                    onClick={() => { setCustomerMode('walkin'); setForm((f) => ({ ...f, customer_id: '', customer: null })); setCustomerQuery(''); }}
                  >
                    Walk-in
                  </button>
                </div>
              </div>

              {/* Registered: search dropdown */}
              {customerMode === 'registered' && (
                <div className="isp-form-group" style={{ position: 'relative' }}>
                  <input
                    type="text"
                    value={customerQuery}
                    onChange={(e) => { setCustomerQuery(e.target.value); setForm((f) => ({ ...f, customer_id: '', customer: null })); }}
                    onFocus={() => customerQuery && setShowDropdown(true)}
                    placeholder="Type customer name or code..."
                    autoComplete="off"
                  />
                  {form.customer && (
                    <div className="isp-customer-selected">
                      <span className={`isp-type-badge isp-type-${form.customer.customer_type}`}>
                        {form.customer.customer_type === 'walk_in' ? 'Walk-in' : 'Registered'}
                      </span>
                      {form.customer.company_name && <span className="isp-customer-company">{form.customer.company_name}</span>}
                    </div>
                  )}
                  {showDropdown && (customerResults.length > 0 || customerLoading) && (
                    <div className="isp-dropdown">
                      {customerLoading && <div className="isp-dropdown-item isp-dropdown-loading">Searching...</div>}
                      {customerResults.map((c) => (
                        <button
                          key={c.customer_id}
                          type="button"
                          className="isp-dropdown-item"
                          onMouseDown={() => selectCustomer(c)}
                        >
                          <span className="isp-dropdown-name">{c.customer_name}</span>
                          <span className={`isp-type-badge isp-type-${c.customer_type}`}>
                            {c.customer_type === 'walk_in' ? 'Walk-in' : 'Registered'}
                          </span>
                          {c.company_name && <span className="isp-dropdown-company">{c.company_name}</span>}
                        </button>
                      ))}
                      <button
                        type="button"
                        className="isp-dropdown-item isp-dropdown-add"
                        onMouseDown={() => { setShowDropdown(false); setQuickAddOpen(true); }}
                      >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14">
                          <line x1="12" y1="5" x2="12" y2="19" /><line x1="5" y1="12" x2="19" y2="12" />
                        </svg>
                        Add New Customer
                      </button>
                    </div>
                  )}
                  {showDropdown && customerResults.length === 0 && !customerLoading && customerQuery.trim() && (
                    <div className="isp-dropdown">
                      <div className="isp-dropdown-item isp-dropdown-empty">No customers found.</div>
                      <button
                        type="button"
                        className="isp-dropdown-item isp-dropdown-add"
                        onMouseDown={() => { setShowDropdown(false); setQuickAddOpen(true); }}
                      >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14">
                          <line x1="12" y1="5" x2="12" y2="19" /><line x1="5" y1="12" x2="19" y2="12" />
                        </svg>
                        Add New Customer
                      </button>
                    </div>
                  )}
                </div>
              )}

              {/* Walk-in sub-options */}
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
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="13" height="13" style={{ marginRight: 4 }}>
                          <line x1="12" y1="5" x2="12" y2="19" /><line x1="5" y1="12" x2="19" y2="12" />
                        </svg>
                        {form.customer ? `Selected: ${form.customer.customer_name}` : 'Add / Select Walk-in Customer'}
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
                        Name will be stored in the issuance notes. No customer record will be created.
                      </p>
                    </div>
                  )}
                </div>
              )}

              {/* Issuance type + location */}
              <div className="isp-form-row">
                <div className="isp-form-group">
                  <label>Issuance Type *</label>
                  <select
                    value={form.issuance_type}
                    onChange={(e) => setForm((f) => ({ ...f, issuance_type: e.target.value, discount_percent: 0 }))}
                  >
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
                      allowedLocations={allowedLocations.length ? allowedLocations : locations.map(l => ({
                        location_id:   l.location_id ?? l.id,
                        location_name: l.location_name ?? l.name,
                        location_type: l.location_type ?? '',
                        location_code: l.location_code ?? '',
                      }))}
                    />
                  )}
                </div>
              </div>

              {/* Discount (business only) */}
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
                      <input
                        type="number" min="0" max="100" step="0.01"
                        value={form.discount_percent}
                        onChange={(e) => setForm((f) => ({ ...f, discount_percent: e.target.value }))}
                      />
                    </div>
                  )}
                </div>
              )}

              <div className="isp-form-group">
                <label>Notes (optional)</label>
                <textarea rows={2} value={form.notes} onChange={(e) => setForm((f) => ({ ...f, notes: e.target.value }))} />
              </div>

              <div className="isp-form-actions">
                <button type="submit" className="isp-btn-primary" disabled={setupSaving || !canIssue}>
                  {setupSaving ? 'Starting...' : 'Start Issuance'}
                </button>
                {!canIssue && <span style={{ fontSize: 12, color: '#dc2626', marginLeft: 8 }}>You do not have permission to issue stock.</span>}
              </div>
            </form>
          </div>
        )}

        {/* ════════════ STEP 2: SCAN ════════════ */}
        {step === STEPS.SCAN && session && (
          <>
            {/* Session header */}
            <div className="isp-session-header">
              <div className="isp-session-info">
                <span className="isp-session-dr">DR: {session.dr_number}</span>
                <span className="isp-session-sep">|</span>
                <span>{customerName}</span>
                <span className="isp-session-sep">|</span>
                <span className={`isp-type-badge isp-type-${session.issuance_type}`}>{session.issuance_type}</span>
                <span className="isp-session-sep">|</span>
                <span>{itemCount} item{itemCount !== 1 ? 's' : ''}</span>
                <span className="isp-session-sep">|</span>
                <span className="isp-session-total">Total: {fmt(totalAmount)}</span>
              </div>
              <div style={{ display: 'flex', gap: 8 }}>
                <button
                  type="button"
                  className="isp-btn-secondary"
                  onClick={handleReset}
                >
                  Reset
                </button>
                <button
                  className="isp-btn-complete"
                  disabled={itemCount === 0}
                  onClick={() => setStep(STEPS.REVIEW)}
                >
                  Review Items
                </button>
              </div>
            </div>

            {/* Scan card */}
            <div className="isp-card isp-scan-card">
              <h2 className="isp-card-title">Scan Item</h2>

              {/* ── Camera scanner (same design as Barcode Scan Receive) ── */}
              <div className="bs-camera-box" style={{ marginBottom: 12 }}>
                <div
                  id={ISP_HTML5_QR_MOUNT_ID}
                  className="bs-html5qrcode-mount"
                  style={{ display: cameraActive ? 'block' : 'none' }}
                />
                {!cameraActive && (
                  <div className="bs-cam-idle">
                    <div className="bs-cam-icon-wrap">
                      <svg viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.6)" strokeWidth="1.5" width="38" height="38">
                        <path d="M23 7l-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/>
                      </svg>
                    </div>
                  </div>
                )}
                <div className="bs-bracket bs-tl"/><div className="bs-bracket bs-tr"/>
                <div className="bs-bracket bs-bl"/><div className="bs-bracket bs-br"/>
                {cameraActive && <div className="bs-scan-line"/>}
                {cameraActive && (
                  <p className="bs-cam-hint">
                    Position the barcode in the frame and tap <strong>Scan</strong> to issue the item.
                  </p>
                )}
                {cameraError && <p className="bs-cam-err">{cameraError}</p>}
                {cameraActive && (
                  <button
                    type="button"
                    className="bs-cam-shutter"
                    onClick={manualFrameScan}
                    disabled={scanProcessing || !!pendingScan || !canIssue}
                    aria-label="Scan barcode from current camera frame"
                  >
                    <span className="bs-cam-shutter-inner"/>
                    <span className="bs-cam-shutter-label">Scan</span>
                  </button>
                )}
                {cameraActive
                  ? <button className="bs-cam-btn bs-cam-stop" onClick={stopCamera} type="button">Stop Camera</button>
                  : <button className="bs-cam-btn bs-cam-start" onClick={startCamera} type="button" disabled={!!pendingScan || scanProcessing}>
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14">
                        <path d="M23 7l-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/>
                      </svg>
                      Start Camera
                    </button>
                }
              </div>
              {cameraDevices.length > 0 && (
                <div className="bs-cam-select-bar" style={{ marginBottom: 12 }}>
                  <label htmlFor="isp-camera-device" className="bs-cam-select-bar-label">Camera</label>
                  <select
                    id="isp-camera-device"
                    className="bs-cam-select-bar-input"
                    value={cameraSelectValue}
                    onChange={onCameraDeviceChange}
                  >
                    {cameraDevices.map((d) => (
                      <option key={d.deviceId} value={d.deviceId}>
                        {d.label}{isLikelyVirtualCameraLabel(d.label) ? ' (virtual)' : ''}
                      </option>
                    ))}
                  </select>
                </div>
              )}

              {/* Scan input panel */}
              <div className="scan-panel">
                <label className="scan-label" htmlFor="issuance-scan-input">
                  {cameraActive ? 'Or type / paste barcode manually below' : 'Scan barcode to issue item'}
                </label>

                <div className="scan-input-row">
                  <input
                    id="issuance-scan-input"
                    ref={scanInputRef}
                    type="text"
                    value={scanValue}
                    onChange={(e) => setScanValue(e.target.value)}
                    onKeyDown={(e) => {
                      if (e.key === 'Enter') {
                        e.preventDefault();
                        if (scanValue.trim()) handleScan(scanValue);
                      }
                    }}
                    placeholder="Scan or type barcode and press Enter"
                    disabled={scanProcessing || !!pendingScan}
                    autoComplete="off"
                    autoCorrect="off"
                    autoCapitalize="off"
                    spellCheck={false}
                    className={`scan-input${scanProcessing ? ' scan-input--processing' : ''}`}
                    aria-label="Barcode scan input"
                  />
                  <button
                    type="button"
                    onClick={() => { if (scanValue.trim()) handleScan(scanValue); }}
                    disabled={!scanValue.trim() || scanProcessing || !!pendingScan || !canIssue}
                    className="scan-submit-btn"
                  >
                    {scanProcessing ? 'Scanning...' : 'Apply'}
                  </button>
                </div>

                <div className="scan-hints">
                  <span>Appliances: scan the unit serial number</span>
                  <span className="scan-hint-sep">|</span>
                  <span>Consumables: scan the product barcode, enter quantity below</span>
                </div>

                {/* Feedback bar — 3-second auto-clear */}
                {scanFeedback.type && (
                  <div className={`scan-feedback scan-feedback--${scanFeedback.type}`}>
                    <span className="scan-feedback-message">{scanFeedback.message}</span>
                    {scanFeedback.detail && (
                      <span className="scan-feedback-detail">{scanFeedback.detail}</span>
                    )}
                  </div>
                )}
              </div>

              {/* Consumable quantity panel */}
              {pendingScan && (
                <div className="qty-panel">

                  {/* ── Panel header ── */}
                  <div className="qty-panel-header">
                    <div className="qty-panel-product-info">
                      <span className="qty-panel-product-name">{pendingScan.product_name}</span>
                      {pendingScan.product_code && (
                        <span className="qty-panel-product-code">{pendingScan.product_code}</span>
                      )}
                    </div>
                    <button
                      type="button"
                      className="qty-panel-close"
                      onClick={() => {
                        setPendingScan(null);
                        setScanValue('');
                        setSelectedAction(null);
                        setShowCustomInput(false);
                        setCustomQty('');
                        setCustomUnit('');
                      }}
                      aria-label="Cancel"
                    >
                      Cancel
                    </button>
                  </div>

                  {/* ── Pricing reference row ── */}
                  <div className="qty-pricing-ref">
                    <span className="qty-pricing-ref-item">
                      Package price:
                      <strong>
                        {' '}&#8369;{Number(pendingScan.package_price)
                          .toLocaleString('en-PH', { minimumFractionDigits: 2 })}
                        {' '}/ {pendingScan.package_unit ?? 'package'}
                      </strong>
                    </span>
                    <span className="qty-pricing-sep">—</span>
                    <span className="qty-pricing-ref-item">
                      Per {pendingScan.base_unit_name}:
                      <strong>
                        {' '}&#8369;{Number(pendingScan.price_per_piece).toFixed(4)}
                      </strong>
                    </span>
                  </div>

                  {/* ── Quick-action buttons ── */}
                  <div className="qty-quick-section-label">Select quantity:</div>
                  <div className="qty-quick-grid">
                    {(pendingScan.quick_actions ?? [])
                      .filter(a => a.show_as_quick_action)
                      .map((action, idx) => {
                        const isSelected = action.is_custom
                          ? showCustomInput
                          : selectedAction?.label === action.label;
                        return (
                          <button
                            key={idx}
                            type="button"
                            className={[
                              'qty-quick-btn',
                              isSelected              ? 'qty-quick-btn--selected' : '',
                              action.is_whole_package ? 'qty-quick-btn--bulk'     : '',
                              action.is_custom        ? 'qty-quick-btn--custom'   : '',
                            ].filter(Boolean).join(' ')}
                            disabled={scanProcessing}
                            onClick={() => {
                              if (action.is_custom) {
                                setSelectedAction(null);
                                setShowCustomInput(true);
                                setCustomQty('');
                                setTimeout(() => customQtyRef.current?.focus(), 50);
                              } else {
                                setSelectedAction(action);
                                setShowCustomInput(false);
                                setCustomQty('');
                              }
                            }}
                          >
                            <span className="qty-quick-btn-label">{action.label}</span>
                            {action.price_preview && (
                              <span className="qty-quick-btn-price">{action.price_preview}</span>
                            )}
                          </button>
                        );
                      })}
                  </div>

                  {/* ── Custom input row ── */}
                  {showCustomInput && (
                    <div className="qty-custom-row">
                      <label className="qty-custom-label">Enter quantity:</label>
                      <input
                        ref={customQtyRef}
                        type="number"
                        min="0.001"
                        step="1"
                        value={customQty}
                        onChange={(e) => setCustomQty(e.target.value)}
                        onKeyDown={(e) => {
                          if (e.key === 'Enter' && parseFloat(customQty) > 0) {
                            e.preventDefault();
                            applyConsumableScan();
                          }
                        }}
                        placeholder="0"
                        className="qty-custom-input"
                      />
                      <select
                        value={customUnit || pendingScan.base_unit_name}
                        onChange={(e) => setCustomUnit(e.target.value)}
                        className="qty-custom-unit-select"
                      >
                        {(pendingScan.dropdown_units?.length > 0
                          ? pendingScan.dropdown_units
                          : units
                        ).map((u) => (
                          <option key={u.unit_id} value={u.unit_name}>
                            {u.unit_name} ({u.unit_abbreviation})
                          </option>
                        ))}
                      </select>
                    </div>
                  )}

                  {/* ── Live total preview ── */}
                  {(selectedAction || (showCustomInput && parseFloat(customQty) > 0)) && (
                    <div className="qty-total-preview">
                      <div className="qty-total-preview-row">
                        <span className="qty-total-label">Quantity:</span>
                        <span className="qty-total-value">
                          {selectedAction
                            ? selectedAction.label
                            : `${customQty} ${customUnit || pendingScan.base_unit_name}`}
                        </span>
                      </div>
                      <div className="qty-total-preview-row">
                        <span className="qty-total-label">Pieces to deduct:</span>
                        <span className="qty-total-value">
                          {computedPieces} {pendingScan.base_unit_name}
                          {computedPieces !== 1 ? 's' : ''}
                        </span>
                      </div>
                      <div className="qty-total-preview-row qty-total-preview-row--total">
                        <span className="qty-total-label">Total:</span>
                        <span className="qty-total-amount">
                          &#8369;{computedSubtotal.toLocaleString('en-PH', { minimumFractionDigits: 2 })}
                        </span>
                      </div>
                    </div>
                  )}

                  {/* ── Add button ── */}
                  <div className="qty-panel-actions">
                    <button
                      type="button"
                      className="qty-add-btn"
                      disabled={
                        !canIssue ||
                        scanProcessing ||
                        (!selectedAction && (!customQty || parseFloat(customQty) <= 0))
                      }
                      onClick={applyConsumableScan}
                    >
                      {scanProcessing ? 'Adding...' : 'Add to Issuance'}
                    </button>
                  </div>

                </div>
              )}
            </div>
          </>
        )}

        {/* ════════════ STEP 3: REVIEW ════════════ */}
        {step === STEPS.REVIEW && session && (
          <>
            <div className="isp-session-header">
              <div className="isp-session-info">
                <span className="isp-session-dr">DR: {session.dr_number}</span>
                <span className="isp-session-sep">|</span>
                <span>{customerName}</span>
                <span className="isp-session-sep">|</span>
                <span className={`isp-type-badge isp-type-${session.issuance_type}`}>{session.issuance_type}</span>
                <span className="isp-session-sep">|</span>
                <span>{itemCount} item{itemCount !== 1 ? 's' : ''}</span>
                <span className="isp-session-sep">|</span>
                <span className="isp-session-total">Total: {fmt(totalAmount)}</span>
              </div>
              <div style={{ display: 'flex', gap: 8 }}>
                <button type="button" className="isp-btn-secondary" onClick={handleReset}>
                  Reset
                </button>
                <button type="button" className="isp-btn-secondary" onClick={() => setStep(STEPS.SCAN)}>
                  Back to Scan
                </button>
              </div>
            </div>

            <div className="isp-card">
              <h2 className="isp-card-title">Review Items ({itemCount})</h2>

              {itemCount === 0 ? (
                <div style={{ textAlign: 'center', padding: '32px 0', color: '#9ca3af', fontSize: 14 }}>
                  No items scanned yet.
                </div>
              ) : (
                <div className="isp-table-wrap">
                  <table className="isp-table">
                    <thead>
                      <tr>
                        <th>#</th>
                        <th>Product</th>
                        <th>Type</th>
                        <th>Serial / Quantity</th>
                        <th>Unit Price</th>
                        <th>Price / Unit</th>
                        <th>Subtotal</th>
                        <th>Warranty</th>
                        <th></th>
                      </tr>
                    </thead>
                    <tbody>
                      {items.map((it, idx) => (
                        <tr key={it.detail_id}>
                          <td style={{ color: '#9ca3af', fontSize: 12 }}>{idx + 1}</td>
                          <td>
                            <div className="isp-td-product">{it.product_name}</div>
                            {it.product_code && <div className="isp-td-code">{it.product_code}</div>}
                          </td>
                          <td>
                            <span className={`isp-ptype-badge isp-ptype-${(it.product_type || '').toLowerCase()}`}>
                              {it.product_type === 'appliance' ? 'Appliance' : 'Consumable'}
                            </span>
                          </td>
                          <td>
                            {it.serial_number
                              ? <span className="isp-serial">{it.serial_number}</span>
                              : <span>{it.quantity_issued} {it.quantity_unit}</span>
                            }
                          </td>
                          <td>{fmt(it.unit_price)}</td>
                          <td>
                            {it.product_type === 'consumable'
                              ? <span>{'₱'}{parseFloat(it.price_per_piece).toFixed(4)} / {it.quantity_unit}</span>
                              : fmt(it.unit_price)
                            }
                          </td>
                          <td>{fmt(it.subtotal)}</td>
                          <td>
                            {it.product_type === 'appliance'
                              ? (it.warranty
                                  ? <span className="isp-warranty-active">{it.warranty.warranty_period_months} mo.</span>
                                  : <span className="isp-warranty-none">No warranty</span>)
                              : <span className="isp-warranty-none">—</span>
                            }
                          </td>
                          <td>
                            <button
                              type="button"
                              className="isp-btn-remove"
                              onClick={() => handleRemoveItem(it.detail_id, it.product_name)}
                            >
                              Remove
                            </button>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}

              {/* Totals */}
              <div className="isp-totals">
                <div className="isp-total-row">
                  <span>Subtotal before discount</span>
                  <span>{fmt(subtotal)}</span>
                </div>
                {discountPct > 0 && (
                  <div className="isp-total-row isp-total-discount">
                    <span>Discount ({discountPct}%)</span>
                    <span>-{fmt(discountAmount)}</span>
                  </div>
                )}
                <div className="isp-total-divider" />
                <div className="isp-total-row isp-total-grand">
                  <span>Total Amount</span>
                  <span>{fmt(totalAmount)}</span>
                </div>
              </div>

              {/* Navigation */}
              <div className="isp-form-actions" style={{ marginTop: 20 }}>
                <button
                  type="button"
                  className="isp-btn-ghost"
                  onClick={() => {
                    setScanFeedback({ type: null, message: '' });
                    setStep(STEPS.SCAN);
                    setTimeout(() => scanInputRef.current?.focus(), 100);
                  }}
                >
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15">
                    <polyline points="15 18 9 12 15 6" />
                  </svg>
                  Back to Scan
                </button>
                <button
                  type="button"
                  className="isp-btn-complete"
                  disabled={itemCount === 0}
                  onClick={() => setStep(STEPS.COMPLETE)}
                >
                  Complete Issuance
                </button>
              </div>
            </div>
          </>
        )}

        {/* ════════════ STEP 4: COMPLETE ════════════ */}
        {step === STEPS.COMPLETE && session && (
          receipt ? (
            <div className="isp-card isp-dr-card">
              <div className="isp-dr-actions no-print">
                <button type="button" className="isp-btn-primary" onClick={() => window.print()}>
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="16" height="16">
                    <polyline points="6 9 6 2 18 2 18 9" />
                    <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2" />
                    <rect x="6" y="14" width="12" height="8" />
                  </svg>
                  Print DR
                </button>
                <button
                  type="button"
                  className="isp-btn-secondary"
                  onClick={() => window.open(issuanceScanAPI.drCsvUrl(receipt.issuance_id), '_blank')}
                >
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="16" height="16">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                    <polyline points="7 10 12 15 17 10" /><line x1="12" y1="15" x2="12" y2="3" />
                  </svg>
                  Download CSV
                </button>
                <button type="button" className="isp-btn-ghost" onClick={handleNewIssuance}>
                  New Issuance
                </button>
              </div>

              <div className="isp-dr-print">
                <div className="isp-dr-header">
                  <h1 className="isp-dr-title">DELIVERY RECEIPT</h1>
                </div>

                <div className="isp-dr-section-row">
                  <div className="isp-dr-block">
                    <div className="isp-dr-row"><span>DR Number</span><span>{receipt.dr_number}</span></div>
                    <div className="isp-dr-row"><span>Date</span><span>{fmtDate(receipt.issuance_date)}</span></div>
                    <div className="isp-dr-row"><span>Issuance Type</span>
                      <span className={`isp-type-badge isp-type-${receipt.issuance_type}`}>{receipt.issuance_type}</span>
                    </div>
                    <div className="isp-dr-row"><span>Issued By</span><span>{receipt.issued_by_name ?? '—'}</span></div>
                    <div className="isp-dr-row"><span>Location</span><span>{receipt.location_name ?? '—'}</span></div>
                  </div>
                  <div className="isp-dr-block">
                    <div className="isp-dr-block-label">CUSTOMER</div>
                    <div className="isp-dr-row"><span>Name</span><span>{receipt.customer?.customer_name ?? '—'}</span></div>
                    <div className="isp-dr-row"><span>Type</span>
                      <span className={`isp-type-badge isp-type-${receipt.customer?.customer_type}`}>{receipt.customer?.customer_type}</span>
                    </div>
                    <div className="isp-dr-row"><span>Company</span><span>{receipt.customer?.company_name ?? '—'}</span></div>
                    <div className="isp-dr-row"><span>Contact</span><span>{receipt.customer?.contact_number ?? '—'}</span></div>
                    <div className="isp-dr-row"><span>Address</span><span>{receipt.customer?.address ?? '—'}</span></div>
                  </div>
                </div>

                <h3 className="isp-dr-section-title">ITEMS ISSUED</h3>
                <table className="isp-dr-table">
                  <thead>
                    <tr>
                      <th>#</th>
                      <th>Product</th>
                      <th>Type</th>
                      <th>Serial / Qty</th>
                      <th>Unit Price</th>
                      <th>Price / Unit</th>
                      <th>Subtotal</th>
                    </tr>
                  </thead>
                  <tbody>
                    {(receipt.items ?? []).map((it, idx) => (
                      <tr key={it.detail_id}>
                        <td>{idx + 1}</td>
                        <td>
                          <div>{it.product_name}</div>
                          {it.brand_name && <div className="isp-td-code">{it.brand_name}</div>}
                          {it.product_code && <div className="isp-td-code">{it.product_code}</div>}
                        </td>
                        <td>{it.product_type === 'appliance' ? 'Appliance' : 'Consumable'}</td>
                        <td>
                          {it.serial_number
                            ? <span className="isp-serial">{it.serial_number}</span>
                            : `${it.quantity_issued} ${it.quantity_unit}`
                          }
                        </td>
                        <td>{fmt(it.unit_price)}</td>
                        <td>
                          {it.product_type === 'consumable'
                            ? `₱${parseFloat(it.price_per_piece).toFixed(4)} / ${it.quantity_unit}`
                            : fmt(it.unit_price)
                          }
                        </td>
                        <td>{fmt(it.subtotal)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>

                {warrantyItems.length > 0 && (
                  <>
                    <h3 className="isp-dr-section-title">WARRANTIES ACTIVATED</h3>
                    <table className="isp-dr-table">
                      <thead>
                        <tr>
                          <th>Serial Number</th>
                          <th>Product</th>
                          <th>Warranty Start</th>
                          <th>Warranty End</th>
                          <th>Duration</th>
                        </tr>
                      </thead>
                      <tbody>
                        {warrantyItems.map((it) => (
                          <tr key={it.detail_id}>
                            <td className="isp-serial">{it.serial_number}</td>
                            <td>{it.product_name}</td>
                            <td>{fmtDate(it.warranty.warranty_start_date)}</td>
                            <td>{fmtDate(it.warranty.warranty_end_date)}</td>
                            <td>{it.warranty.warranty_duration_label ?? `${it.warranty.warranty_period_months} months`}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </>
                )}

                <div className="isp-dr-totals">
                  <div className="isp-dr-total-row"><span>Subtotal (before discount)</span><span>{fmt(receipt.subtotal)}</span></div>
                  {parseFloat(receipt.discount_percent) > 0 && (
                    <div className="isp-dr-total-row"><span>Discount ({receipt.discount_percent}%)</span><span>-{fmt(receipt.discount_amount)}</span></div>
                  )}
                  <div className="isp-dr-total-grand"><span>TOTAL AMOUNT</span><span>{fmt(receipt.total_amount)}</span></div>
                </div>

                <div className="isp-dr-signatures">
                  <div className="isp-dr-sig">
                    <div className="isp-dr-sig-line" />
                    <div>Received by</div>
                  </div>
                  <div className="isp-dr-sig">
                    <div className="isp-dr-sig-line" />
                    <div>Issued by</div>
                  </div>
                </div>
              </div>
            </div>
          ) : (
            <div className="isp-card" style={{ maxWidth: 560, margin: '0 auto' }}>
              <h2 className="isp-card-title">Confirm and Complete</h2>
              <div className="isp-confirm-body">
                <div className="isp-confirm-summary">
                  <div className="isp-confirm-row"><span>Customer</span><strong>{customerName}</strong></div>
                  <div className="isp-confirm-row"><span>Issuance Type</span>
                    <span className={`isp-type-badge isp-type-${session.issuance_type}`}>{session.issuance_type}</span>
                  </div>
                  <div className="isp-confirm-row"><span>DR Number</span><span style={{ fontFamily: 'monospace', fontWeight: 700 }}>{session.dr_number}</span></div>
                  <div className="isp-confirm-row"><span>Items</span><span>{itemCount} item{itemCount !== 1 ? 's' : ''}</span></div>
                  {discountPct > 0 && (
                    <div className="isp-confirm-row"><span>Discount ({discountPct}%)</span><span>-{fmt(discountAmount)}</span></div>
                  )}
                  <div className="isp-confirm-row isp-confirm-total"><span>Total Amount</span><strong>{fmt(totalAmount)}</strong></div>
                </div>
                <p style={{ marginTop: 16, fontSize: 13, color: '#6b7280' }}>
                  This action will finalize the issuance, create a delivery receipt, and activate warranties for eligible appliances. This cannot be undone.
                </p>
              </div>
              <div className="isp-form-actions" style={{ marginTop: 8 }}>
                <button type="button" className="isp-btn-ghost" onClick={() => setStep(STEPS.REVIEW)}>
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15">
                    <polyline points="15 18 9 12 15 6" />
                  </svg>
                  Go Back
                </button>
                <button type="button" className="isp-btn-complete" onClick={handleComplete} disabled={completing || !canIssue}>
                  {completing ? 'Completing...' : 'Confirm and Complete'}
                </button>
              </div>
            </div>
          )
        )}

        {/* ── Quick-add customer modal ── */}
        {quickAddOpen && (
          <QuickAddModal
            onSave={(newCustomer) => { selectCustomer(newCustomer); setQuickAddOpen(false); }}
            onClose={() => setQuickAddOpen(false)}
          />
        )}
      </div>
    </AdminLayout>
  );
}
