import { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import Loader from '../components/Loader';
import Modal from '../components/Modal';
import ProductRegisterModal from '../components/ProductRegisterModal';
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
  itemsAPI, productsAPI, inventoryAPI, categoriesAPI, brandsAPI,
  receivingsAPI, issuancesAPI, transfersAPI, adjustmentsAPI, barcodeScanAPI, locationsAPI, purchaseOrdersAPI,
  istAPI,
} from '../services/api';
import { toast } from '../utils/toast';
import { useAuth, useUserLocation } from '../context/AuthContext';
import LocationBadge    from '../components/LocationBadge';
import LocationSelector from '../components/LocationSelector';
import ReceivingReceipt from '../components/ReceivingReceipt';
// NOTE: LocalStorage PO integration removed - dropdown shows only database POs
import { getRoleKey, ROLES } from '../utils/roles';
import {
  linesForLocation as bsrLinesForLocation,
  locationAmount as bsrLocationAmount,
  locationItemCount as bsrLocationItemCount,
  warehouseBreakdown as bsrWarehouseBreakdown,
} from '../utils/poReceivingTotals';
import '../styles/barcode_scan.css';
import '../styles/item_management.css';
import '../styles/po_receiving.css';

/** Short UI beep (success vs not found) — Receive PO smart scan. */
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

/** Mount point for html5-qrcode (must match DOM id). */
const HTML5_QR_MOUNT_ID = 'bs-html5-qrcode-mount';

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

const HTML5_QR_SCAN_FPS = 10;

function getHtml5CameraScanConfig() {
  return {
    fps: HTML5_QR_SCAN_FPS,
    qrbox: (viewfinderWidth, viewfinderHeight) => {
      const minDim = Math.min(viewfinderWidth, viewfinderHeight);
      const w = Math.max(160, Math.floor(Math.min(viewfinderWidth * 0.88, minDim * 0.92)));
      const h = Math.max(88, Math.floor(Math.min(viewfinderHeight * 0.42, minDim * 0.38)));
      return { width: w, height: h };
    },
  };
}

/* ─── mode config ─────────────────────────────────── */
const MODES = [
  {
    key: 'receive-po',
    label: 'Receive PO',
    apiLabel: 'RECEIVE PO',
    color: '#10b981',
    bg: '#ecfdf5',
    icon: (
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="28" height="28">
        <path d="M5 12h14"/><path d="m12 19-7-7 7-7"/>
      </svg>
    ),
  },
  {
    key: 'issue-out',
    label: 'Issue Out',
    apiLabel: 'ISSUE OUT',
    color: '#3b82f6',
    bg: '#eff6ff',
    icon: (
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="28" height="28">
        <path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>
      </svg>
    ),
  },
  {
    key: 'audit',
    label: 'Audit',
    apiLabel: 'AUDIT',
    color: '#6366f1',
    bg: '#eef2ff',
    icon: (
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="28" height="28">
        <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/><path d="M11 8v4l2.5 2.5"/>
      </svg>
    ),
  },
  {
    key: 'adjust',
    label: 'Adjust',
    apiLabel: 'ADJUST',
    color: '#ef4444',
    bg: '#fef2f2',
    icon: (
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="28" height="28">
        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
        <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
      </svg>
    ),
  },
];

/* ─── recent movement label helper ───────────────── */
const movementColor = (type = '') => {
  const t = type.toLowerCase();
  if (t.includes('receiv') || t.includes('receive')) return '#10b981';
  if (t.includes('issue') || t.includes('sale'))     return '#3b82f6';
  if (t.includes('transfer'))                         return '#f59e0b';
  if (t.includes('adjust'))                           return '#ef4444';
  return '#6b7280';
};

/** List Carmen-named showrooms first in the transfer destination dropdown */
const sortLocationsCarmenFirst = (list) => {
  const arr = [...(list || [])];
  arr.sort((a, b) => {
    const na = String(a.location_name || a.name || '').toLowerCase();
    const nb = String(b.location_name || b.name || '').toLowerCase();
    const score = (n) => (n.includes('carmen') ? 0 : 1);
    const diff = score(na) - score(nb);
    if (diff !== 0) return diff;
    return na.localeCompare(nb);
  });
  return arr;
};

const findCarmenLocationId = (list) => {
  const hit = (list || []).find((l) => {
    const n = String(l.location_name || l.name || '').toLowerCase();
    return n.includes('carmen');
  });
  if (!hit) return '';
  return String(hit.id ?? hit.location_id ?? '');
};

const inventoryRowLocationId = (row) =>
  row?.location_id ?? row?.location?.location_id ?? '';

/**
 * Pick stock row for the selected warehouse/location.
 * If locationId is set but there is no row for that location, returns null (do not show another site's qty).
 * If locationId is empty, prefer a warehouse row, else first row.
 */
const pickInventoryForLocation = (rows, locationId) => {
  if (!Array.isArray(rows) || rows.length === 0) return null;
  if (locationId) {
    const hit = rows.find((r) => String(inventoryRowLocationId(r)) === String(locationId));
    return hit || null;
  }
  const wh = rows.find((r) => String(r?.location?.location_type || '').toLowerCase() === 'warehouse');
  return wh || rows[0];
};

/** Available units at an inventory row (used before component helpers exist). */
const availableQtyAtInventoryRow = (inv) => {
  if (!inv) return 0;
  const a = inv.available_quantity;
  const q = inv.quantity_on_hand;
  if (a != null && a !== '') return Math.max(0, Number(a));
  if (q != null && q !== '') return Math.max(0, Number(q));
  return Math.max(0, Number(inv.quantity ?? inv.qty ?? 0));
};

/** Camera: ignore duplicate reads of the same code within this window (ms). */
const CAMERA_SAME_CODE_MS = 450;

function buildBarcodeReaderHints() {
  const hints = new Map();
  hints.set(DecodeHintType.TRY_HARDER, true);
  /* Do not set POSSIBLE_FORMATS — narrowing formats caused missed reads on some labels. */
  return hints;
}

/** Reused MultiFormatReader for center-crop decodes (full-frame video is often too small for 1D on laptop cams). */
let cropMultiFormatReader = null;
function getCropMultiFormatReader() {
  if (!cropMultiFormatReader) {
    cropMultiFormatReader = new MultiFormatReader();
    cropMultiFormatReader.setHints(buildBarcodeReaderHints());
  }
  return cropMultiFormatReader;
}

let cropWorkCanvas = null;
let cropWorkCtx = null;

/**
 * Decode from a zoomed center band of the video (where the UI scan line sits).
 * Built-in laptop cameras usually need this; full-frame ZXing sees too few pixels per bar.
 */
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
  const outW = Math.floor(cropW * scale);
  const outH = Math.floor(cropH * scale);
  if (!cropWorkCanvas) {
    cropWorkCanvas = document.createElement('canvas');
    cropWorkCtx = cropWorkCanvas.getContext('2d', { willReadFrequently: true });
  }
  if (!cropWorkCtx) return null;
  cropWorkCanvas.width = outW;
  cropWorkCanvas.height = outH;
  cropWorkCtx.filter = 'contrast(1.12) saturate(0.85)';
  cropWorkCtx.drawImage(video, sx, sy, cropW, cropH, 0, 0, outW, outH);
  cropWorkCtx.filter = 'none';
  try {
    const luminance = new HTMLCanvasElementLuminanceSource(cropWorkCanvas, true);
    const bitmap = new BinaryBitmap(new HybridBinarizer(luminance));
    const result = getCropMultiFormatReader().decode(bitmap);
    const text = typeof result.getText === 'function' ? result.getText() : '';
    const code = String(text || '').replace(/\s+/g, '').trim();
    return code || null;
  } catch (e) {
    if (e instanceof NotFoundException) return null;
    return null;
  }
}

/** Chromium BarcodeDetector (Chrome/Edge) — often reads phone-screen / glossy 1D better than ZXing alone. */
const NATIVE_BARCODE_FORMATS = [
  'qr_code',
  'ean_13',
  'ean_8',
  'code_128',
  'code_39',
  'codabar',
  'itf',
  'upc_a',
  'upc_e',
  'data_matrix',
  'pdf417',
];

function hasNativeBarcodeDetector() {
  return (
    typeof window !== 'undefined' &&
    typeof window.BarcodeDetector === 'function'
  );
}

const startOfLocalDay = (value) => {
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return null;
  d.setHours(0, 0, 0, 0);
  return d.getTime();
};

/**
 * Block Receive PO when status is not approved-like or before expected_delivery_date (API POs).
 * Local Approval Queue POs are not listed here until they exist in the API as purchase_orders rows.
 */
const getReceivePoBlockReason = (po) => {
  if (!po) return 'Selected purchase order was not found.';
  const statusName = String(po.status?.status_name ?? po.status_name ?? '').toLowerCase();
  if (statusName && /reject|cancel|void|denied|closed/.test(statusName)) {
    return 'This purchase order cannot be used for receiving.';
  }
  if (
    statusName
    && /pending|draft|awaiting|submitted/.test(statusName)
    && !/author|approv|complete|active|received|confirmed/.test(statusName)
  ) {
    return 'This PO is not approved yet. Approve it (or set status to authorized) before receiving.';
  }
  const rawExp = po.expected_delivery_date;
  if (!rawExp) return null;
  const expMs = startOfLocalDay(rawExp);
  const todayMs = startOfLocalDay(new Date());
  if (expMs == null || todayMs == null) return null;
  if (todayMs < expMs) {
    const days = Math.ceil((expMs - todayMs) / 86400000);
    const label = new Date(expMs).toLocaleDateString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric' });
    return `Receiving is blocked until the expected arrival date (${label}, in ${days} day(s)).`;
  }
  return null;
};

/** 
 * NO LONGER USED - LocalStorage merge removed.
 * Purchase Orders dropdown now shows ONLY real POs from the database (API).
 * This ensures the Receive PO dropdown reflects actual database records.
 */
const mergeSyncedLocalPurchaseOrders = (apiRows) => {
  // Return only API POs - no localStorage merge
  return Array.isArray(apiRows) ? apiRows : [];
};

const CAMERA_STORAGE_KEY = 'bs_inventory_camera_device_id';

/** Laravel list endpoints usually return `{ data: [...] }`; tolerate odd shapes. */
const extractApiDataArray = (res) => {
  const d = res?.data;
  if (Array.isArray(d)) return d;
  if (d && Array.isArray(d.data)) return d.data;
  return [];
};

/**
 * Known virtual / software cameras (OBS, etc.). `\bobs\b` catches "OBS Virtual Camera" / "OBS-Camera"
 * without matching substrings like "Panasonic" (no word "obs").
 */
const isLikelyVirtualCameraLabel = (label) => {
  const s = String(label || '').toLowerCase();
  if (!s) return false;
  return (
    /\bobs\b|\bobs\s*virtual\b|open\s*broadcaster|obs\s*studio|streamlabs|webcamoid|manycam|epocam|snap\s*camera|snapchat|droidcam|\bivcam\b|e2esoft|xsplit|chromacam|camtwist|newtek|unitycapture|screen\s*capture|desktop\s*video|fake|simulator|v\s*mixer|reincubate|iriun|iriun\s*webcam|\bndi\b|nvidia\s*broadcast|mmhmm|virtual\s*camera|virtual\s*webcam|virtual\s*device|\(virtual\)|^\s*obs\s*camera\s*$/.test(
      s,
    )
  );
};

/** List all devices; real cameras first, virtual last (default selection uses first non-virtual). */
const sortVideoDevicesPhysicalFirst = (all) => {
  const list = Array.isArray(all) ? [...all] : [];
  return list.sort((a, b) => {
    const va = isLikelyVirtualCameraLabel(a.label);
    const vb = isLikelyVirtualCameraLabel(b.label);
    if (va === vb) return 0;
    return va ? 1 : -1;
  });
};

/** Among physical cameras, prefer likely Windows default: built-in / USB / known OEM labels first. */
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

/** Browsers only expose camera on HTTPS or localhost — not on http://192.168.x.x etc. */
function assertCameraEnvironment() {
  if (typeof window === 'undefined') return;
  if (!window.isSecureContext) {
    const host = window.location?.hostname || '';
    const isLocalName =
      host === 'localhost' || host === '127.0.0.1' || host === '[::1]' || host === '::1';
    if (!isLocalName) {
      throw new Error(
        'INSECURE_ORIGIN: Camera is blocked on plain HTTP unless you use localhost. Open this app as http://localhost:PORT (or use HTTPS). Using http://192.168… or another PC name will not work.',
      );
    }
  }
  if (!navigator.mediaDevices?.getUserMedia || !navigator.mediaDevices?.enumerateDevices) {
    throw new Error(
      'Camera API is not available. Use a current Chrome or Edge browser, and open the site over https:// or http://localhost.',
    );
  }
}

async function getUserMediaVideo(constraints = { video: true }) {
  assertCameraEnvironment();
  const md = navigator.mediaDevices;
  if (md.getUserMedia) {
    return md.getUserMedia(constraints);
  }
  return Promise.reject(new Error('getUserMedia is not supported.'));
}

/** Map DOMException / Error to a short, actionable message for the UI. */
function cameraAccessErrorMessage(err) {
  const name = err?.name || '';
  const msg = String(err?.message || err || '');
  if (/INSECURE_ORIGIN/i.test(msg)) {
    return msg.replace(/^INSECURE_ORIGIN:\s*/i, '');
  }
  if (name === 'NotAllowedError' || /denied|not allowed|Permission/i.test(msg)) {
    return 'The browser did not allow the camera. Click the lock/camera icon in the address bar, set Camera to Allow, then reload the page. Also check Windows Settings → Privacy → Camera → allow desktop apps / browser access.';
  }
  if (name === 'NotFoundError' || /Could not start video source|DevicesNotFound/i.test(msg)) {
    return 'No camera was found or it is disabled. Plug in the webcam, enable it in Device Manager, and close other apps (Zoom, Teams, OBS) that may be using it.';
  }
  if (name === 'NotReadableError' || name === 'TrackStartError' || /Could not start|busy|in use/i.test(msg)) {
    return 'The camera could not be started (often in use or driver issue). Close other apps using the camera, disconnect and reconnect the USB webcam, then try again.';
  }
  if (name === 'OverconstrainedError') {
    return 'This camera does not support the requested settings. Try another camera in the list or update the camera driver.';
  }
  if (name === 'SecurityError' || /secure context/i.test(msg)) {
    return 'Camera requires a secure connection. Use https:// or http://localhost only.';
  }
  return msg || 'Unknown camera error.';
}

/**
 * Open a temporary stream then enumerate. Some browsers only report every videoinput
 * after permission + a stream (otherwise you may only see e.g. OBS Virtual Camera).
 * Prefer `{ video: true }` first so the probe matches the browser / Windows default camera.
 */
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
    try {
      tmp = await getUserMediaVideo(c);
      break;
    } catch (e) {
      lastErr = e;
    }
  }
  if (!tmp) throw new Error(cameraAccessErrorMessage(lastErr));
  tmp.getTracks().forEach((t) => t.stop());
  /* Let the driver release the device before the scanner opens another stream (Windows). */
  await new Promise((r) => setTimeout(r, 280));
  const inputs = (await navigator.mediaDevices.enumerateDevices()).filter((d) => d.kind === 'videoinput');
  if (inputs.length === 0) throw new Error('No camera found.');
  return inputs.map((d, i) => ({
    deviceId: d.deviceId,
    label: d.label?.trim() || `Camera ${i + 1}`,
  }));
}

/* ── BSR (Barcode Scan Receive) helpers ─────────────── */
const bsrFmtDate = (v) => {
  if (!v) return '—';
  const d = new Date(v);
  if (Number.isNaN(d.getTime())) return String(v);
  return d.toLocaleDateString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric' });
};
const bsrFmtCurrency = (n) =>
  `₱${Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 0 })}`;
const bsrIsApproved = (po) =>
  /approv|authorized|active|confirmed|received|partial/.test(
    String(po?.status?.status_name ?? po?.status_name ?? '').toLowerCase(),
  );
const bsrGetSupplierName = (po) =>
  po?.supplier?.supplier_name || po?.supplier?.name || po?.supplier_name || '—';
const bsrGetApprovedBy = (po) => {
  const u = po?.approved_by;
  if (!u) return '—';
  if (typeof u === 'object') return `${u.first_name || ''} ${u.last_name || ''}`.trim() || u.username || u.email || '—';
  return String(u);
};
const bsrGetDeliveryLocation = (po) =>
  po?.location?.location_name || po?.delivery_location || po?.location_name || '—';
const bsrGetItemCount = (po) =>
  Array.isArray(po?.details) ? po.details.length : '—';
const bsrIsSerialized = (line) => line?.product?.product_type === 'appliance';

const BsrBarcodeIcon = ({ size = 26, color = '#9810FA' }) => (
  <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke={color} strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <path d="M3 5v4"/><path d="M3 15v4"/><path d="M7 5v2"/><path d="M7 17v2"/>
    <path d="M7 9v6"/><path d="M11 5v14"/><path d="M15 5v2"/><path d="M15 17v2"/>
    <path d="M15 9v6"/><path d="M19 5v4"/><path d="M19 15v4"/>
  </svg>
);
const BsrSearchIcon = () => (
  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" strokeWidth="2">
    <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
  </svg>
);
const BsrCartIcon = ({ size = 18 }) => (
  <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
    <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
    <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
  </svg>
);
const BsrCheckIcon = () => (
  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
    <polyline points="20 6 9 17 4 12"/>
  </svg>
);

function pickPreferredCameraDeviceId(devices, savedId) {
  if (!devices?.length) return null;
  const saved = devices.find(
    (d) => d.deviceId === savedId && !isLikelyVirtualCameraLabel(d.label),
  );
  if (saved) return saved.deviceId;
  const physical = devices.find((d) => !isLikelyVirtualCameraLabel(d.label));
  if (physical) return physical.deviceId;
  return devices[0].deviceId;
}

const BarcodeScan = () => {
  const navigate = useNavigate();
  const { user: _user, refreshUser, can } = useAuth();
  const { locationId: enforcedLocationId, locationName: enforcedLocationName, locationType: enforcedLocationType, isStrictLocation, allowedLocations } = useUserLocation();
  const roleKey = useMemo(() => getRoleKey(_user || {}), [_user]);
  const isAdminRole = roleKey === ROLES.ADMIN;
  // Barcode inventory processing: admin, branch manager, and warehouse personnel.
  const canScan = isAdminRole || (typeof can === 'function' && can('barcode_inventory_processing', 'create'));
  /** The receiver's own warehouse for PO-receiving line scoping; null (no filtering) for centralized/admin users who are not locked to one location. */
  const myLocationId = useMemo(
    () => (isStrictLocation && enforcedLocationId ? String(enforcedLocationId) : null),
    [isStrictLocation, enforcedLocationId],
  );
  /** Browsers block getUserMedia on plain http:// unless hostname is localhost. */
  const cameraBlockedPlainHttp = useMemo(() => {
    if (typeof window === 'undefined') return false;
    if (window.isSecureContext) return false;
    const host = window.location?.hostname || '';
    return !(
      host === 'localhost' ||
      host === '127.0.0.1' ||
      host === '[::1]' ||
      host === '::1'
    );
  }, []);
  const scannedByName = useMemo(() => {
    const currentUser = _user || {};
    if (currentUser.first_name) {
      return `${currentUser.first_name} ${currentUser.last_name || ''}`.trim();
    }
    return currentUser.username || 'System Administrator';
  }, [_user]);

  /* ── view state ── */
  const [scanMode, setScanMode]     = useState(null); // null = home, or MODES[i]
  const [modeIndex, setModeIndex]   = useState(0);

  /* ── scan state ── */
  const [barcodeInput, setBarcodeInput] = useState('');
  const [scanResult,   setScanResult]   = useState(null);
  const [scanning,     setScanning]     = useState(false);
  const [scanHistory,  setScanHistory]  = useState(() => {
    try {
      const saved = localStorage.getItem('bs_scan_history');
      return saved ? JSON.parse(saved) : [];
    } catch { return []; }
  });

  /* ── camera ── */
  const [cameraActive, setCameraActive] = useState(false);
  const [cameraError,  setCameraError]  = useState('');
  const [lastDecoded, setLastDecoded] = useState('');
  const [cameraDevices, setCameraDevices] = useState([]);
  const [selectedCameraId, setSelectedCameraId] = useState(() => {
    try {
      return localStorage.getItem(CAMERA_STORAGE_KEY) || '';
    } catch {
      return '';
    }
  });

  /* ── filters ── */
  const [categories,     setCategories]     = useState([]);
  const [brands,         setBrands]         = useState([]);
  const [filterCategory, setFilterCategory] = useState('');
  const [filterBrand,    setFilterBrand]    = useState('');
  const [filterModel,    setFilterModel]    = useState('');
  const [activeType,     setActiveType]     = useState('appliance');

  /* ── home page data ── */
  const [recentMovement, setRecentMovement] = useState([]);
  const [locations, setLocations] = useState([]);
  const [purchaseOrders, setPurchaseOrders] = useState([]);
  const [txSubmitting, setTxSubmitting] = useState(false);
  const [txLogs, setTxLogs] = useState([]);
  // Goods Received Note preview for a just-posted receiving session
  const [receiptReceiving, setReceiptReceiving] = useState(null);
  const openReceipt = async (receivingId) => {
    try {
      const res = await receivingsAPI.getById(receivingId);
      setReceiptReceiving(res?.data || null);
    } catch {
      toast.error('Failed to load the receiving receipt');
    }
  };
  const [transferSubmode, setTransferSubmode] = useState('out'); // kept for legacy: no longer used by a selectable mode
  const [txForm, setTxForm] = useState({
    quantity: 1,
    condition: 'Good',
    location_id: '',
    to_location_id: '',
    pc_id: '',
    adjustment_direction: 'deduct',
    ship_transfer_first: true,
    pending_transfer_id: '',
  });

  /** Product IDs allowed for the currently selected PO (Receive PO only). */
  const [receivePoProductIds, setReceivePoProductIds] = useState([]);
  const [receivePoLinesLoading, setReceivePoLinesLoading] = useState(false);

  // BSR dedicated view state
  const [bsrPoList, setBsrPoList] = useState([]);
  const [bsrPoLoading, setBsrPoLoading] = useState(false);
  const [bsrSelectedPo, setBsrSelectedPo] = useState(null);
  const [bsrProductProgress, setBsrProductProgress] = useState({});
  const [bsrPoSearchQ, setBsrPoSearchQ] = useState('');
  const [bsrScannedItems, setBsrScannedItems] = useState([]);
  const [bsrLocationId, setBsrLocationId] = useState('');
  const [bsrScanInput, setBsrScanInput] = useState('');
  const [bsrScanning, setBsrScanning] = useState(false);
  const [bsrActiveLine, setBsrActiveLine] = useState(null);
  const [bsrScanSuccess, setBsrScanSuccess] = useState(null);
  const [bsrAlertModal, setBsrAlertModal] = useState(null);
  /** Set of lowercase-trimmed scanned_barcode values already in the DB for the active PO. */
  const [bsrPoReceivedBarcodes, setBsrPoReceivedBarcodes] = useState(new Set());
  const [bsrPoStatusLoading, setBsrPoStatusLoading] = useState(false);

  // ── IST Transfer Scan state ──────────────────────────────────────────────
  const [istList,          setIstList]          = useState([]);
  const [istLoading,       setIstLoading]       = useState(false);
  const [istSelectedIst,   setIstSelectedIst]   = useState(null);
  const [istProgress,      setIstProgress]      = useState({});  // { [transfer_detail_id]: qty_scanned }
  const [istScannedItems,  setIstScannedItems]  = useState([]);
  const [istScanInput,     setIstScanInput]     = useState('');
  const [istScanning,      setIstScanning]      = useState(false);
  const [istAlertModal,    setIstAlertModal]    = useState(null);
  const [istScanSuccess,   setIstScanSuccess]   = useState(null);
  const [istActiveLine,    setIstActiveLine]    = useState(null);
  const [istConfirmOpen,   setIstConfirmOpen]   = useState(false);
  const [istConfirming,    setIstConfirming]    = useState(false);
  const [istLoadingStatus, setIstLoadingStatus] = useState(false);
  const istInputRef       = useRef(null);
  const istScanTimerRef   = useRef(null);
  const istProcessingRef  = useRef(false);

  const showPricingFields = roleKey === ROLES.ADMIN || roleKey === ROLES.BRANCH_MANAGER;
  const [notFoundPromptOpen, setNotFoundPromptOpen] = useState(false);
  const [pendingRegisterBarcode, setPendingRegisterBarcode] = useState('');
  const [registerProductOpen, setRegisterProductOpen] = useState(false);

  const bsrFilteredPos = useMemo(
    () => bsrPoList.filter((po) => {
      if (!bsrPoSearchQ.trim()) return true;
      const q = bsrPoSearchQ.toLowerCase();
      return (po.po_number || '').toLowerCase().includes(q) || bsrGetSupplierName(po).toLowerCase().includes(q);
    }),
    [bsrPoList, bsrPoSearchQ],
  );

  const cameraSelectValue = useMemo(() => {
    if (!cameraDevices.length) return '';
    return cameraDevices.some((d) => d.deviceId === selectedCameraId)
      ? selectedCameraId
      : cameraDevices[0].deviceId;
  }, [cameraDevices, selectedCameraId]);

  const loadPurchaseOrders = useCallback(() => {
    purchaseOrdersAPI
      .getAll({ per_page: 500 })
      .then((r) => {
        const rows = extractApiDataArray(r);
        setPurchaseOrders(mergeSyncedLocalPurchaseOrders(rows));
      })
      .catch((e) => {
        setPurchaseOrders(mergeSyncedLocalPurchaseOrders([]));
        toast.error(e?.message || 'Could not load purchase orders. Check API URL and network.');
      });
  }, []);

  /** Refetch dashboard “Recent movement” (home + after transactions). */
  const reloadRecentMovement = useCallback(() => {
    const run = async () => {
      try {
        const [recRes, issRes, trRes] = await Promise.allSettled([
          receivingsAPI.getAll({ per_page: 3, sort: 'desc' }),
          issuancesAPI.getAll({ per_page: 3, sort: 'desc' }),
          transfersAPI.getAll({ per_page: 3, sort: 'desc' }),
        ]);
        const all = [];
        const extract = (res, type) => {
          if (res.status === 'fulfilled') {
            const items = Array.isArray(res.value?.data) ? res.value.data : [];
            items.forEach((item) => all.push({ ...item, _type: type }));
          }
        };
        extract(recRes, 'RECEIVE');
        extract(issRes, 'ISSUE');
        extract(trRes, 'TRANSFER');
        all.sort((a, b) => {
          const da = new Date(b.created_at || b.transaction_date || 0);
          const db = new Date(a.created_at || a.transaction_date || 0);
          return da - db;
        });
        setRecentMovement(all.slice(0, 5));
      } catch {
        /* ignore */
      }
    };
    void run();
  }, []);

  /**
   * Pull latest inventory rows + product snapshot from the API so the UI matches the server
   * after a transaction (no full page refresh).
   */
  const mergeFreshInventoryForSession = useCallback(async (productId, locationId, barcode, scannedAt) => {
    if (productId == null || productId === '') return;
    try {
      const [invRes, prodRes] = await Promise.all([
        inventoryAPI.getAll({ product_id: productId, per_page: 500 }),
        productsAPI.getById(productId).catch(() => null),
      ]);
      const invList = Array.isArray(invRes?.data) ? invRes.data : [];
      const latestInventory = pickInventoryForLocation(invList, locationId)
        || pickInventoryForLocation(invList, '');
      const rawProd = prodRes?.data ?? prodRes;
      const itemMerge = rawProd && typeof rawProd === 'object' && rawProd.product_id != null
        ? { ...rawProd, id: rawProd.product_id }
        : null;

      setScanResult((prev) => {
        if (!prev) return prev;
        if (String(prev.item?.product_id ?? prev.item?.id) !== String(productId)) return prev;
        if (barcode != null && scannedAt != null && (prev.barcode !== barcode || prev.scannedAt !== scannedAt)) {
          return prev;
        }
        return {
          ...prev,
          inventory: latestInventory,
          inventoryRows: invList,
          ...(itemMerge ? { item: { ...prev.item, ...itemMerge } } : {}),
        };
      });
      setScanHistory((prev) => {
        const next = prev.map((h) => {
          const sameProduct = String(h.item?.product_id ?? h.item?.id) === String(productId);
          if (!sameProduct) return h;
          if (barcode != null && scannedAt != null && (h.barcode !== barcode || h.scannedAt !== scannedAt)) {
            return h;
          }
          return {
            ...h,
            inventory: latestInventory,
            inventoryRows: invList,
            ...(itemMerge ? { item: { ...h.item, ...itemMerge } } : {}),
          };
        });
        try { localStorage.setItem('bs_scan_history', JSON.stringify(next)); } catch { /* ignore */ }
        return next;
      });
    } catch {
      /* ignore */
    }
  }, []);

  /* ── refs ── */
  const scanResultRef = useRef(null);
  const html5QrRef = useRef(null);
  const inputRef       = useRef(null);
  const lastCodeRef    = useRef('');
  const lastTimeRef    = useRef(0);
  const handleScanRef  = useRef(null);
  const scanningRef    = useRef(false);
  const inputScanTimerRef = useRef(null);
  /** Resets Issue Out quantity to 1 only when preview target (barcode + time) changes */
  const issuancePreviewKeyRef = useRef('');
  const bsrInputRef = useRef(null);
  const bsrScanTimerRef = useRef(null);
  const bsrProcessingRef = useRef(false); // synchronous lock — prevents double-fire
  // True while a PO is selected in BSR mode — used by applyDecodedBarcode to block
  // continuous camera auto-scan; only the manual Scan (circular) button fires scans.
  const bsrModeActiveRef = useRef(false);

  /* ── hydrate location enforcement fields ── */
  useEffect(() => { refreshUser(); }, [refreshUser]);

  /* ── load filter data ── */
  useEffect(() => {
    categoriesAPI.getAll().then((r) => setCategories(Array.isArray(r.data) ? r.data : [])).catch(() => {});
    brandsAPI.getAll().then((r)     => setBrands(Array.isArray(r.data) ? r.data : [])).catch(() => {});
    locationsAPI
      .getAll({ per_page: 500 })
      .then((r) => setLocations(extractApiDataArray(r)))
      .catch((e) => {
        toast.error(e?.message || 'Could not load locations. Check API URL and network.');
      });
  }, []);

  // For strict roles, auto-populate location fields so validations pass without a dropdown
  useEffect(() => {
    if (isStrictLocation && enforcedLocationId) {
      const strId = String(enforcedLocationId);
      setTxForm((p) => p.location_id === strId ? p : { ...p, location_id: strId });
      setBsrLocationId((prev) => prev === strId ? prev : strId);
    }
  }, [isStrictLocation, enforcedLocationId]);

// REMOVED: Storage event listener - no longer needed since we only use database POs now
  useEffect(() => {
    loadPurchaseOrders();
  }, [loadPurchaseOrders]);

  const effectiveModeKey = (scanMode || MODES[modeIndex])?.key;
  useEffect(() => {
    if (effectiveModeKey !== 'receive-po') return;
    loadPurchaseOrders();
  }, [effectiveModeKey, loadPurchaseOrders]);

  useEffect(() => {
    if (effectiveModeKey !== 'receive-po') {
      setBsrSelectedPo(null);
      setBsrScannedItems([]);
      setBsrProductProgress({});
      setBsrPoSearchQ('');
      return;
    }
    setBsrPoLoading(true);
    purchaseOrdersAPI
      .getAll({ per_page: 500, with: 'status,supplier,details.product,approved_by,location' })
      .then((r) => {
        const rows = Array.isArray(r?.data) ? r.data : [];
        setBsrPoList(rows.filter(bsrIsApproved));
      })
      .catch(() => toast.error('Could not load purchase orders.'))
      .finally(() => setBsrPoLoading(false));
  }, [effectiveModeKey]);

  /* Load PO line items so scans can be restricted to products on that PO only. */
  useEffect(() => {
    if (scanMode?.key !== 'receive-po' || !txForm.pc_id) {
      setReceivePoProductIds([]);
      setReceivePoLinesLoading(false);
      return;
    }
    let cancelled = false;
    setReceivePoLinesLoading(true);
    setReceivePoProductIds([]);
    purchaseOrdersAPI
      .getById(txForm.pc_id)
      .then((res) => {
        const po = res?.data;
        const details = Array.isArray(po?.details) ? po.details : [];
        const ids = [
          ...new Set(
            details
              .map((d) => Number(d.product_id))
              .filter((n) => Number.isFinite(n) && n > 0),
          ),
        ];
        if (!cancelled) setReceivePoProductIds(ids);
      })
      .catch(() => {
        if (!cancelled) {
          setReceivePoProductIds([]);
          toast.error('Could not load line items for this PO. Re-select the PO or check your connection.');
        }
      })
      .finally(() => {
        if (!cancelled) setReceivePoLinesLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [scanMode?.key, txForm.pc_id]);

  /* Changing PO clears the current scan so the UI does not show a product from the wrong batch. */
  useEffect(() => {
    if (scanMode?.key !== 'receive-po') return;
    setScanResult(null);
    setBarcodeInput('');
  }, [txForm.pc_id, scanMode?.key]);

  /* Default transfer destination to Carmen Showroom when available */
  useEffect(() => {
    const mode = scanMode || MODES[modeIndex];
    if (!mode || mode.key !== 'transfer') return;
    if (!locations.length || txForm.to_location_id) return;
    const carmenId = findCarmenLocationId(locations);
    if (carmenId) setTxForm((p) => ({ ...p, to_location_id: carmenId }));
  }, [locations, scanMode, modeIndex, txForm.to_location_id]);

  /* ── load recent movement for home page ── */
  useEffect(() => {
    if (scanMode !== null) return;
    reloadRecentMovement();
  }, [scanMode, reloadRecentMovement]);

  /* ── scan handler ── */
  const handleScan = useCallback(async (barcode) => {
    const code = String(barcode || '').replace(/\s+/g, '').trim();
    if (!code) return;
    setBarcodeInput(code);
    setScanning(true);
    try {
      const modeEarly = scanMode || MODES[modeIndex];
      if (modeEarly?.key === 'receive-po') {
        if (!txForm.location_id) {
          toast.error('Select a location before scanning.');
          return;
        }
        if (txForm.pc_id) {
          if (receivePoLinesLoading) {
            toast.error('Loading PO line items — try again in a moment.');
            return;
          }
          if (receivePoProductIds.length === 0) {
            toast.error('This PO has no line items loaded. Re-select the PO or pick another order.');
            return;
          }
        }
        try {
          const lookupRes = await barcodeScanAPI.lookup(code);
          const lookupPayload = lookupRes?.data;
          if (lookupPayload?.status === 'PENDING_CONSUMABLE') {
            playScanFeedback(false);
            toast.warning('This product registration is pending approval');
            return;
          }
          if (lookupPayload?.status === 'NOT_FOUND') {
            playScanFeedback(false);
            toast.warning('Barcode not registered');
            setPendingRegisterBarcode(code);
            setNotFoundPromptOpen(true);
            return;
          }
          if (lookupPayload?.status === 'FOUND' && lookupPayload.product) {
            playScanFeedback(true);
            toast.success('Product found');
            const pid = Number(lookupPayload.product.product_id);
            if (
              txForm.pc_id &&
              receivePoProductIds.length > 0 &&
              Number.isFinite(pid) &&
              !receivePoProductIds.includes(pid)
            ) {
              toast.error('This product is not on the selected Purchase Order.');
              return;
            }
          }
        } catch (lookupErr) {
          toast.error(lookupErr.message || 'Barcode lookup failed.');
          return;
        }
      }

      /* ── Issue Out: scan loads product + stock only; user enters quantity then confirms (deduct on Execute).
          NOT_FOUND here often means a unit serial — post issuance immediately with quantity from the form. ── */
      if (modeEarly?.key === 'issue-out') {
        if (!txForm.location_id) {
          toast.error('Select a location in Issue Out setup before scanning.');
          return;
        }
        const setupQty = Number(txForm.quantity);
        if (!Number.isInteger(setupQty) || setupQty < 1) {
          toast.error('Enter a valid quantity (whole number ≥ 1) in Issue Out setup.');
          return;
        }
        let lookupPayload = null;
        try {
          const lookupRes = await barcodeScanAPI.lookup(code);
          lookupPayload = lookupRes?.data;
        } catch (lookupErr) {
          toast.error(lookupErr.message || 'Barcode lookup failed.');
          return;
        }
        if (lookupPayload?.status === 'PENDING_CONSUMABLE') {
          playScanFeedback(false);
          toast.warning('This product registration is pending approval');
          return;
        }
        if (lookupPayload?.status === 'FOUND' && lookupPayload.product) {
          playScanFeedback(true);
          toast.success('Product found — tap Issue stock out to deduct the setup quantity.');
          const product = lookupPayload.product;
          const pid = Number(product.product_id);
          const invRes = await inventoryAPI.getAll({ product_id: pid, per_page: 500 });
          const inventoryRows = Array.isArray(invRes.data) ? invRes.data : [];
          const inventory = pickInventoryForLocation(inventoryRows, txForm.location_id);
          if (!inventory) {
            playScanFeedback(false);
            toast.error('No inventory record at this location for this product.');
            return;
          }
          if (availableQtyAtInventoryRow(inventory) < 1) {
            playScanFeedback(false);
            toast.error('No stock available at this location.');
            return;
          }
          const item = { ...product, id: product.product_id };
          const mode = scanMode || MODES[modeIndex];
          const scannedAt = new Date().toLocaleString();
          const result = {
            item,
            inventory,
            inventoryRows,
            barcode: code,
            mode: mode.apiLabel,
            modeKey: mode.key,
            scannedAt,
            scannedBy: scannedByName,
            transactionEngineApplied: false,
            auditSnapshot: null,
          };
          setScanResult(result);
          setTxLogs([]);
          setScanHistory((prev) => {
            const next = [result, ...prev.slice(0, 49)];
            try { localStorage.setItem('bs_scan_history', JSON.stringify(next)); } catch {}
            return next;
          });
          setBarcodeInput('');
          setTimeout(() => inputRef.current?.focus(), 50);
          try {
            await barcodeScanAPI.create({
              barcode: code,
              product_id: item.product_id ?? item.id,
              scan_mode: mode.key,
              scanned_at: new Date().toISOString(),
            });
          } catch {}
          return;
        }
        if (lookupPayload?.status === 'NOT_FOUND') {
          const qtyEngine = Math.max(1, Number(txForm.quantity) || 1);
          const engRes = await inventoryAPI.scanTransaction({
            barcode: code,
            transaction_type: 'issuance',
            location_id: Number(txForm.location_id),
            quantity: qtyEngine,
          });
          const eng = engRes?.data;
          if (!eng || typeof eng !== 'object') {
            toast.error('Invalid transaction engine response.');
            return;
          }
          if (eng.action === 'rejected') {
            playScanFeedback(false);
            toast.error(eng.reason || 'Use the product barcode to choose quantity, or check serial/stock.');
            return;
          }
          (Array.isArray(eng.warnings) ? eng.warnings : []).forEach((w) => toast.warning(w));
          const p = eng.product;
          if (!p) {
            toast.error('No product data returned.');
            return;
          }
          playScanFeedback(true);
          toast.success('Issuance posted (serial / alternate code).');
          const item = { ...p, id: p.product_id };
          const inventoryRows = Array.isArray(eng.inventory) ? eng.inventory : [];
          const inventory = pickInventoryForLocation(inventoryRows, txForm.location_id)
            || pickInventoryForLocation(inventoryRows, '');
          const mode = scanMode || MODES[modeIndex];
          const result = {
            item,
            inventory,
            inventoryRows,
            barcode: code,
            mode: mode.apiLabel,
            modeKey: mode.key,
            scannedAt: new Date().toLocaleString(),
            scannedBy: scannedByName,
            transactionEngineApplied: true,
            auditSnapshot: null,
            issuedQuantity: qtyEngine,
          };
          setScanResult(result);
          setTxLogs([]);
          setScanHistory((prev) => {
            const next = [result, ...prev.slice(0, 49)];
            try { localStorage.setItem('bs_scan_history', JSON.stringify(next)); } catch {}
            return next;
          });
          setBarcodeInput('');
          setTimeout(() => inputRef.current?.focus(), 50);
          queueMicrotask(() => {
            void mergeFreshInventoryForSession(item.product_id ?? item.id, txForm.location_id, code, result.scannedAt);
            reloadRecentMovement();
          });
          try {
            await barcodeScanAPI.create({
              barcode: code,
              product_id: item.product_id ?? item.id,
              scan_mode: mode.key,
              scanned_at: new Date().toISOString(),
            });
          } catch {}
          return;
        }
        playScanFeedback(false);
        toast.warning('Barcode not registered');
        setPendingRegisterBarcode(code);
        setNotFoundPromptOpen(true);
        return;
      }

      /* ── Core transaction engine: server validates PO / stock / serials, updates inventory, logs every scan ── */
      const engineTransactionType =
        modeEarly?.key === 'receive-po'
          ? 'receiving'
          : modeEarly?.key === 'audit'
            ? 'audit'
            : null;

      if (engineTransactionType) {
        if (engineTransactionType === 'audit') {
          if (!txForm.location_id) {
            toast.error('Select a location before scanning.');
            return;
          }
        }
        const qtyEngine = Math.max(1, Number(txForm.quantity) || 1);
        const body = {
          barcode: code,
          transaction_type: engineTransactionType,
        };
        if (engineTransactionType === 'receiving') {
          if (txForm.pc_id) {
            body.po_id = Number(txForm.pc_id);
          }
          body.location_id = Number(txForm.location_id);
          body.quantity = qtyEngine;
        }
        if (engineTransactionType === 'audit') {
          body.location_id = Number(txForm.location_id);
        }

        const engRes = await inventoryAPI.scanTransaction(body);
        const eng = engRes?.data;
        if (!eng || typeof eng !== 'object') {
          toast.error('Invalid transaction engine response.');
          return;
        }
        if (eng.action === 'rejected') {
          toast.error(eng.reason || 'Scan rejected.');
          return;
        }
        (Array.isArray(eng.warnings) ? eng.warnings : []).forEach((w) => toast.warning(w));
        const p = eng.product;
        if (!p) {
          toast.error('No product data returned.');
          return;
        }
        const item = { ...p, id: p.product_id };
        const inventoryRows = Array.isArray(eng.inventory) ? eng.inventory : [];
        const modeForPick = scanMode || MODES[modeIndex];
        const locForPick =
          modeForPick?.key === 'transfer' && transferSubmode === 'in'
            ? txForm.to_location_id
            : txForm.location_id;
        const inventory = pickInventoryForLocation(inventoryRows, locForPick)
          || pickInventoryForLocation(inventoryRows, txForm.location_id)
          || pickInventoryForLocation(inventoryRows, '');

        const mode = scanMode || MODES[modeIndex];
        const engineApplied = engineTransactionType !== 'audit';
        const result = {
          item,
          inventory,
          inventoryRows,
          barcode: code,
          mode: mode.apiLabel,
          modeKey: mode.key,
          scannedAt: new Date().toLocaleString(),
          scannedBy: scannedByName,
          transactionEngineApplied: engineApplied,
          auditSnapshot: eng.audit || null,
        };

        setScanResult(result);
        setTxLogs([]);
        setScanHistory((prev) => {
          const next = [result, ...prev.slice(0, 49)];
          try { localStorage.setItem('bs_scan_history', JSON.stringify(next)); } catch {}
          return next;
        });
        setBarcodeInput('');
        setTimeout(() => inputRef.current?.focus(), 50);

        {
          const pidMerge = item.product_id ?? item.id;
          const locMerge = locForPick || txForm.location_id;
          queueMicrotask(() => {
            void mergeFreshInventoryForSession(pidMerge, locMerge, code, result.scannedAt);
            reloadRecentMovement();
            if (engineTransactionType === 'receiving') {
              loadPurchaseOrders();
              window.dispatchEvent(new CustomEvent('inventory-scan-applied', {
                detail: { transaction_type: 'receiving', po_id: body.po_id || null },
              }));
            }
          });
        }

        try {
          await barcodeScanAPI.create({
            barcode: code,
            product_id: item.product_id ?? item.id,
            scan_mode: mode.key,
            scanned_at: new Date().toISOString(),
          });
        } catch {}
        return;
      }

      const toEan13From12 = (v) => {
        if (!/^\d{12}$/.test(v)) return null;
        const sum = v.split('').map(Number).reduce((acc, d, idx) => acc + (idx % 2 === 0 ? d : d * 3), 0);
        const checksum = (10 - (sum % 10)) % 10;
        return `${v}${checksum}`;
      };
      const candidates = Array.from(new Set([
        code,
        code.toUpperCase(),
        code.replace(/[^0-9A-Za-z]/g, ''),
        code.replace(/\D/g, ''),
        toEan13From12(code.replace(/\D/g, '')),
      ].filter(Boolean)));
      const normalizedSet = new Set(candidates.map((v) => String(v).toUpperCase()));
      const exactMatch = (row) => {
        const bc = String(row?.barcode ?? '').trim().toUpperCase();
        const pc = String(row?.product_code ?? '').trim().toUpperCase();
        return normalizedSet.has(bc) || normalizedSet.has(pc);
      };

      let item = null;
      let preloadedInventoryRows = null;

      const locForScan =
        (scanMode || MODES[modeIndex])?.key === 'transfer' && transferSubmode === 'in'
          ? txForm.to_location_id
          : txForm.location_id;

      // 1) Backend exact match — try every normalized variant (API used to see only the raw string).
      for (const cand of candidates) {
        if (item) break;
        try {
          const scanRes = await inventoryAPI.scanBarcode(cand, locForScan || undefined);
          const payload = scanRes?.data;
          if (payload?.product) {
            item = payload.product;
            preloadedInventoryRows = Array.isArray(payload.inventory) ? payload.inventory : [];
          }
        } catch {
          /* try next candidate */
        }
      }

      // 2) Product search fallback — do NOT apply category/type filters; wrong tab was hiding valid items.
      if (!item) {
        const searchQ = candidates[0];
        const strictRes = await productsAPI.getAll({ search: searchQ, per_page: 100 });
        const strictItems = Array.isArray(strictRes.data) ? strictRes.data : [];
        item = strictItems.find((row) => exactMatch(row));

        if (!item) {
          const wideRes = await productsAPI.getAll({ search: searchQ, per_page: 200 });
          const wideItems = Array.isArray(wideRes.data) ? wideRes.data : [];
          item = wideItems.find(exactMatch);
        }

        if (!item) {
          const legacyRes = await itemsAPI.getAll({ search: searchQ, per_page: 200 });
          const legacyItems = Array.isArray(legacyRes.data) ? legacyRes.data : [];
          item = legacyItems.find(exactMatch);
        }
      }

      if (!item) {
        toast.error(`No item found for barcode: ${code}`);
        return;
      }

      const modeAfterItem = scanMode || MODES[modeIndex];
      if (modeAfterItem?.key === 'receive-po') {
        const pid = Number(item.product_id ?? item.id);
        if (!Number.isFinite(pid) || pid <= 0) {
          toast.error('Invalid product on this scan. Try another barcode.');
          return;
        }
        if (!receivePoProductIds.includes(pid)) {
          toast.error(
            'This product is not on the selected Purchase Order. Only items listed on that PO can be scanned for receiving.',
          );
          return;
        }
      }

      let inventoryRows = [];
      let inventory = null;
      try {
        if (preloadedInventoryRows && preloadedInventoryRows.length) {
          inventoryRows = preloadedInventoryRows;
        } else {
          const invRes = await inventoryAPI.getAll({ product_id: item.product_id ?? item.id, per_page: 500 });
          inventoryRows = Array.isArray(invRes.data) ? invRes.data : [];
        }
        const modeForPick = scanMode || MODES[modeIndex];
        const locForPick =
          modeForPick?.key === 'transfer' && transferSubmode === 'in'
            ? txForm.to_location_id
            : txForm.location_id;
        inventory = pickInventoryForLocation(inventoryRows, locForPick)
          || pickInventoryForLocation(inventoryRows, txForm.location_id)
          || pickInventoryForLocation(inventoryRows, '');
      } catch {}

      const mode   = scanMode || MODES[modeIndex];
      const result = {
        item,
        inventory,
        inventoryRows,
        barcode:   code,
        mode:      mode.apiLabel,
        modeKey:   mode.key,
        scannedAt: new Date().toLocaleString(),
        scannedBy: scannedByName,
      };

      setScanResult(result);
      setTxLogs([]);
      setScanHistory((prev) => {
        const next = [result, ...prev.slice(0, 49)];
        try { localStorage.setItem('bs_scan_history', JSON.stringify(next)); } catch {}
        return next;
      });
      setBarcodeInput('');
      setTimeout(() => inputRef.current?.focus(), 50);

      /* ── save to DB (graceful fallback) ── */
      try {
        await barcodeScanAPI.create({
          barcode:    code,
          product_id: item.product_id ?? item.id,
          scan_mode:  mode.key,
          scanned_at: new Date().toISOString(),
        });
      } catch {}

    } catch (err) {
      toast.error(err.message || 'Scan failed');
    } finally {
      setScanning(false);
    }
  }, [
    modeIndex,
    scanMode,
    scannedByName,
    txForm.location_id,
    txForm.to_location_id,
    txForm.pc_id,
    txForm.quantity,
    transferSubmode,
    receivePoProductIds,
    receivePoLinesLoading,
    mergeFreshInventoryForSession,
    reloadRecentMovement,
    loadPurchaseOrders,
  ]);

  useEffect(() => { scanningRef.current = scanning; }, [scanning]);

  useEffect(
    () => () => {
      if (inputScanTimerRef.current) clearTimeout(inputScanTimerRef.current);
    },
    [],
  );

  const stopReaderAndTracks = useCallback(async () => {
    const h = html5QrRef.current;
    html5QrRef.current = null;
    if (h) {
      try {
        await h.stop();
      } catch {
        /* not running */
      }
      try {
        h.clear();
      } catch {
        /* ignore */
      }
    }
  }, []);

  const applyDecodedBarcode = useCallback((decodedText) => {
    if (scanningRef.current) return;
    // In BSR mode the circular Scan button is the only trigger — block auto-scan.
    if (bsrModeActiveRef.current) return;
    const code = String(decodedText || '').replace(/\s+/g, '').trim();
    if (!code) return;
    const now = Date.now();
    if (code === lastCodeRef.current && now - lastTimeRef.current < CAMERA_SAME_CODE_MS) return;
    lastCodeRef.current = code;
    lastTimeRef.current = now;
    setLastDecoded(code);
    setBarcodeInput(code);
    handleScanRef.current?.(code);
  }, []);

  const startHtml5OnDevice = useCallback(
    async (deviceId) => {
      await stopReaderAndTracks();
      await new Promise((r) => setTimeout(r, 80));
      const mount = typeof document !== 'undefined' ? document.getElementById(HTML5_QR_MOUNT_ID) : null;
      if (!mount) throw new Error('Scanner mount not found.');
      const h5 = new Html5Qrcode(HTML5_QR_MOUNT_ID, {
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
    },
    [applyDecodedBarcode, stopReaderAndTracks],
  );

  /* ── camera (html5-qrcode: QR + common 1D/2D formats, works well on mobile browsers) ── */
  const startCamera = async () => {
    setCameraError('');
    if (typeof document === 'undefined' || !document.getElementById(HTML5_QR_MOUNT_ID)) {
      setCameraError('Scanner not ready. Close and re-open this screen, then try again.');
      return;
    }

    try {
      const savedId = localStorage.getItem(CAMERA_STORAGE_KEY) || '';
      if (savedId) {
        const alreadyKnown = cameraDevices.find((d) => d.deviceId === savedId);
        if (alreadyKnown && isLikelyVirtualCameraLabel(alreadyKnown.label)) {
          localStorage.removeItem(CAMERA_STORAGE_KEY);
          setSelectedCameraId('');
        }
      }
    } catch {
      /* ignore */
    }

    await stopReaderAndTracks();
    setCameraActive(true);
    await new Promise((r) => setTimeout(r, 100));

    try {
      const allDevices = await enumerateVideoInputsWithLabels();
      const devices = sortVideoDevicesPhysicalFirst(allDevices);
      setCameraDevices(devices);

      const physicalOnly = allDevices.filter((d) => !isLikelyVirtualCameraLabel(d.label));
      const virtualOnly = allDevices.filter((d) => isLikelyVirtualCameraLabel(d.label));
      const selectionPool =
        physicalOnly.length > 0
          ? sortPhysicalCamerasDefaultFirst(physicalOnly)
          : sortVideoDevicesPhysicalFirst(allDevices);

      const resolvedId = pickPreferredCameraDeviceId(selectionPool, selectedCameraId);
      if (!resolvedId) throw new Error('No usable camera device.');

      setSelectedCameraId(resolvedId);
      try {
        localStorage.setItem(CAMERA_STORAGE_KEY, resolvedId);
      } catch {
        /* ignore */
      }

      const physicalSorted =
        physicalOnly.length > 0 ? sortPhysicalCamerasDefaultFirst(physicalOnly) : [];
      const tryOrder =
        physicalOnly.length > 0
          ? [
              resolvedId,
              ...physicalSorted.map((d) => d.deviceId).filter((id) => id !== resolvedId),
              ...virtualOnly.map((d) => d.deviceId),
            ]
          : [
              resolvedId,
              ...allDevices.map((d) => d.deviceId).filter((id) => id !== resolvedId),
            ];
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
            const retryable =
              attempt === 0 &&
              (err?.name === 'NotReadableError' || err?.name === 'TrackStartError');
            if (!retryable) break;
          }
        }
        if (opened) break;
      }
      if (!opened) throw lastErr || new Error('Could not open any camera.');
    } catch (e) {
      await stopReaderAndTracks();
      setCameraActive(false);
      setCameraError(
        `${cameraAccessErrorMessage(e)} ${
          isAdminRole
            ? 'You can still type or use a USB barcode wedge in the field below.'
            : 'Use the camera to scan, or ask a System Administrator if you need manual entry.'
        }`,
      );
    }
  };

  const onCameraDeviceChange = async (e) => {
    const id = e.target.value;
    setSelectedCameraId(id);
    try {
      localStorage.setItem(CAMERA_STORAGE_KEY, id);
    } catch {
      /* ignore */
    }
    if (!cameraActive) return;
    try {
      await startHtml5OnDevice(id);
    } catch (err) {
      setCameraError(`Could not switch camera: ${cameraAccessErrorMessage(err)}`);
      await stopReaderAndTracks();
      setCameraActive(false);
    }
  };

  const stopCamera = async () => {
    await stopReaderAndTracks();
    setCameraActive(false);
  };

  /** Like a phone “shutter”: decode the current frame once (helps when auto-scan misses). */
  const manualFrameScan = useCallback(async () => {
    const mountEl = typeof document !== 'undefined' ? document.getElementById(HTML5_QR_MOUNT_ID) : null;
    const v = mountEl?.querySelector('video');
    if (!cameraActive || !v || v.readyState < 2 || v.videoWidth < 48) {
      toast.info('Start the camera and wait until you see a live preview.');
      return;
    }
    // In BSR mode block the circular button while a scan or modal is already active.
    if (bsrModeActiveRef.current && bsrProcessingRef.current) return;
    if (scanningRef.current) {
      toast.info('Still processing the previous scan — try again in a moment.');
      return;
    }
    let code = null;
    if (hasNativeBarcodeDetector()) {
      try {
        const detector = new window.BarcodeDetector({ formats: NATIVE_BARCODE_FORMATS });
        const codes = await detector.detect(v);
        const raw = codes?.[0]?.rawValue;
        if (raw) code = String(raw).replace(/\s+/g, '').trim();
      } catch {
        /* ignore */
      }
    }
    if (!code) code = tryDecodeVideoCenterCropZxing(v);
    if (!code || code.length < 2) {
      toast.warning('No barcode found. Center the code in the frame, add light, and tap Scan again.');
      return;
    }
    const now = Date.now();
    lastCodeRef.current = code;
    lastTimeRef.current = now;
    setLastDecoded(code);
    setBarcodeInput(code);
    handleScanRef.current?.(code);
  }, [cameraActive]);

  useEffect(() => {
    if (!scanResult) return;
    const t = requestAnimationFrame(() => {
      scanResultRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
    return () => cancelAnimationFrame(t);
  }, [scanResult]);

  useEffect(() => () => {
    const h = html5QrRef.current;
    html5QrRef.current = null;
    if (h) {
      h.stop().catch(() => {});
    }
  }, []);

  /* ── select a mode and enter scan view ── */
  const selectMode = (mode, idx) => {
    if (mode.key === 'adjust') {
      navigate('/admin/barcode-adjustment');
      return;
    }
    if (mode.key === 'issue-out') {
      navigate('/admin/issuance-scan');
      return;
    }
    setScanMode(mode);
    setModeIndex(idx);
    setScanResult(null);
    setBarcodeInput('');
    setTimeout(() => inputRef.current?.focus(), 100);
  };

  const exitScanMode = () => {
    void stopCamera();
    setCameraDevices([]);
    setScanMode(null);
    setScanResult(null);
    setBarcodeInput('');
    setBsrSelectedPo(null);
    setBsrScannedItems([]);
    setBsrProductProgress({});
    setBsrPoSearchQ('');
    setBsrScanInput('');
    setBsrActiveLine(null);
    setIstSelectedIst(null);
    setIstScannedItems([]);
    setIstProgress({});
    setIstScanInput('');
    setIstActiveLine(null);
    setIstAlertModal(null);
    setIstScanSuccess(null);
    istProcessingRef.current = false;
  };

  const handleBsrSelectPo = async (po) => {
    const poId = po?.po_id ?? po?.id;

    // Reset all scan state immediately so the UI is responsive before the DB call.
    setBsrSelectedPo(po);
    setBsrScannedItems([]);
    setBsrActiveLine(null);
    setBsrScanInput('');
    setBsrPoReceivedBarcodes(new Set());
    // Explicitly clear the camera same-code debounce buffer on PO transition
    // so the camera cannot re-fire the previous PO's last scanned code.
    lastCodeRef.current = '';
    lastTimeRef.current = 0;

    // Only the lines destined for this receiver's own warehouse are receivable here.
    const receivableLines = bsrLinesForLocation(po, myLocationId);
    const receivableDetailIds = new Set(receivableLines.map((d) => d.po_detail_id).filter(Boolean));

    const prog = {};
    receivableLines.forEach((d) => {
      if (d.po_detail_id) prog[d.po_detail_id] = 0;
    });
    setBsrProductProgress(prog);

    // Load existing receiving data for this PO from the database so that:
    // 1. Progress bars start at actual received quantities, not zero.
    // 2. Previously received barcodes/serials are known before the first scan.
    // 3. The scanned-items list is restored so the session appears seamlessly resumed.
    if (poId) {
      setBsrPoStatusLoading(true);
      try {
        const res = await receivingsAPI.poScanStatus(poId);
        const lines = Array.isArray(res?.data?.lines) ? res.data.lines : [];
        const history = Array.isArray(res?.data?.scan_history) ? res.data.scan_history : [];

        const receivedBarcodes = new Set();
        const updatedProg = { ...prog };
        lines.forEach((line) => {
          if (line.po_detail_id != null && line.already_received > 0) {
            updatedProg[line.po_detail_id] = line.already_received;
          }
          (line.scanned_barcodes || []).forEach((bc) => {
            if (bc) receivedBarcodes.add(bc.trim().toLowerCase());
          });
        });
        setBsrProductProgress(updatedProg);
        setBsrPoReceivedBarcodes(receivedBarcodes);

        // Restore the scanned-items list from the DB history (newest first), scoped to this
        // receiver's own warehouse lines only.
        const restoredItems = history
          .slice()
          .reverse()
          .filter((entry) => !myLocationId || receivableDetailIds.has(entry.po_detail_id))
          .map((entry, idx) => ({
            id: entry.receiving_detail_id ?? Date.now() + idx,
            barcode: entry.scanned_barcode ?? '',
            productName: entry.product_name ?? 'Unknown',
            serial: entry.is_serialized ? (entry.scanned_barcode ?? null) : null,
            packages: entry.is_serialized ? null : 1,
            qty: entry.is_serialized ? null : entry.quantity_amount,
            productId: entry.product_id,
            poDetailId: entry.po_detail_id,
            at: entry.created_at ? new Date(entry.created_at).toLocaleTimeString() : '–',
          }));
        setBsrScannedItems(restoredItems);
      } catch {
        // Non-fatal: progress falls back to zeros; the backend still guards duplicates.
      } finally {
        setBsrPoStatusLoading(false);
      }
    }

    setTimeout(() => bsrInputRef.current?.focus(), 100);
  };

  // Clear the scan input buffer and cancel any pending debounce whenever the
  // active line changes. Prevents a serial number typed for one product from
  // leaking into the next product's scan when the user switches lines.
  useEffect(() => {
    setBsrScanInput('');
    if (bsrScanTimerRef.current) {
      clearTimeout(bsrScanTimerRef.current);
      bsrScanTimerRef.current = null;
    }
    // Reset the camera's same-code debounce so the old code doesn't re-fire
    // against the new product context if the camera is still aimed at it.
    lastCodeRef.current = '';
    lastTimeRef.current = 0;
  }, [bsrActiveLine?.po_detail_id]);

  const handleBsrScan = useCallback(async (raw) => {
    // Synchronous ref lock — blocks any second invocation that arrives before
    // the first one's React state update (bsrScanning) has propagated.
    if (bsrProcessingRef.current) return;
    if (!raw.trim()) return;
    if (!bsrLocationId) {
      bsrProcessingRef.current = true;
      setBsrAlertModal({ type: 'error', title: 'Location Required', message: 'Select a receiving location first.' });
      setBsrScanInput('');
      return;
    }
    if (!bsrActiveLine) {
      bsrProcessingRef.current = true;
      setBsrAlertModal({ type: 'error', title: 'No Item Selected', message: 'Select an item from the left panel first.' });
      setBsrScanInput('');
      return;
    }
    // Cancel any pending debounce so the timer can't fire a second time.
    if (bsrScanTimerRef.current) { clearTimeout(bsrScanTimerRef.current); bsrScanTimerRef.current = null; }

    const isSerialized = bsrIsSerialized(bsrActiveLine);
    // Pre-compute here so they are available for both pre-checks and the scan body.
    const pcs = Number(bsrActiveLine.product?.pieces_per_package) || 1;
    const pkgs = 1;

    // ── Duplicate / already-received pre-checks (all run before any API call) ──

    const normalizedCode = raw.trim().toLowerCase();

    // Global barcode uniqueness pre-check — applies to every product type.
    // Every barcode is a one-time-use identifier; the backend enforces this at the DB level,
    // but these checks give instant modal feedback before the round-trip.

    // 1. Check the current scan session.
    const inSession = bsrScannedItems.some(
      (item) => item.barcode && item.barcode.trim().toLowerCase() === normalizedCode,
    );
    if (inSession) {
      bsrProcessingRef.current = true;
      setBsrAlertModal({
        type: 'error',
        title: 'Barcode Already Scanned',
        message: `Barcode "${raw.trim()}" was already scanned in this session. Each barcode is a one-time-use identifier — check the label.`,
      });
      setBsrScanInput('');
      return;
    }

    // 2. Check DB-loaded received barcodes for the current PO.
    if (bsrPoReceivedBarcodes.has(normalizedCode)) {
      bsrProcessingRef.current = true;
      setBsrAlertModal({
        type: 'error',
        title: 'Barcode Already Received',
        message: `Barcode "${raw.trim()}" has already been recorded as received. Each barcode is a one-time-use identifier — check the label.`,
      });
      setBsrScanInput('');
      return;
    }

    // 2. Pre-check: is this PO line already fully received according to local progress?
    //    This catches both DB-initialised progress and within-session progress,
    //    avoiding a full round-trip just to get a rejection back from the server.
    {
      const ordered    = Number(bsrActiveLine.quantity_ordered || 0);
      const orderedPcs = isSerialized ? ordered : ordered * pcs;
      const prevReceived = bsrProductProgress[bsrActiveLine.po_detail_id] || 0;
      if (orderedPcs > 0 && prevReceived >= orderedPcs) {
        bsrProcessingRef.current = true;
        const label = isSerialized ? `${ordered} unit(s)` : `${ordered} package(s) (${orderedPcs} pcs)`;
        setBsrAlertModal({
          type: 'error',
          title: 'Already Fully Received',
          message: `All ${label} for this line item have already been received.`,
        });
        setBsrScanInput('');
        return;
      }
    }

    bsrProcessingRef.current = true; // lock — set before any await
    setBsrScanInput('');
    setBsrScanning(true);
    try {
      const res = await inventoryAPI.scanTransaction({
        barcode: raw.trim(),
        transaction_type: 'receiving',
        location_id: bsrLocationId,
        po_id: bsrSelectedPo?.po_id ?? bsrSelectedPo?.id,
        po_detail_id: bsrActiveLine.po_detail_id,
        quantity: pkgs,
      });
      const data = res?.data ?? res;
      const action = data?.action ?? data?.status;
      if (action === 'rejected') {
        // Match both "already received" and "already been recorded as received / Duplicate prevented"
        const isAlreadyReceived = /already.*received|duplicate.*prevented/i.test(data?.reason || '');
        setBsrAlertModal({
          type: 'error',
          title: isAlreadyReceived ? 'Barcode Already Received' : 'Scan Rejected',
          message: data?.reason || 'Scan rejected.',
        });
        return;
      }
      const qtyReceived       = data?.quantity_received ?? (isSerialized ? 1 : pkgs * pcs);
      const packageUnitLabel  = data?.packages_unit_label ?? 'pkg';
      const remainingPackages = data?.remaining_packages ?? null;
      const productName = bsrActiveLine.product?.product_name || bsrActiveLine.product?.name || 'Unknown Product';
      const productId   = bsrActiveLine.product_id || bsrActiveLine.product?.product_id;
      const poDetailId  = bsrActiveLine.po_detail_id;
      const ordered     = Number(bsrActiveLine.quantity_ordered || 0);
      // quantity_ordered is in packages for consumables; convert to pieces for progress tracking
      const orderedPcs  = isSerialized ? ordered : ordered * pcs;
      const prevReceived = bsrProductProgress[poDetailId] || 0;
      const newReceived  = prevReceived + qtyReceived;

      setBsrScannedItems((prev) => [
        {
          id: Date.now(), barcode: raw.trim(), productName,
          serial: isSerialized ? raw.trim() : null,
          packages: isSerialized ? null : pkgs,
          qty: isSerialized ? null : qtyReceived,
          productId, poDetailId, at: new Date().toLocaleTimeString(),
        },
        ...prev,
      ]);
      setBsrProductProgress((prev) => ({ ...prev, [poDetailId]: newReceived }));
      // Keep the local received-barcodes set in sync so subsequent scans of the
      // same barcode in this session are caught by the pre-check above.
      setBsrPoReceivedBarcodes((prev) => new Set([...prev, normalizedCode]));

      // Immediately reflect the updated PO status (Partially Received / Completed)
      // in both the BSR list and the selected PO, using the value the server just
      // persisted — no second network request needed.
      if (data?.po_status) {
        const activePo = bsrSelectedPo;
        const activePoId = activePo?.po_id ?? activePo?.id;
        setBsrPoList((prev) => prev.map((po) =>
          (po.po_id ?? po.id) === activePoId
            ? { ...po, status: { ...(po.status || {}), status_name: data.po_status } }
            : po,
        ));
        setBsrSelectedPo((prev) => prev
          ? { ...prev, status: { ...(prev.status || {}), status_name: data.po_status } }
          : prev,
        );
      }

      // Broadcast to ApprovalQueue and InventoryManagement so they re-fetch instantly.
      window.dispatchEvent(new CustomEvent('inventory-scan-applied', {
        detail: {
          transaction_type: 'receiving',
          po_id: bsrSelectedPo?.po_id ?? bsrSelectedPo?.id,
          product_id: productId,
        },
      }));

      const lineComplete = orderedPcs > 0 && newReceived >= orderedPcs;
      let nextLine = null;
      if (lineComplete) {
        const updatedProgress = { ...bsrProductProgress, [poDetailId]: newReceived };
        nextLine = bsrLinesForLocation(bsrSelectedPo, myLocationId).find((d) => {
          if (d.po_detail_id === poDetailId) return false;
          const dOrdered = Number(d.quantity_ordered || 0);
          const dPps = Number(d.product?.pieces_per_package) || 1;
          const dIsSerialized = d.product?.product_type === 'appliance';
          const dOrderedPcs = dIsSerialized ? dOrdered : dOrdered * dPps;
          return dOrderedPcs > 0 && (updatedProgress[d.po_detail_id] || 0) < dOrderedPcs;
        }) || null;
      }

      // Show success modal — scanning is blocked until user clicks Done.
      setBsrScanSuccess({
        productName,
        barcode: raw.trim(),
        isSerialized,
        pkgs,
        pcs,
        qtyReceived,
        newReceived,
        ordered,
        orderedPcs,
        lineComplete,
        nextLine,
        packageUnitLabel,
        remainingPackages,
      });
    } catch (e) {
      setBsrAlertModal({ type: 'error', title: 'Scan Failed', message: e?.message || 'Scan failed.' });
    } finally {
      setBsrScanning(false);
      // Do NOT release bsrProcessingRef here — it stays locked until the modal
      // is dismissed, so no duplicate scan can slip through.
    }
  }, [bsrLocationId, bsrSelectedPo, bsrActiveLine, bsrProductProgress, bsrScannedItems, bsrPoReceivedBarcodes, myLocationId]);

  const handleBsrScanSuccessDone = useCallback(() => {
    const result = bsrScanSuccess;
    setBsrScanSuccess(null);
    bsrProcessingRef.current = false; // release lock — ready for next scan
    if (result?.lineComplete) {
      if (result.nextLine) {
        setBsrActiveLine(result.nextLine);
        setTimeout(() => bsrInputRef.current?.focus(), 80);
      } else {
        setBsrActiveLine(null);
        setBsrAlertModal({ type: 'success', title: 'All Items Received!', message: 'All items on this PO are fully received!' });
      }
    } else {
      setTimeout(() => bsrInputRef.current?.focus(), 80);
    }
  }, [bsrScanSuccess]);

  const handleBsrAlertDismiss = useCallback(() => {
    setBsrAlertModal((current) => {
      if (current?.onDismiss) current.onDismiss();
      return null;
    });
    bsrProcessingRef.current = false;
    setTimeout(() => bsrInputRef.current?.focus(), 80);
  }, []);

  const handleBsrInputChange = (e) => {
    setBsrScanInput(e.target.value);
  };

  const handleBsrCaptureClick = () => {
    if (bsrScanTimerRef.current) { clearTimeout(bsrScanTimerRef.current); bsrScanTimerRef.current = null; }
    const raw = bsrScanInput.trim();
    if (raw.length >= 4) handleBsrScan(raw);
  };

  const handleBsrKeyDown = (e) => {
    if (e.key === 'Enter' || e.key === 'Tab') {
      if (bsrScanTimerRef.current) clearTimeout(bsrScanTimerRef.current);
      const raw = bsrScanInput.trim();
      if (raw.length >= 4) handleBsrScan(raw);
    }
  };

  // ── IST Transfer Scan handlers ────────────────────────────────────────────

  const handleIstSelectIst = useCallback(async (ist) => {
    setIstSelectedIst(ist);
    setIstScannedItems([]);
    setIstActiveLine(null);
    setIstScanInput('');
    setIstAlertModal(null);
    setIstScanSuccess(null);
    istProcessingRef.current = false;
    lastCodeRef.current = '';
    lastTimeRef.current = 0;

    const prog = {};
    (ist.details || []).forEach((d) => {
      prog[d.transfer_detail_id] = d.quantity_scanned || 0;
    });
    setIstProgress(prog);

    setIstLoadingStatus(true);
    try {
      const res = await istAPI.scanStatus(ist.transfer_id);
      const details  = Array.isArray(res?.data?.details)      ? res.data.details      : [];
      const history  = Array.isArray(res?.data?.scan_history) ? res.data.scan_history : [];

      const updatedProg = {};
      details.forEach((d) => { updatedProg[d.transfer_detail_id] = d.quantity_scanned || 0; });
      setIstProgress(updatedProg);

      const restored = history.map((scan) => {
        const matchDetail = details.find((d) => d.product_id === scan.product_id);
        return {
          id:        scan.id,
          scanId:    scan.id,
          barcode:   scan.barcode || '',
          productName: scan.product?.product_name || 'Unknown',
          productId: scan.product_id,
          detailId:  matchDetail?.transfer_detail_id,
          at:        scan.scanned_at ? new Date(scan.scanned_at).toLocaleTimeString() : '—',
        };
      });
      setIstScannedItems(restored);
    } catch { /* keep empty */ }
    finally { setIstLoadingStatus(false); }

    setTimeout(() => istInputRef.current?.focus(), 100);
  }, []);

  const handleIstScan = useCallback(async (raw) => {
    if (istProcessingRef.current) return;
    if (!raw.trim()) return;
    if (!istActiveLine) {
      istProcessingRef.current = true;
      setIstAlertModal({ type: 'error', title: 'No Item Selected', message: 'Select an item from the left panel first.' });
      setIstScanInput('');
      return;
    }
    if (istScanTimerRef.current) { clearTimeout(istScanTimerRef.current); istScanTimerRef.current = null; }

    // Session duplicate guard
    const normalizedCode = raw.trim().toLowerCase();
    const inSession = istScannedItems.some((i) => i.barcode.trim().toLowerCase() === normalizedCode);
    if (inSession) {
      istProcessingRef.current = true;
      setIstAlertModal({ type: 'error', title: 'Already Scanned', message: `Barcode "${raw.trim()}" was already scanned in this session.` });
      setIstScanInput('');
      return;
    }

    // Over-scan pre-check
    const detailId  = istActiveLine.transfer_detail_id;
    const already   = istProgress[detailId] || 0;
    const requested = parseInt(istActiveLine.quantity_requested) || 0;
    if (already >= requested) {
      istProcessingRef.current = true;
      setIstAlertModal({ type: 'error', title: 'Already Fully Scanned', message: `All ${requested} unit(s) for this item have already been scanned.` });
      setIstScanInput('');
      return;
    }

    istProcessingRef.current = true;
    setIstScanInput('');
    setIstScanning(true);
    try {
      const res  = await istAPI.scan(istSelectedIst.transfer_id, {
        barcode:            raw.trim(),
        transfer_detail_id: detailId,
        quantity:           1,
      });
      const data = res?.data ?? res;

      const totalScanned  = data?.total_scanned    ?? (already + 1);
      const quantityReq   = data?.quantity_requested ?? requested;
      const remaining     = data?.remaining          ?? (quantityReq - totalScanned);
      const lineComplete  = totalScanned >= quantityReq;
      const istFull       = !!data?.ist_fully_scanned;

      setIstProgress((prev) => ({ ...prev, [detailId]: totalScanned }));
      setIstScannedItems((prev) => [{
        id:          data?.scan_id ?? Date.now(),
        scanId:      data?.scan_id ?? null,
        barcode:     raw.trim(),
        productName: istActiveLine.product?.product_name || data?.product_name || 'Unknown',
        productId:   istActiveLine.product_id,
        detailId,
        at:          new Date().toLocaleTimeString(),
      }, ...prev]);

      setIstScanSuccess({
        productName:  istActiveLine.product?.product_name || data?.product_name || 'Unknown',
        barcode:      raw.trim(),
        totalScanned,
        quantityReq,
        remaining,
        lineComplete,
        istFull,
      });
    } catch (e) {
      setIstAlertModal({ type: 'error', title: 'Scan Failed', message: e?.message || 'Scan failed.' });
    } finally {
      setIstScanning(false);
    }
  }, [istActiveLine, istSelectedIst, istScannedItems, istProgress]);

  const handleIstScanSuccessDone = useCallback(() => {
    const result = istScanSuccess;
    setIstScanSuccess(null);
    istProcessingRef.current = false;
    if (result?.lineComplete && !result?.istFull) {
      // Auto-advance to next incomplete line
      const details = istSelectedIst?.details || [];
      const nextLine = details.find((d) => {
        if (d.transfer_detail_id === istActiveLine?.transfer_detail_id) return false;
        const prog = istProgress[d.transfer_detail_id] || 0;
        return prog < (parseInt(d.quantity_requested) || 0);
      });
      if (nextLine) setIstActiveLine(nextLine);
    }
    setTimeout(() => istInputRef.current?.focus(), 80);
  }, [istScanSuccess, istSelectedIst, istActiveLine, istProgress]);

  const handleIstAlertDismiss = useCallback(() => {
    setIstAlertModal(null);
    istProcessingRef.current = false;
    setTimeout(() => istInputRef.current?.focus(), 80);
  }, []);

  const handleIstRemoveScan = useCallback(async (item) => {
    if (!item.scanId || !istSelectedIst) return;
    try {
      await istAPI.removeScan(istSelectedIst.transfer_id, item.scanId);
      setIstScannedItems((prev) => prev.filter((i) => i.id !== item.id));
      setIstProgress((prev) => ({
        ...prev,
        [item.detailId]: Math.max(0, (prev[item.detailId] || 1) - 1),
      }));
    } catch (e) {
      setIstAlertModal({ type: 'error', title: 'Remove Failed', message: e?.message || 'Could not remove scan.' });
    }
  }, [istSelectedIst]);

  const handleIstConfirmDispatch = useCallback(async () => {
    if (!istSelectedIst) return;
    setIstConfirming(true);
    try {
      const res = await istAPI.confirmDispatch(istSelectedIst.transfer_id);
      const num = res?.data?.transfer_number || istSelectedIst.transfer_number;
      toast.success(`${num} is now In Transit.`);
      setIstConfirmOpen(false);
      // Reset back to IST list and reload
      setIstSelectedIst(null);
      setIstScannedItems([]);
      setIstProgress({});
      setIstActiveLine(null);
      istProcessingRef.current = false;
      setIstLoading(true);
      istAPI.getAll({ per_page: 100 })
        .then((r) => {
          const all     = Array.isArray(r?.data) ? r.data : [];
          const locId   = String(enforcedLocationId || '');
          const filtered = isAdminRole
            ? all.filter(t => t.status?.status_name === 'Approved')
            : all.filter(t =>
                t.status?.status_name === 'Approved' &&
                (!locId || String(t.from_location_id) === locId)
              );
          setIstList(filtered);
        })
        .catch(() => {})
        .finally(() => setIstLoading(false));
    } catch (e) {
      setIstAlertModal({ type: 'error', title: 'Dispatch Failed', message: e?.message || 'Failed to confirm dispatch.' });
      setIstConfirmOpen(false);
    } finally {
      setIstConfirming(false);
    }
  }, [istSelectedIst, enforcedLocationId, isAdminRole]);

  useEffect(() => {
    handleScanRef.current = (scanMode?.key === 'receive-po' && bsrSelectedPo) ? handleBsrScan : handleScan;
  }, [handleScan, handleBsrScan, scanMode?.key, bsrSelectedPo]);

  useEffect(() => {
    bsrModeActiveRef.current = (scanMode?.key === 'receive-po' && !!bsrSelectedPo);
  }, [scanMode?.key, bsrSelectedPo]);

  /* ── helpers ── */
  const qty = (inv) => {
    if (!inv) return null;
    if (inv.quantity_on_hand != null && inv.quantity_on_hand !== '') return Number(inv.quantity_on_hand);
    if (inv.available_quantity != null && inv.available_quantity !== '') return Number(inv.available_quantity);
    return inv?.quantity ?? inv?.qty ?? null;
  };

  /** Sum on-hand across inventory rows (Item Master / Product Management basis). */
  const sumInventoryOnHand = (rows) => {
    if (!Array.isArray(rows) || rows.length === 0) return null;
    let sum = 0;
    for (const r of rows) {
      const q = r?.quantity_on_hand;
      if (q != null && q !== '') sum += Number(q);
    }
    return Number.isFinite(sum) ? sum : null;
  };

  const availableAtLocation = availableQtyAtInventoryRow;
  const price  = (item) => Number(item?.unit_price ?? item?.price ?? 0);
  const branch = (inv) =>
    inv?.location?.branch?.name
    ?? inv?.location?.branch?.branch_name
    ?? inv?.branch?.name
    ?? inv?.branch?.branch_name
    ?? inv?.branch_name
    ?? null;
  const warehouse = (inv) => inv?.warehouse?.warehouse_name ?? inv?.location?.location_name ?? inv?.location?.name ?? inv?.location_name ?? null;
  /** Storage / site name (API nests under location; shelf codes are optional). */
  const locCode = (inv) =>
    inv?.location?.location_name
    ?? inv?.location?.name
    ?? inv?.location_code
    ?? inv?.shelf_code
    ?? inv?.aisle
    ?? null;
  const stockSt   = (inv) => inv?.status?.status_name ?? inv?.stock_status ?? null;
  const isLow     = (inv) => (stockSt(inv) || '').toLowerCase().includes('low');

  /**
   * Status chip for scan result / history: Issue Out is always a stock-out transaction.
   * "In Stock" (and inventory row status) applies to receiving, audit, transfer context, etc.
   */
  const getStatusChipDisplay = (modeKey, inv, transactionEngineApplied) => {
    if (modeKey === 'issue-out') {
      if (transactionEngineApplied) {
        return { label: 'Stock Out', variant: 'stock-out' };
      }
      return { label: 'Pending issuance', variant: 'pending-issue' };
    }
    const label = stockSt(inv) || 'In Stock';
    return { label, variant: isLow(inv) ? 'low' : '' };
  };
  const getLocationId = (inv) => inv?.location_id ?? inv?.location?.id ?? inv?.location?.location_id ?? '';
  const getLocationName = useCallback((locationId) => {
    const hit = locations.find((l) => String(l.id ?? l.location_id) === String(locationId));
    return hit?.location_name || hit?.name || '—';
  }, [locations]);

  useEffect(() => {
    if (!scanResult) return;
    const inv = scanResult.inventory;
    const inferredLocation = inv ? getLocationId(inv) : '';
    const issuePreview = scanResult.modeKey === 'issue-out' && !scanResult.transactionEngineApplied;
    const previewKey = issuePreview ? `${scanResult.barcode}\0${scanResult.scannedAt}` : '';

    setTxForm((prev) => {
      let nextQty;
      if (issuePreview) {
        if (issuancePreviewKeyRef.current !== previewKey) {
          issuancePreviewKeyRef.current = previewKey;
          nextQty = 1;
        } else {
          nextQty = prev.quantity;
        }
      } else {
        issuancePreviewKeyRef.current = '';
        nextQty = 1;
      }
      return {
        ...prev,
        quantity: nextQty,
        condition: 'Good',
        location_id: inferredLocation ? String(inferredLocation) : (prev.location_id || ''),
        to_location_id: scanResult.modeKey === 'transfer' ? prev.to_location_id : '',
        pending_transfer_id: scanResult.modeKey === 'transfer' ? prev.pending_transfer_id : '',
        ship_transfer_first: scanResult.modeKey === 'transfer' ? prev.ship_transfer_first : true,
        pc_id: prev.pc_id || '',
        adjustment_direction: 'deduct',
      };
    });
  }, [scanResult]);

  const currentModeKey = (scanMode || MODES[modeIndex])?.key;

  /* When FROM LOCATION changes, show stock for that row (or null if no record there) */
  useEffect(() => {
    if (!scanResult?.inventoryRows?.length || !txForm.location_id) return;
    const picked = pickInventoryForLocation(scanResult.inventoryRows, txForm.location_id);
    setScanResult((prev) => {
      if (!prev) return prev;
      const same = String(prev.inventory?.inventory_id || '') === String(picked?.inventory_id || '');
      if (same) return prev;
      return { ...prev, inventory: picked };
    });
  }, [txForm.location_id, scanResult?.inventoryRows]);

  const executeModeAction = useCallback(async () => {
    if (!scanResult?.item) return;
    const mode = scanMode || MODES[modeIndex];
    if (scanResult.transactionEngineApplied) {
      toast.info('Inventory was already updated when you scanned (server transaction engine). No need to post again.');
      return;
    }
    if (mode.key === 'audit') {
      toast.info('Audit is read-only; the verification was logged when you scanned.');
      return;
    }
    const productId = scanResult.item.product_id ?? scanResult.item.id;
    const qtyNum = mode.key === 'issue-out'
      ? (() => {
          const n = Number(txForm.quantity);
          if (!Number.isInteger(n) || n < 1) return null;
          return n;
        })()
      : Math.max(1, Math.floor(Number(txForm.quantity)) || 1);
    if (qtyNum === null) {
      toast.error('Enter a whole-number quantity of at least 1.');
      return;
    }
    const today = new Date().toISOString().slice(0, 10);
    const locationId = txForm.location_id || String(getLocationId(scanResult.inventory) || '');

    if (!productId) {
      toast.error('No product selected from scan result.');
      return;
    }

    const available = availableAtLocation(scanResult.inventory);
    if (mode.key === 'issue-out') {
      if (!scanResult.inventory) {
        toast.error('No inventory record for this product at the selected location. Receive stock first or pick another location.');
        return;
      }
      if (available < qtyNum) {
        toast.error(`Insufficient stock at this location. Available: ${available}, requested: ${qtyNum}.`);
        return;
      }
    }

    setTxSubmitting(true);
    try {
      if (mode.key === 'receive-po') {
        if (!locationId) {
          toast.error('Select a location for Receive PO.');
          return;
        }
        if (txForm.pc_id) {
          const selectedPo = purchaseOrders.find((p) => String(p.po_id ?? p.id) === String(txForm.pc_id));
          const blockReason = getReceivePoBlockReason(selectedPo);
          if (blockReason) {
            toast.error(blockReason);
            return;
          }
          const linePid = Number(productId);
          if (!receivePoProductIds.includes(linePid)) {
            toast.error('This product is not on the selected Purchase Order.');
            return;
          }
          const res = await receivingsAPI.create({
            pc_id: txForm.pc_id,
            location_id: locationId,
            receiving_number: `RCV-${Date.now()}`,
            receiving_date: today,
            total_quantity_damaged: 0,
            details: [
              {
                product_id: productId,
                quantity_amount: qtyNum,
                condition: txForm.condition || 'Good',
              },
            ],
          });
          toast.success('Receive PO posted successfully.');
          setTxLogs((prev) => [{
            id: Date.now(),
            mode: mode.label,
            ref: res?.data?.receiving_number || res?.receiving_number || 'Posted',
            barcode: scanResult.barcode,
            qty: qtyNum,
            location: getLocationName(locationId),
            at: new Date().toLocaleTimeString(),
            receivingId: res?.data?.receiving_id || null,
          }, ...prev].slice(0, 8));
        } else {
          const engRes = await inventoryAPI.scanTransaction({
            barcode: scanResult.barcode,
            transaction_type: 'receiving',
            location_id: Number(locationId),
            quantity: qtyNum,
          });
          const eng = engRes?.data;
          if (!eng || typeof eng !== 'object' || eng.action === 'rejected') {
            toast.error(eng?.reason || 'Receiving failed.');
            return;
          }
          (Array.isArray(eng.warnings) ? eng.warnings : []).forEach((w) => toast.warning(w));
          const p = eng.product;
          if (!p) {
            toast.error('No product data returned.');
            return;
          }
          const item = { ...p, id: p.product_id };
          const inventoryRows = Array.isArray(eng.inventory) ? eng.inventory : [];
          const invPicked = pickInventoryForLocation(inventoryRows, locationId)
            || pickInventoryForLocation(inventoryRows, '');
          setScanResult((prev) => (prev ? {
            ...prev,
            item,
            inventory: invPicked,
            inventoryRows,
            transactionEngineApplied: true,
          } : prev));
          setScanHistory((prev) => {
            const next = prev.map((h) => (
              h.barcode === scanResult.barcode && h.scannedAt === scanResult.scannedAt
                ? {
                  ...h,
                  item,
                  inventory: invPicked,
                  inventoryRows,
                  transactionEngineApplied: true,
                }
                : h
            ));
            try { localStorage.setItem('bs_scan_history', JSON.stringify(next)); } catch { /* ignore */ }
            return next;
          });
          toast.success('Receiving posted successfully.');
          setTxLogs((prev) => [{
            id: Date.now(),
            mode: mode.label,
            ref: eng?.receiving_number || eng?.reference || 'Receiving',
            barcode: scanResult.barcode,
            qty: qtyNum,
            location: getLocationName(locationId),
            at: new Date().toLocaleTimeString(),
          }, ...prev].slice(0, 8));
        }
      } else if (mode.key === 'issue-out') {
        if (!locationId) {
          toast.error('Select a location for Issue Out.');
          return;
        }
        const engRes = await inventoryAPI.scanTransaction({
          barcode: scanResult.barcode,
          transaction_type: 'issuance',
          location_id: Number(locationId),
          quantity: qtyNum,
        });
        const eng = engRes?.data;
        if (!eng || typeof eng !== 'object' || eng.action === 'rejected') {
          toast.error(eng?.reason || 'Issuance failed.');
          return;
        }
        (Array.isArray(eng.warnings) ? eng.warnings : []).forEach((w) => toast.warning(w));
        const p = eng.product;
        if (!p) {
          toast.error('No product data returned.');
          return;
        }
        const item = { ...p, id: p.product_id };
        const inventoryRows = Array.isArray(eng.inventory) ? eng.inventory : [];
        const invPicked = pickInventoryForLocation(inventoryRows, locationId)
          || pickInventoryForLocation(inventoryRows, '');
        setScanResult((prev) => (prev ? {
          ...prev,
          item,
          inventory: invPicked,
          inventoryRows,
          transactionEngineApplied: true,
          issuedQuantity: qtyNum,
        } : prev));
        setScanHistory((prev) => {
          const next = prev.map((h) => (
            h.barcode === scanResult.barcode && h.scannedAt === scanResult.scannedAt
              ? {
                ...h,
                item,
                inventory: invPicked,
                inventoryRows,
                transactionEngineApplied: true,
                issuedQuantity: qtyNum,
              }
              : h
          ));
          try { localStorage.setItem('bs_scan_history', JSON.stringify(next)); } catch { /* ignore */ }
          return next;
        });
        toast.success(`Issued ${qtyNum} unit(s). Inventory updated.`);
        setTxLogs((prev) => [{
          id: Date.now(),
          mode: mode.label,
          ref: `Issued ${qtyNum} u`,
          barcode: scanResult.barcode,
          qty: qtyNum,
          location: getLocationName(locationId),
          at: new Date().toLocaleTimeString(),
        }, ...prev].slice(0, 8));
      } else if (mode.key === 'adjust') {
        if (!locationId) {
          toast.error('Select a location for adjustment.');
          return;
        }
        const addQty = txForm.adjustment_direction === 'add' ? qtyNum : 0;
        const deductQty = txForm.adjustment_direction === 'deduct' ? qtyNum : 0;
        const res = await adjustmentsAPI.create({
          location_id: locationId,
          adjustment_number: `ADJ-${Date.now()}`,
          adjustment_date: today,
          adjustment_type: txForm.adjustment_direction === 'add' ? 'Found Stock' : 'Stock Count',
          adjusted_by: scannedByName,
          details: [
            {
              product_id: productId,
              add_quantity: addQty,
              deduct_quantity: deductQty,
            },
          ],
        });
        toast.success('Adjustment posted successfully.');
        setTxLogs((prev) => [{
          id: Date.now(),
          mode: mode.label,
          ref: res?.data?.adjustment_number || res?.adjustment_number || 'Posted',
          barcode: scanResult.barcode,
          qty: qtyNum,
          location: getLocationName(locationId),
          at: new Date().toLocaleTimeString(),
        }, ...prev].slice(0, 8));
      }
      await mergeFreshInventoryForSession(
        productId,
        txForm.location_id,
        scanResult.barcode,
        scanResult.scannedAt,
      );
      reloadRecentMovement();
      if (mode.key === 'receive-po') loadPurchaseOrders();
    } catch (err) {
      toast.error(err.message || 'Failed to execute inventory action.');
    } finally {
      setTxSubmitting(false);
    }
  }, [scanResult, scanMode, modeIndex, txForm, scannedByName, getLocationName, mergeFreshInventoryForSession, reloadRecentMovement, loadPurchaseOrders, purchaseOrders, receivePoProductIds]);

  /* ── mode-aware field labels ── */
  const getModeLabels = (modeKey) => {
    switch (modeKey) {
      case 'receive-po': return { branch: 'Receiving Branch', warehouse: 'Destination Warehouse', location: 'Storage Location' };
      case 'transfer':   return { branch: 'Destination (Showroom)', warehouse: 'From Warehouse',       location: 'Transfer Location' };
      case 'issue-out':  return { branch: 'Issued To (Branch)',     warehouse: 'From Warehouse',       location: 'Current Location' };
      case 'audit':      return { branch: 'Branch',                 warehouse: 'Warehouse',            location: 'Audit location' };
      case 'adjust':
      default:           return { branch: 'Branch',                 warehouse: 'Warehouse',            location: 'Location' };
    }
  };

  const receivePoSetupGrid = (
    <div className="bs-action-grid">
      <div className="bs-filter-col">
        <label title="Per scan: full amount for consumables; appliances always add 1 unit per scan.">QUANTITY</label>
        <input
          type="number"
          min="1"
          value={txForm.quantity}
          onChange={(e) => setTxForm((p) => ({ ...p, quantity: e.target.value }))}
          title="Consumables: units added per scan. Appliances: treated as 1 (serialized)."
        />
      </div>
      <div className="bs-filter-col">
        <label>CONDITION</label>
        <select
          value={txForm.condition}
          onChange={(e) => setTxForm((p) => ({ ...p, condition: e.target.value }))}
        >
          {['Good', 'Fair', 'Damaged', 'Defective'].map((c) => <option key={c} value={c}>{c}</option>)}
        </select>
      </div>
      <div className="bs-filter-col">
        <label>LOCATION</label>
        {isStrictLocation ? (
          <LocationBadge locationName={enforcedLocationName} locationType={enforcedLocationType} />
        ) : (
          <LocationSelector
            value={txForm.location_id ? Number(txForm.location_id) : null}
            onChange={(val) => setTxForm((p) => ({ ...p, location_id: val != null ? String(val) : '' }))}
            allowedLocations={allowedLocations.length ? allowedLocations : locations.map(l => ({ location_id: l.id ?? l.location_id, location_name: l.location_name || l.name, location_type: l.location_type ?? '', location_code: l.location_code ?? '' }))}
          />
        )}
      </div>
      <div className="bs-filter-col">
        <label>PURCHASE ORDER (OPTIONAL)</label>
        <select
          value={txForm.pc_id}
          onChange={(e) => setTxForm((p) => ({ ...p, pc_id: e.target.value }))}
        >
          <option value="">None — receive without PO</option>
          {purchaseOrders.map((po) => {
            const pid = po.po_id ?? po.id;
            const label = po.po_number || po.pc_number || (pid != null ? `PO #${pid}` : '—');
            return (
              <option key={pid} value={pid}>{label}</option>
            );
          })}
        </select>
      </div>
    </div>
  );

  const issueOutSetupGrid = (
    <div className="bs-action-grid">
      <div className="bs-filter-col">
        <label title="Whole units to issue; validated against available stock after scan.">QUANTITY</label>
        <input
          type="number"
          min="1"
          step="1"
          inputMode="numeric"
          value={txForm.quantity}
          onChange={(e) => setTxForm((p) => ({ ...p, quantity: e.target.value }))}
        />
      </div>
      <div className="bs-filter-col">
        <label>CONDITION</label>
        <select
          value={txForm.condition}
          onChange={(e) => setTxForm((p) => ({ ...p, condition: e.target.value }))}
        >
          {['Good', 'Fair', 'Damaged', 'Defective'].map((c) => <option key={c} value={c}>{c}</option>)}
        </select>
      </div>
      <div className="bs-filter-col">
        <label>LOCATION <span className="bs-required-mark">*</span></label>
        {isStrictLocation ? (
          <LocationBadge locationName={enforcedLocationName} locationType={enforcedLocationType} />
        ) : (
          <LocationSelector
            value={txForm.location_id ? Number(txForm.location_id) : null}
            onChange={(val) => setTxForm((p) => ({ ...p, location_id: val != null ? String(val) : '' }))}
            allowedLocations={allowedLocations.length ? allowedLocations : locations.map(l => ({ location_id: l.id ?? l.location_id, location_name: l.location_name || l.name, location_type: l.location_type ?? '', location_code: l.location_code ?? '' }))}
            error={!txForm.location_id && !isStrictLocation ? 'Required before scanning — choose where stock is issued from.' : ''}
          />
        )}
      </div>
    </div>
  );

  /* ─────────────────────────────────────────────
     RENDER: HOME VIEW
  ───────────────────────────────────────────── */
  if (scanMode === null) {
    return (
      <AdminLayout>
        <div className="ak-page bs-page">
          <header className="ak-header">
            <div className="ak-header-row">
              <div>
                <p className="ak-eyebrow">Inventory · Barcode</p>
                <h1 className="ak-title">Inventory Operation</h1>
                <p className="ak-sub">Scan barcode or QR code to view item details and perform inventory lookups.</p>
              </div>
            </div>
          </header>

          {/* ── mode action cards ── */}
          <div className="bs-mode-cards">
            {MODES.map((m, idx) => (
              <button
                key={m.key}
                className="bs-mode-card"
                style={{ '--mc': m.color, '--mc-bg': m.bg }}
                onClick={() => selectMode(m, idx)}
                type="button"
              >
                <span className="bs-mc-icon" style={{ color: m.color }}>{m.icon}</span>
                <span className="bs-mc-label">{m.label}</span>
              </button>
            ))}
          </div>

          {/* ── scan history ── */}
          {scanHistory.length > 0 && (
            <div className="bs-home-card">
              <div className="bs-home-card-head">
                <span className="bs-home-card-title">Scan History</span>
                <span className="bs-home-card-sub">Recent scans</span>
              </div>
              <div className="bs-history-list">
                {scanHistory.map((h, i) => {
                  const hMode = MODES.find(m => m.key === h.modeKey);
                  return (
                    <div key={i} className="bs-history-item" onClick={() => { selectMode(hMode || MODES[0], MODES.findIndex(m => m.key === h.modeKey) || 0); setScanResult(h); }}>
                      <div className="bs-hi-left">
                        <span className="bs-hi-name">{h.item.product_name || h.item.name}</span>
                        <span className="bs-hi-code">{h.barcode}</span>
                        <span className="bs-hi-time">{h.scannedAt}</span>
                      </div>
                      <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'flex-end', gap: 4 }}>
                        <span style={{
                          fontSize: '0.6rem', fontWeight: 700, letterSpacing: '0.05em',
                          color: hMode?.color || '#6b7280',
                          background: hMode?.bg || '#f3f4f6',
                          padding: '2px 8px', borderRadius: 4, whiteSpace: 'nowrap',
                        }}>
                          {h.mode}
                        </span>
                        {h.inventory && (() => {
                          const st = getStatusChipDisplay(h.modeKey, h.inventory, h.transactionEngineApplied);
                          return (
                            <span className={`bs-status-chip${st.variant ? ` ${st.variant}` : ''}`}>
                              {st.label}
                            </span>
                          );
                        })()}
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>
          )}

          {/* ── recent movement ── */}
          {recentMovement.length > 0 && (
            <div className="bs-home-card">
              <div className="bs-home-card-head">
                <span className="bs-home-card-title">Recent Movement</span>
                <button className="bs-viewall" type="button" onClick={() => navigate('/admin/inventory?tab=activity')}>VIEW ALL</button>
              </div>
              <div className="bs-movement-list">
                {recentMovement.map((m, i) => {
                  const ref = m.receiving_code || m.issuance_code || m.transfer_code || m.reference_number || `#${m.id || i + 1}`;
                  const label = `${m._type} ${ref}`.trim();
                  const when  = m.created_at
                    ? new Date(m.created_at).toLocaleString()
                    : m.transaction_date || '';
                  return (
                    <div key={i} className="bs-movement-item">
                      <span className="bs-movement-number">{i + 1}</span>
                      <span className="bs-movement-dot" style={{ background: movementColor(m._type) }} />
                      <div className="bs-movement-info">
                        <span className="bs-movement-label">{label}</span>
                        {when && <span className="bs-movement-time">{when}</span>}
                      </div>
                      <span className="bs-success-chip">Success</span>
                    </div>
                  );
                })}
              </div>
            </div>
          )}
        </div>
      </AdminLayout>
    );
  }

  /* ─────────────────────────────────────────────
     RENDER: BSR — PO list view
  ───────────────────────────────────────────── */
  if (scanMode?.key === 'receive-po' && !bsrSelectedPo) {
    return (
      <AdminLayout>
        <div className="bsr-page">
          <div className="bsr-header-card">
            <button className="bs-topback-btn" onClick={exitScanMode} style={{ marginRight: 12, flexShrink: 0 }} type="button">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15"><polyline points="15 18 9 12 15 6"/></svg>
              Back
            </button>
            <div className="bsr-header-icon"><BsrBarcodeIcon size={26} color="#9810FA" /></div>
            <div className="bsr-header-text">
              <h1>BARCODE SCAN RECEIVE</h1>
              <p>Scan products from approved purchase orders</p>
            </div>
          </div>
          <div className="bsr-search-card">
            <div className="bsr-search-inner">
              <BsrSearchIcon />
              <input
                className="bsr-search-input"
                placeholder="Search by PO ID or supplier..."
                value={bsrPoSearchQ}
                onChange={(e) => setBsrPoSearchQ(e.target.value)}
              />
            </div>
          </div>
          {bsrPoLoading ? (
            <Loader label="Loading purchase orders" />
          ) : bsrFilteredPos.length === 0 ? (
            <div className="bsr-empty">No approved purchase orders found.</div>
          ) : bsrFilteredPos.map((po) => {
            const poId = po.po_id ?? po.id;
            const scoped    = !!myLocationId;
            const totalAmt  = scoped
              ? bsrLocationAmount(po, myLocationId)
              : Number(po.total_amount || po.grand_total || 0);
            const itemCount = scoped ? bsrLocationItemCount(po, myLocationId) : bsrGetItemCount(po);
            const breakdown = scoped ? [] : bsrWarehouseBreakdown(po);
            const showBreakdown = !scoped && breakdown.length > 1;
            const statusName  = po?.status?.status_name ?? po?.status_name ?? '';
            const isPartial   = /partial/i.test(statusName);
            const isCompleted = /complet/i.test(statusName);
            const badgeClass  = isCompleted ? 'bsr-badge--completed' : isPartial ? 'bsr-badge--partial' : 'bsr-badge--approved';
            const badgeLabel  = isCompleted ? 'COMPLETED' : isPartial ? 'PARTIALLY RECEIVED' : statusName.toUpperCase() || 'APPROVED';
            return (
              <button key={poId} type="button" className="bsr-po-card" onClick={() => handleBsrSelectPo(po)}>
                <div className="bsr-po-card-top">
                  <div className="bsr-po-left">
                    <span className="bsr-po-number">{po.po_number || `PO #${poId}`}</span>
                    <span className={`bsr-badge ${badgeClass}`}><BsrCheckIcon /> {badgeLabel}</span>
                  </div>
                  <div className="bsr-po-amount">
                    <span className="bsr-amount-label">TOTAL AMOUNT</span>
                    <span className="bsr-amount-value">{bsrFmtCurrency(totalAmt)}</span>
                    {scoped && (
                      <span className="bsr-amount-scope">for {enforcedLocationName || 'your warehouse'}</span>
                    )}
                  </div>
                </div>
                <div className="bsr-po-card-meta">
                  <div className="bsr-meta-col"><span className="bsr-meta-label">SUPPLIER</span><span className="bsr-meta-value">{bsrGetSupplierName(po)}</span></div>
                  <div className="bsr-meta-col"><span className="bsr-meta-label">APPROVED BY</span><span className="bsr-meta-value">{bsrGetApprovedBy(po)}</span></div>
                  <div className="bsr-meta-col"><span className="bsr-meta-label">EXPECTED DELIVERY</span><span className="bsr-meta-value">{bsrFmtDate(po.expected_delivery_date)}</span></div>
                  <div className="bsr-meta-col">
                    <span className="bsr-meta-label">ITEMS</span>
                    <span className="bsr-meta-value">{typeof itemCount === 'number' ? `${itemCount} items` : '—'}</span>
                  </div>
                </div>
                {showBreakdown && (
                  <div className="bsr-po-breakdown">
                    {breakdown.map((b) => (
                      <span key={b.locationId} className="bsr-breakdown-chip">
                        {b.locationName} <strong>{bsrFmtCurrency(b.amount)}</strong>
                      </span>
                    ))}
                  </div>
                )}
              </button>
            );
          })}
        </div>
      </AdminLayout>
    );
  }

  /* ─────────────────────────────────────────────
     RENDER: BSR — 2-column scan workflow
  ───────────────────────────────────────────── */
  if (scanMode?.key === 'receive-po' && bsrSelectedPo) {
    const poDetails = bsrLinesForLocation(bsrSelectedPo, myLocationId);
    const totalScanned = bsrScannedItems.length;

    // True only when every ordered line has been fully received.
    const isFullyReceived = poDetails.length > 0 && poDetails.every((d) => {
      if (!d.po_detail_id) return true;
      const dIsSer = d.product?.product_type === 'appliance';
      const dPps   = Number(d.product?.pieces_per_package) || 1;
      const dOrd   = Number(d.quantity_ordered || 0);
      const dOrdPcs = dIsSer ? dOrd : dOrd * dPps;
      return dOrdPcs === 0 || (bsrProductProgress[d.po_detail_id] || 0) >= dOrdPcs;
    });

    const handleBsrSaveExit = () => {
      const hasScans = Object.values(bsrProductProgress).some((v) => v > 0);
      const doExit = () => {
        void stopCamera();
        setBsrSelectedPo(null); setBsrScannedItems([]); setBsrProductProgress({});
        setBsrScanInput(''); setBsrActiveLine(null);
      };
      if (hasScans) {
        bsrProcessingRef.current = true;
        setBsrAlertModal({
          type: 'warning',
          title: 'Progress Saved',
          message: `Your scans for ${bsrSelectedPo?.po_number} have been saved. The PO is marked as "Partially Received" and can be resumed at any time.`,
          onDismiss: doExit,
        });
      } else {
        doExit();
      }
    };
    return (
      <AdminLayout>
        <div className="bsr-page">
          <div className="bsr-header-card">
            <div className="bsr-header-icon"><BsrBarcodeIcon size={26} color="#9810FA" /></div>
            <div className="bsr-header-text">
              <h1>BARCODE SCAN RECEIVE</h1>
              <p>Scan products from approved purchase orders</p>
            </div>
            <div className="bsr-receiving-badge"><BsrCheckIcon /> Receiving: {bsrSelectedPo?.po_number}</div>
          </div>
          {/* ── Alert / validation modal (centered, blocks all input) ── */}
          {bsrAlertModal && (
            <div className="bsr-alert-overlay" role="dialog" aria-modal="true">
              <div className={`bsr-alert-modal bsr-alert-modal--${bsrAlertModal.type}`}>
                <div className="bsr-alert-icon">
                  {bsrAlertModal.type === 'success' ? (
                    <svg viewBox="0 0 24 24" fill="none" stroke="#16a34a" strokeWidth="2.5" width="36" height="36">
                      <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                      <polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                  ) : bsrAlertModal.type === 'warning' ? (
                    <svg viewBox="0 0 24 24" fill="none" stroke="#d97706" strokeWidth="2.5" width="36" height="36">
                      <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                      <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                    </svg>
                  ) : (
                    <svg viewBox="0 0 24 24" fill="none" stroke="#dc2626" strokeWidth="2.5" width="36" height="36">
                      <circle cx="12" cy="12" r="10"/>
                      <line x1="12" y1="8" x2="12" y2="12"/>
                      <line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                  )}
                </div>
                <h3 className="bsr-alert-title">{bsrAlertModal.title}</h3>
                <p className="bsr-alert-message">{bsrAlertModal.message}</p>
                <button
                  type="button"
                  className={`bsr-alert-dismiss-btn bsr-alert-dismiss-btn--${bsrAlertModal.type}`}
                  onClick={handleBsrAlertDismiss}
                  autoFocus
                >
                  {bsrAlertModal.type === 'success' ? 'OK' : 'Dismiss'}
                </button>
              </div>
            </div>
          )}

          <div className="bsr-workflow">
            <div className="bsr-left">
              <div className="bsr-card">
                <div className="bsr-card-head">
                  <div className="bsr-card-icon bsr-card-icon--doc">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#9810FA" strokeWidth="2">
                      <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                      <polyline points="14 2 14 8 20 8"/>
                      <line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>
                    </svg>
                  </div>
                  <span className="bsr-card-title">PURCHASE ORDER</span>
                  <button type="button" className="bsr-close-btn"
                    onClick={handleBsrSaveExit}
                    title="Save & exit to PO list"
                  >×</button>
                </div>
                <div className="bsr-po-details">
                  <div className="bsr-detail-row"><span className="bsr-detail-label">PO Number:</span><span className="bsr-detail-value bsr-detail-value--bold">{bsrSelectedPo?.po_number}</span></div>
                  <div className="bsr-detail-row"><span className="bsr-detail-label">Supplier:</span><span className="bsr-detail-value bsr-detail-value--bold">{bsrGetSupplierName(bsrSelectedPo)}</span></div>
                  <div className="bsr-detail-row"><span className="bsr-detail-label">Delivery Location:</span><span className="bsr-detail-value bsr-detail-value--bold">{bsrGetDeliveryLocation(bsrSelectedPo)}</span></div>
                  <div className="bsr-detail-row"><span className="bsr-detail-label">Expected Delivery:</span><span className="bsr-detail-value bsr-detail-value--bold">{bsrFmtDate(bsrSelectedPo?.expected_delivery_date)}</span></div>
                </div>
              </div>
              <div className="bsr-card">
                <div className="bsr-card-head">
                  <div className="bsr-card-icon bsr-card-icon--box">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#9810FA" strokeWidth="2">
                      <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                      <polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>
                    </svg>
                  </div>
                  <span className="bsr-card-title">ITEMS TO RECEIVE</span>
                </div>
                <div className="bsr-items-list">
                  {poDetails.length === 0 ? (
                    <p className="bsr-items-empty">No line items found on this PO.</p>
                  ) : poDetails.map((d, i) => {
                    const prod = d.product || {};
                    const pid = d.product_id || prod.product_id;
                    const detailId = d.po_detail_id;
                    const name = prod.product_name || prod.name || `Product #${pid}`;
                    const sku = prod.applianceDetail?.model_code || prod.model_code || prod.barcode || '—';
                    const ordered = Number(d.quantity_ordered || d.quantity || d.quantity_amount || 0);
                    const isSerial = prod.product_type === 'appliance';
                    const pps = Number(prod.pieces_per_package) || 1;
                    const orderedPcs = isSerial ? ordered : ordered * pps;
                    const received = bsrProductProgress[detailId] || 0;
                    const pct = orderedPcs > 0 ? Math.min(100, (received / orderedPcs) * 100) : 0;
                    const done = received >= orderedPcs && orderedPcs > 0;
                    const isActive = bsrActiveLine?.po_detail_id === detailId;
                    return (
                      <div
                        key={detailId || pid || i}
                        className={`bsr-item-row bsr-item-row--clickable${isActive ? ' bsr-item-row--active' : ''}${done ? ' bsr-item-row--done' : ''}`}
                        onClick={() => !done && setBsrActiveLine(d)}
                        title={done ? 'Fully received' : 'Click to scan this item'}
                      >
                        <div className="bsr-item-info">
                          <span className="bsr-item-name">{name}</span>
                          <span className="bsr-item-sku">SKU: {sku}</span>
                          <div className="bsr-item-badges">
                            {prod.product_type === 'appliance'
                              ? <span className="bsr-serialized-badge">SERIAL</span>
                              : <span className="bsr-bulk-badge">BULK</span>}
                            {isActive && <span className="bsr-active-badge">SCANNING NOW</span>}
                          </div>
                        </div>
                        <div className="bsr-item-progress-row">
                          <span className="bsr-item-progress-label">Progress:</span>
                          <span className={`bsr-item-qty ${done ? 'bsr-item-qty--done' : 'bsr-item-qty--zero'}`}>
                            {isSerial
                              ? `${received} / ${ordered} units`
                              : `${Math.floor(received / pps)} / ${ordered} pkg`}
                          </span>
                        </div>
                        <div className="bsr-progress-bar-wrap">
                          <div className={`bsr-progress-bar-fill ${done ? 'bsr-progress-bar-fill--done' : ''}`} style={{ width: `${pct}%` }}/>
                        </div>
                      </div>
                    );
                  })}
                </div>
              </div>
            </div>
            <div className="bsr-right">

              {/* ── Scan Success overlay ── */}
              {bsrScanSuccess && (
                <div className="bsr-success-overlay">
                  <div className="bsr-success-modal">
                    <div className="bsr-success-icon">
                      <svg viewBox="0 0 24 24" fill="none" stroke="#16a34a" strokeWidth="2.5" width="40" height="40">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                        <polyline points="22 4 12 14.01 9 11.01"/>
                      </svg>
                    </div>
                    <h3 className="bsr-success-title">Scan Successful</h3>
                    <p className="bsr-success-product">{bsrScanSuccess.productName}</p>
                    <div className="bsr-success-details">
                      <div className="bsr-success-row">
                        <span className="bsr-success-label">Barcode</span>
                        <span className="bsr-success-value bsr-success-code">{bsrScanSuccess.barcode}</span>
                      </div>
                      {bsrScanSuccess.isSerialized ? (
                        <div className="bsr-success-row">
                          <span className="bsr-success-label">Unit Received</span>
                          <span className="bsr-success-value">1 unit</span>
                        </div>
                      ) : (
                        <>
                          <div className="bsr-success-row">
                            <span className="bsr-success-label">Received</span>
                            <span className="bsr-success-value">
                              1 {bsrScanSuccess.packageUnitLabel}
                              <span style={{ color: '#6b7280', fontWeight: 400, marginLeft: 4 }}>({bsrScanSuccess.qtyReceived} pcs)</span>
                            </span>
                          </div>
                          <div className="bsr-success-row">
                            <span className="bsr-success-label">Pcs / {bsrScanSuccess.packageUnitLabel}</span>
                            <span className="bsr-success-value">{bsrScanSuccess.pcs} pcs</span>
                          </div>
                        </>
                      )}
                      <div className="bsr-success-row">
                        <span className="bsr-success-label">Progress</span>
                        <span className="bsr-success-value">
                          {bsrScanSuccess.isSerialized
                            ? `${bsrScanSuccess.newReceived} / ${bsrScanSuccess.ordered} units`
                            : `${Math.floor(bsrScanSuccess.newReceived / bsrScanSuccess.pcs)} / ${bsrScanSuccess.ordered} ${bsrScanSuccess.packageUnitLabel}`
                          }
                          {bsrScanSuccess.lineComplete && <span className="bsr-success-complete-tag">COMPLETE</span>}
                        </span>
                      </div>
                      {!bsrScanSuccess.isSerialized && bsrScanSuccess.remainingPackages !== null && (
                        <div className="bsr-success-row">
                          <span className="bsr-success-label">Remaining</span>
                          <span className="bsr-success-value" style={{ color: bsrScanSuccess.remainingPackages <= 0 ? '#16a34a' : '#d97706' }}>
                            {bsrScanSuccess.remainingPackages <= 0
                              ? `0 ${bsrScanSuccess.packageUnitLabel}(s) — fully received`
                              : `${bsrScanSuccess.remainingPackages} ${bsrScanSuccess.packageUnitLabel}(s) left`
                            }
                          </span>
                        </div>
                      )}
                    </div>
                    <button type="button" className="bsr-success-done-btn" onClick={handleBsrScanSuccessDone}>
                      Done
                    </button>
                  </div>
                </div>
              )}

              <div className="bsr-card">
                <div className="bsr-card-head">
                  <div className="bsr-card-icon bsr-card-icon--scan"><BsrBarcodeIcon size={18} color="#9810FA" /></div>
                  <div>
                    <span className="bsr-card-title">SCAN BARCODE</span>
                    {bsrActiveLine ? (
                      <p className="bsr-card-subtitle">
                        <strong>{bsrActiveLine.product?.product_name || bsrActiveLine.product?.name || 'Selected Item'}</strong>
                      </p>
                    ) : (
                      <p className="bsr-card-subtitle bsr-card-subtitle--warn">Select an item from the left panel to begin</p>
                    )}
                  </div>
                </div>
                <div className="bsr-location-row">
                  <label className="bsr-location-label">RECEIVING LOCATION</label>
                  {isStrictLocation ? (
                    <LocationBadge locationName={enforcedLocationName} locationType={enforcedLocationType} />
                  ) : (
                    <LocationSelector
                      value={bsrLocationId ? Number(bsrLocationId) : null}
                      onChange={(val) => setBsrLocationId(val != null ? String(val) : '')}
                      allowedLocations={allowedLocations.length ? allowedLocations : locations.map(l => ({ location_id: l.id ?? l.location_id, location_name: l.location_name || l.name, location_type: l.location_type ?? '', location_code: l.location_code ?? '' }))}
                    />
                  )}
                </div>

                <div className="bs-camera-box" style={{ marginBottom: 12 }}>
                  <div
                    id={HTML5_QR_MOUNT_ID}
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
                    <p className="bs-cam-hint">Position the barcode in the frame and tap <strong>Scan</strong> — auto-capture is disabled; only the button triggers a scan.</p>
                  )}
                  {cameraError && <p className="bs-cam-err">{cameraError}</p>}
                  {cameraActive && canScan && (
                    <button type="button" className="bs-cam-shutter" onClick={manualFrameScan} aria-label="Scan barcode from current camera frame">
                      <span className="bs-cam-shutter-inner"/><span className="bs-cam-shutter-label">Scan</span>
                    </button>
                  )}
                  {cameraActive
                    ? <button className="bs-cam-btn bs-cam-stop" onClick={stopCamera} type="button">Stop Camera</button>
                    : <button className="bs-cam-btn bs-cam-start" onClick={startCamera} type="button">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14">
                          <path d="M23 7l-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/>
                        </svg>
                        Start Camera
                      </button>
                  }
                </div>
                {cameraDevices.length > 0 && (
                  <div className="bs-cam-select-bar" style={{ marginBottom: 10 }}>
                    <label htmlFor="bs-camera-device" className="bs-cam-select-bar-label">Camera</label>
                    <select id="bs-camera-device" className="bs-cam-select-bar-input" value={cameraSelectValue} onChange={onCameraDeviceChange}>
                      {cameraDevices.map((d) => (
                        <option key={d.deviceId} value={d.deviceId}>
                          {d.label}{isLikelyVirtualCameraLabel(d.label) ? ' (virtual)' : ''}
                        </option>
                      ))}
                    </select>
                  </div>
                )}

                <div className="bsr-scan-row">
                  <input
                    ref={bsrInputRef}
                    type="text"
                    className={`bsr-scan-input${bsrScanning ? ' bsr-scan-input--scanning' : ''}`}
                    placeholder={bsrActiveLine ? 'Scan or type barcode / serial...' : 'Select an item from the left panel first...'}
                    value={bsrScanInput}
                    onChange={handleBsrInputChange}
                    onKeyDown={handleBsrKeyDown}
                    autoComplete="off"
                    disabled={bsrScanning || !bsrActiveLine || !!bsrScanSuccess || !!bsrAlertModal || bsrPoStatusLoading}
                  />
                  <button
                    type="button"
                    className="bsr-capture-btn"
                    onClick={handleBsrCaptureClick}
                    disabled={!canScan || bsrScanning || !bsrActiveLine || !!bsrScanSuccess || !!bsrAlertModal || bsrPoStatusLoading || bsrScanInput.trim().length < 4}
                    aria-label="Submit barcode"
                  >
                    {bsrScanning ? 'Scanning…' : 'Submit'}
                  </button>
                </div>
                <div className="bsr-examples-card">
                  {bsrActiveLine ? (() => {
                    const isSerialized = bsrIsSerialized(bsrActiveLine);
                    const pcs = Number(bsrActiveLine.product?.pieces_per_package) || 1;
                    return isSerialized ? (
                      <>
                        <span className="bsr-examples-label">Scan serial number label on the unit</span>
                        <div className="bsr-examples-list">
                          <span className="bsr-example-code">{bsrActiveLine.product?.product_name || 'Item'} — 1 scan = 1 unit</span>
                        </div>
                      </>
                    ) : (
                      <>
                        <span className="bsr-examples-label">Scan the package barcode, then submit</span>
                        <div className="bsr-examples-list">
                          <span className="bsr-example-code">
                            {bsrActiveLine.product?.product_name || 'Item'} — {pcs} pcs per package
                          </span>
                        </div>
                      </>
                    );
                  })() : (
                    <>
                      <span className="bsr-examples-label">How to scan:</span>
                      <div className="bsr-examples-list">
                        <span className="bsr-example-code" style={{ opacity: 0.6 }}>Click an item on the left, then scan its barcode</span>
                      </div>
                    </>
                  )}
                </div>
              </div>
              <div className="bsr-card">
                <div className="bsr-card-head">
                  <div className="bsr-card-icon bsr-card-icon--cart"><BsrCartIcon size={18} /></div>
                  <span className="bsr-card-title">SCANNED ITEMS</span>
                  <span className="bsr-scanned-count">{totalScanned} {totalScanned === 1 ? 'item' : 'items'}</span>
                </div>
                {bsrScannedItems.length === 0 ? (
                  <div className="bsr-scanned-empty">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#d1d5db" strokeWidth="1.5">
                      <rect x="2" y="5" width="3" height="14" rx="1"/><rect x="7" y="5" width="1.5" height="14" rx="0.5"/>
                      <rect x="10" y="5" width="3" height="14" rx="1"/><rect x="15" y="5" width="1.5" height="14" rx="0.5"/>
                      <rect x="18" y="5" width="3" height="14" rx="1"/>
                    </svg>
                    <p>No items scanned yet</p>
                  </div>
                ) : (
                  <div className="bsr-scanned-list">
                    {bsrScannedItems.map((item) => (
                      <div key={item.id} className="bsr-scanned-item">
                        <div className="bsr-scanned-item-info">
                          <span className="bsr-scanned-item-name">{item.productName}</span>
                          <span className="bsr-scanned-item-serial">
                            {item.serial
                              ? item.serial
                              : item.packages != null && item.qty != null
                                ? `${item.packages} pkg${item.packages !== 1 ? 's' : ''} × ${item.qty / item.packages} pcs = ${item.qty} pcs`
                                : item.qty != null ? `${item.qty} pcs` : ''}
                          </span>
                        </div>
                        <span className="bsr-scanned-item-time">{item.at}</span>
                      </div>
                    ))}
                  </div>
                )}
              </div>
              <div className="bsr-action-btns">
                <button type="button" className="bsr-btn-cancel"
                  onClick={handleBsrSaveExit}
                >Save &amp; Exit</button>
                <button type="button"
                  className={`bsr-btn-complete${(!isFullyReceived && totalScanned === 0) ? ' bsr-btn-complete--disabled' : ''}`}
                  onClick={() => {
                    if (totalScanned === 0 && !isFullyReceived) {
                      setBsrAlertModal({ type: 'error', title: 'Nothing Scanned', message: 'No items have been scanned yet.' });
                      return;
                    }
                    const poNum = bsrSelectedPo?.po_number;
                    const count = totalScanned;
                    if (isFullyReceived) {
                      bsrProcessingRef.current = true;
                      setBsrAlertModal({
                        type: 'success',
                        title: 'Receiving Complete',
                        message: `All ordered items for ${poNum} have been received. The PO has been marked as Completed.`,
                        onDismiss: () => {
                          setBsrSelectedPo(null); setBsrScannedItems([]); setBsrProductProgress({}); setBsrScanInput('');
                        },
                      });
                    } else {
                      bsrProcessingRef.current = true;
                      setBsrAlertModal({
                        type: 'warning',
                        title: 'Partial Receiving Saved',
                        message: `${count} item(s) recorded for ${poNum}. Not all quantities have been received — this PO remains as "Partially Received". You can resume scanning at any time.`,
                        onDismiss: () => {
                          setBsrSelectedPo(null); setBsrScannedItems([]); setBsrProductProgress({}); setBsrScanInput('');
                        },
                      });
                    }
                  }}
                  disabled={!canScan || (totalScanned === 0 && !isFullyReceived)}
                ><BsrCheckIcon /> {isFullyReceived ? 'Complete Receiving' : 'Save & Exit'}</button>
              </div>
            </div>
          </div>
        </div>
      </AdminLayout>
    );
  }

  /* ─────────────────────────────────────────────
     RENDER: SCAN VIEW
  ───────────────────────────────────────────── */
  return (
    <AdminLayout>
      <div className="ak-page bs-page">
        <div className="bs-page-header" style={{ display: 'flex', alignItems: 'flex-start', gap: 12 }}>
          <button className="bs-topback-btn" onClick={exitScanMode} type="button">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15"><polyline points="15 18 9 12 15 6"/></svg>
            Back
          </button>
          <div>
            <h1>Inventory Operation</h1>
            <p>Scan barcode or QR code to view item details and perform inventory lookups</p>
          </div>
        </div>

        {/* ── camera box ── */}
        <div className="bs-camera-box">
          {/* mode bar inside camera */}
          <div className="bs-cam-modebar">
            <button className="bs-cam-arrow" onClick={() => { const ni = (modeIndex - 1 + MODES.length) % MODES.length; setModeIndex(ni); setScanMode(MODES[ni]); }} type="button">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" width="14" height="14"><polyline points="15 18 9 12 15 6"/></svg>
            </button>
            <div className="bs-cam-modename">
              <span>{MODES[modeIndex].label.toUpperCase()}</span>
              <span className="bs-cam-modesub">SCANNING BARCODE / QR</span>
            </div>
            <button className="bs-cam-arrow" onClick={() => { const ni = (modeIndex + 1) % MODES.length; setModeIndex(ni); setScanMode(MODES[ni]); }} type="button">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" width="14" height="14"><polyline points="9 18 15 12 9 6"/></svg>
            </button>
          </div>

          {/* html5-qrcode injects its preview video inside this mount */}
          <div
            id={HTML5_QR_MOUNT_ID}
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

          {/* corner brackets */}
          <div className="bs-bracket bs-tl"/><div className="bs-bracket bs-tr"/>
          <div className="bs-bracket bs-bl"/><div className="bs-bracket bs-br"/>

          {/* scan line */}
          {cameraActive && <div className="bs-scan-line"/>}

          {cameraActive && (
            <p className="bs-cam-hint">Position the barcode in the frame. Scanning runs automatically — or tap the white button to capture now.</p>
          )}

          {/* camera error */}
          {cameraError && <p className="bs-cam-err">{cameraError}</p>}

          {cameraActive && canScan && (
            <button
              type="button"
              className="bs-cam-shutter"
              onClick={() => manualFrameScan()}
              aria-label="Scan barcode from current camera frame"
            >
              <span className="bs-cam-shutter-inner" />
              <span className="bs-cam-shutter-label">Scan</span>
            </button>
          )}

          {/* camera button */}
          {cameraActive
            ? <button className="bs-cam-btn bs-cam-stop" onClick={stopCamera} type="button">Stop Camera</button>
            : <button className="bs-cam-btn bs-cam-start" onClick={startCamera} type="button">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14">
                  <path d="M23 7l-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/>
                </svg>
                Start Camera
              </button>
          }

          {/* back button */}
          <button className="bs-back-btn" onClick={exitScanMode} type="button">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14"><polyline points="15 18 9 12 15 6"/></svg>
            Back
          </button>
        </div>

        {cameraDevices.length > 0 && (
          <div className="bs-cam-select-bar">
            <label htmlFor="bs-camera-device" className="bs-cam-select-bar-label">Camera</label>
            <select
              id="bs-camera-device"
              className="bs-cam-select-bar-input"
              value={cameraSelectValue}
              onChange={onCameraDeviceChange}
            >
              {cameraDevices.map((d) => (
                <option key={d.deviceId} value={d.deviceId}>
                  {d.label}
                  {isLikelyVirtualCameraLabel(d.label) ? ' (virtual)' : ''}
                </option>
              ))}
            </select>
          </div>
        )}

        {currentModeKey === 'issue-out' && (
          <div className="bs-action-card bs-issue-out-setup" style={{ marginTop: 0, marginBottom: 20 }}>
            <div className="bs-action-head">
              <span className="bs-action-title">Issue Out — setup</span>
              <span className="bs-action-sub">
                Fill in <strong>quantity</strong>, <strong>condition</strong>, and <strong>location</strong> first, then scan the product barcode.
                Stock is reduced when you tap <strong>Issue stock out</strong> after the scan (catalog barcodes). Quantity must be a whole number ≥ 1 and cannot exceed available stock at the selected location.
              </span>
            </div>
            {issueOutSetupGrid}
          </div>
        )}

        {currentModeKey === 'receive-po' && (
          <div className="bs-action-card" style={{ marginTop: 0, marginBottom: 20 }}>
            <div className="bs-action-head">
              <span className="bs-action-title">Receive PO — setup</span>
              <span className="bs-action-sub">
                Select a storage location first, then scan. Purchase order is optional — leave it empty to receive without a PO.
                {' '}When a PO is selected, only products on that PO are accepted. Each valid scan updates inventory on the server.
                {' '}Quantity applies to <strong>consumables</strong> (bulk items); <strong>appliances</strong> are received one unit per scan (serialized).
                {receivePoLinesLoading && ' (Loading PO line items…)'}
                {!receivePoLinesLoading && txForm.pc_id && receivePoProductIds.length > 0
                  && ` Only ${receivePoProductIds.length} product(s) on this PO can be accepted by scan.`}
              </span>
            </div>
            {receivePoSetupGrid}
          </div>
        )}

        {currentModeKey === 'audit' && (
          <div className="bs-action-card" style={{ marginTop: 0, marginBottom: 20, borderColor: '#c7d2fe', background: '#f5f7ff' }}>
            <div className="bs-action-head">
              <span className="bs-action-title">Audit — setup</span>
              <span className="bs-action-sub">
                Select the location to verify, then scan barcodes. Stock is not changed; each scan is logged for traceability.
              </span>
            </div>
            <div className="bs-action-grid">
              <div className="bs-filter-col">
                <label>LOCATION</label>
                {isStrictLocation ? (
                  <LocationBadge locationName={enforcedLocationName} locationType={enforcedLocationType} />
                ) : (
                  <LocationSelector
                    value={txForm.location_id ? Number(txForm.location_id) : null}
                    onChange={(val) => setTxForm((p) => ({ ...p, location_id: val != null ? String(val) : '' }))}
                    allowedLocations={allowedLocations.length ? allowedLocations : locations.map(l => ({ location_id: l.id ?? l.location_id, location_name: l.location_name || l.name, location_type: l.location_type ?? '', location_code: l.location_code ?? '' }))}
                  />
                )}
              </div>
            </div>
          </div>
        )}

        {/* ── barcode input: camera for everyone; manual typing / wedge typing only for System Administrator ── */}
        <div className="bs-input-section">
          <p className="bs-input-title">Barcode / QR Scanner</p>
          {cameraBlockedPlainHttp && (
            <p
              className="bs-input-sub"
              style={{
                background: '#fff7ed',
                border: '1px solid #fdba74',
                borderRadius: 8,
                padding: '10px 12px',
                color: '#9a3412',
                marginBottom: 10,
              }}
            >
              <strong>Camera:</strong> The webcam will not work on plain <code style={{ fontSize: '0.9em' }}>http://</code> when the app is opened from a non-local address (e.g. <code style={{ fontSize: '0.9em' }}>http://192.168.x.x</code>).
              Use <strong>http://localhost:PORT</strong> or <strong>HTTPS</strong>
              {isAdminRole ? (
                <>, or type / use a USB wedge scanner in the field below.</>
              ) : (
                <> so the camera can be used. Manual entry is not available for your role on this network setup.</>
              )}
            </p>
          )}
          <p className="bs-input-sub">
            {isAdminRole
              ? 'Camera decodes automatically when the code is in view. Admins may also type or use a USB barcode wedge in the field — short pause or Enter / Tab submits.'
              : 'Camera decodes automatically when the code is in view. Manual typing and USB wedge entry are disabled; use the camera only.'}
          </p>
          <p className="bs-input-sub" style={{ marginTop: -6, fontSize: 12, color: '#64748b' }}>
            {currentModeKey === 'issue-out' ? (
              <>
                In <strong>Issue Out</strong>, use the <strong>setup</strong> above (quantity, condition, location), then scan. After the product appears, tap <strong>Issue stock out</strong> to deduct the quantity you entered. Unit <strong>serials</strong> may post immediately when the code is not a catalog barcode.
              </>
            ) : (
              <>
                Tip: In <strong>Receive PO</strong> mode, pick a <strong>location</strong> first; PO is optional.
                Unknown barcodes can be registered from the prompt. For lookup-only, use <strong>Audit</strong>.
              </>
            )}
          </p>
          <div className="bs-input-row">
            <div className="bs-input-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="#9ca3af" strokeWidth="1.5" width="16" height="16">
                <rect x="3" y="5" width="3" height="14" rx="1"/><rect x="8" y="5" width="1.5" height="14" rx="0.5"/>
                <rect x="11" y="5" width="3" height="14" rx="1"/><rect x="16" y="5" width="1.5" height="14" rx="0.5"/>
                <rect x="19" y="5" width="2" height="14" rx="1"/>
              </svg>
            </div>
            <input
              ref={inputRef}
              type="text"
              className="bs-barcode-input"
              placeholder={isAdminRole ? 'Scan with camera, type, or USB wedge scanner…' : 'Decoded value appears here (camera only)'}
              value={barcodeInput}
              readOnly={!isAdminRole}
              onPaste={!isAdminRole ? (e) => e.preventDefault() : undefined}
              onChange={
                isAdminRole
                  ? (e) => {
                      const v = e.target.value;
                      setBarcodeInput(v);
                      if (inputScanTimerRef.current) clearTimeout(inputScanTimerRef.current);
                      inputScanTimerRef.current = setTimeout(() => {
                        inputScanTimerRef.current = null;
                        const raw = String(inputRef.current?.value ?? '')
                          .replace(/\s+/g, '')
                          .trim();
                        if (!raw || scanningRef.current) return;
                        if (raw.length < 4) return;
                        handleScanRef.current?.(raw);
                      }, 200);
                    }
                  : () => {}
              }
              autoComplete="off"
              inputMode="text"
              enterKeyHint="done"
              onKeyDown={(e) => {
                if (isAdminRole && (e.key === 'Enter' || e.key === 'Tab')) {
                  if (inputScanTimerRef.current) {
                    clearTimeout(inputScanTimerRef.current);
                    inputScanTimerRef.current = null;
                  }
                  const raw = String(e.currentTarget?.value ?? barcodeInput ?? '')
                    .replace(/\s+/g, '')
                    .trim();
                  if (raw) {
                    e.preventDefault();
                    handleScan(raw);
                  } else if (e.key === 'Enter') {
                    e.preventDefault();
                  }
                  return;
                }
                if (!isAdminRole && e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
                  e.preventDefault();
                }
              }}
              disabled={scanning}
              autoFocus
            />
            {scanning && <span className="bs-spin"/>}
          </div>
          {lastDecoded && <p className="bs-lastdecoded">Last decoded: {lastDecoded}</p>}
        </div>

        {/* ── type tabs ── */}
        <div className="bs-type-row">
          <button className={`bs-type-btn${activeType === 'appliance' ? ' active' : ''}`} onClick={() => setActiveType('appliance')} type="button">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="13" height="13"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
            UNIT / APPLIANCE
          </button>
          <button className={`bs-type-btn${activeType === 'installation_material' ? ' active' : ''}`} onClick={() => setActiveType('installation_material')} type="button">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="13" height="13"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
            PART / MATERIAL
          </button>
        </div>

        {currentModeKey === 'transfer' && (
          <div style={{ margin: '12px 0', padding: '14px 16px', background: '#fff7ed', borderRadius: 10, border: '1px solid #fed7aa' }}>
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 10 }}>
              <button
                type="button"
                className={`bs-type-btn${transferSubmode === 'out' ? ' active' : ''}`}
                onClick={() => setTransferSubmode('out')}
              >
                TRANSFER OUT
              </button>
              <button
                type="button"
                className={`bs-type-btn${transferSubmode === 'in' ? ' active' : ''}`}
                onClick={() => setTransferSubmode('in')}
              >
                TRANSFER IN (showroom)
              </button>
            </div>
            <p style={{ margin: 0, fontSize: 13, color: '#9a3412', lineHeight: 1.45 }}>
              {transferSubmode === 'out'
                ? 'Ship from warehouse. Use “Ship first” so stock only appears at the showroom after someone scans to receive here.'
                : 'Truck arrived? Pick your showroom and the in-transit transfer, then scan each barcode to add stock at this location.'}
            </p>
            <button type="button" className="btn-link" style={{ padding: 0, marginTop: 10, fontSize: 13 }} onClick={() => navigate('/admin/ist')}>
              Open IST receive flow →
            </button>
          </div>
        )}

        {/* ── filter dropdowns ── */}
        <div className="bs-filters">
          <div className="bs-filter-col">
            <label>CATEGORY</label>
            <select value={filterCategory} onChange={(e) => setFilterCategory(e.target.value)}>
              <option value="">All</option>
              {categories.map((c) => <option key={c.category_id} value={c.category_id}>{c.category_name}</option>)}
            </select>
          </div>
          <div className="bs-filter-col">
            <label>BRAND</label>
            <select value={filterBrand} onChange={(e) => setFilterBrand(e.target.value)}>
              <option value="">All</option>
              {brands.map((b) => <option key={b.brand_id} value={b.brand_id}>{b.brand_name}</option>)}
            </select>
          </div>
          <div className="bs-filter-col">
            <label>MODEL CODE</label>
            <input
              type="text"
              value={filterModel}
              onChange={(e) => setFilterModel(e.target.value)}
              placeholder="Model code"
            />
          </div>
        </div>

        {/* ── scan result card ── */}
        {scanResult && (() => {
          const lbl = getModeLabels(scanResult.modeKey);
          const mode = scanMode || MODES[modeIndex];
          const isTransferMode = scanResult.modeKey === 'transfer';
          const destinationShowroomName = isTransferMode && txForm.to_location_id
            ? getLocationName(txForm.to_location_id)
            : '';
          const fromLocationName = isTransferMode && txForm.location_id
            ? getLocationName(txForm.location_id)
            : '';
          return (
            <div className="bs-result-wrap" ref={scanResultRef}>
            <div className="bs-result-card">
              <div className="bs-result-head">
                <div className="bs-check-circle">
                  <svg viewBox="0 0 24 24" fill="none" stroke="#fff" strokeWidth="3" width="13" height="13"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
                <h3 className="bs-result-name">{scanResult.item.product_name || scanResult.item.name}</h3>
              </div>
              <div className="bs-result-grid">
                {/* Left column */}
                <div className="bs-result-col">
                  <div className="bs-result-cell">
                    <span className="bs-rk">MODEL CODE</span>
                    <span className="bs-rv">{scanResult.item.model_code || scanResult.item.applianceDetail?.model_code || '—'}</span>
                  </div>
                  <div className="bs-result-cell">
                    <span className="bs-rk">Category</span>
                    <span className="bs-rv">
                      {scanResult.item.category?.category_name
                        || scanResult.item.category_name
                        || '—'}
                    </span>
                  </div>
                  {scanResult.auditSnapshot ? (
                    <>
                      <div className="bs-result-cell">
                        <span className="bs-rk">Location</span>
                        <span className="bs-rv">{scanResult.auditSnapshot.location_name || '—'}</span>
                      </div>
                      <div className="bs-result-cell">
                        <span className="bs-rk">On hand (this location)</span>
                        <span className="bs-rv">{scanResult.auditSnapshot.current_stock ?? '—'} units</span>
                      </div>
                      <div className="bs-result-cell">
                        <span className="bs-rk">Unit Price</span>
                        <span className="bs-rv">₱{price(scanResult.item).toLocaleString()}</span>
                      </div>
                      <div className="bs-result-cell">
                        <span className="bs-rk">Audit</span>
                        <span className="bs-rv" style={{ color: '#4338ca', fontWeight: 600 }}>Verified (no stock change)</span>
                      </div>
                    </>
                  ) : scanResult.inventory ? (
                    <>
                      <div className="bs-result-cell">
                        <span className="bs-rk">{lbl.branch}</span>
                        <span className="bs-rv">
                          {isTransferMode
                            ? (destinationShowroomName || '—')
                            : (branch(scanResult.inventory) || '—')}
                        </span>
                      </div>
                      <div className="bs-result-cell">
                        <span className="bs-rk">{lbl.location}</span>
                        <span className="bs-rv">{locCode(scanResult.inventory) || '—'}</span>
                      </div>
                      <div className="bs-result-cell">
                        <span className="bs-rk">Unit Price</span>
                        <span className="bs-rv">₱{price(scanResult.item).toLocaleString()}</span>
                      </div>
                      <div className="bs-result-cell">
                        <span className="bs-rk">Status</span>
                        {(() => {
                          const st = getStatusChipDisplay(
                            scanResult.modeKey,
                            scanResult.inventory,
                            scanResult.transactionEngineApplied,
                          );
                          return (
                            <span className={`bs-status-chip${st.variant ? ` ${st.variant}` : ''}`}>
                              {st.label}
                            </span>
                          );
                        })()}
                      </div>
                    </>
                  ) : null}
                </div>
                {/* Right column */}
                <div className="bs-result-col">
                  <div className="bs-result-cell">
                    <span className="bs-rk">Barcode</span>
                    <span className="bs-rv bs-mono">{scanResult.barcode}</span>
                  </div>
                  <div className="bs-result-cell">
                    <span className="bs-rk">Brand</span>
                    <span className="bs-rv">
                      {scanResult.item.applianceDetail?.brand?.brand_name
                        || scanResult.item.brand?.brand_name
                        || scanResult.item.brand_name
                        || '—'}
                    </span>
                  </div>
                  {scanResult.auditSnapshot ? (
                    <>
                      <div className="bs-result-cell">
                        <span className="bs-rk">Item (audit)</span>
                        <span className="bs-rv">{scanResult.auditSnapshot.item_name || scanResult.item.product_name || '—'}</span>
                      </div>
                      <div className="bs-result-cell">
                        <span className="bs-rk">Quantity</span>
                        <span className="bs-rv">{scanResult.auditSnapshot.current_stock ?? '—'} units</span>
                      </div>
                      <div className="bs-result-cell">
                        <span className="bs-rk">Total Value</span>
                        <span className="bs-rv">₱{((Number(scanResult.auditSnapshot.current_stock) || 0) * price(scanResult.item)).toLocaleString()}</span>
                      </div>
                      <div className="bs-result-cell">
                        <span className="bs-rk">Item Type</span>
                        <span className="bs-rv" style={{ textTransform: 'capitalize' }}>{scanResult.item.product_type || '—'}</span>
                      </div>
                    </>
                  ) : scanResult.inventory ? (
                    <>
                      <div className="bs-result-cell">
                        <span className="bs-rk">{lbl.warehouse}</span>
                        <span className="bs-rv">
                          {isTransferMode && fromLocationName
                            ? fromLocationName
                            : (warehouse(scanResult.inventory) || '—')}
                        </span>
                      </div>
                      <div className="bs-result-cell">
                        <span className="bs-rk">Qty (this location)</span>
                        <span className="bs-rv">
                          {qty(scanResult.inventory) !== null ? `${qty(scanResult.inventory)} units` : '—'}
                        </span>
                      </div>
                      <div className="bs-result-cell">
                        <span className="bs-rk">Total Qty (all locations)</span>
                        <span className="bs-rv">
                          {(() => {
                            const total = sumInventoryOnHand(scanResult.inventoryRows);
                            const fallback = qty(scanResult.inventory);
                            const n = total != null ? total : fallback;
                            return n != null ? `${n} units` : '—';
                          })()}
                        </span>
                      </div>
                      <div className="bs-result-cell">
                        <span className="bs-rk">Total Value</span>
                        <span className="bs-rv">₱{((qty(scanResult.inventory) ?? 0) * price(scanResult.item)).toLocaleString()}</span>
                      </div>
                      <div className="bs-result-cell">
                        <span className="bs-rk">Item Type</span>
                        <span className="bs-rv" style={{ textTransform: 'capitalize' }}>{scanResult.item.product_type || '—'}</span>
                      </div>
                    </>
                  ) : null}
                </div>
              </div>
              <p className="bs-timestamp">
                Scanned at {scanResult.scannedAt} — By: {scanResult.scannedBy}
                {scanResult.modeKey === 'issue-out' && scanResult.issuedQuantity != null && (
                  <> — Issued: <strong>{scanResult.issuedQuantity}</strong> unit(s)</>
                )}
              </p>
            </div>
            <div className="bs-action-card">
              <div className="bs-action-head">
                <span className="bs-action-title">Mode Action: {mode.label}</span>
                <span className="bs-action-sub">
                  {mode.key === 'audit'
                    ? 'Read-only: verification is logged when you scan. Inventory is not modified.'
                    : mode.key === 'issue-out' && !scanResult.transactionEngineApplied
                      ? 'Enter how many units to issue. Quantity is checked against available stock, then inventory is reduced by exactly that amount when you tap Issue stock out.'
                      : scanResult.transactionEngineApplied
                        ? 'Stock for this scan was already updated on the server. You do not need to post again.'
                        : mode.key === 'receive-po'
                          ? 'Each accepted scan posts receiving on the server. Execute is not required for normal receiving.'
                          : 'Post transaction using scanned barcode'}
                </span>
              </div>
              {mode.key === 'receive-po' && (
                <div className="bs-receive-po-context" style={{ borderTop: '1px solid #ecfdf5' }}>
                  <p className="bs-action-sub" style={{ padding: '12px 16px 0', margin: 0, color: '#047857' }}>
                    <strong>Location</strong> and <strong>Purchase order</strong> (optional) — same as setup above; keep them here after you scan so you can change them without scrolling up.
                  </p>
                  {receivePoSetupGrid}
                </div>
              )}
              {mode.key !== 'receive-po' && mode.key !== 'audit' ? (
              <div className="bs-action-grid">
                <div className="bs-filter-col">
                  <label>
                    QUANTITY
                    {mode.key === 'issue-out' && scanResult?.inventory && !scanResult.transactionEngineApplied && (
                      <span style={{ fontWeight: 500, color: '#64748b' }}>
                        {' '}(max {availableAtLocation(scanResult.inventory)})
                      </span>
                    )}
                  </label>
                  <input
                    type="number"
                    min="1"
                    max={
                      mode.key === 'issue-out' && scanResult?.inventory && !scanResult.transactionEngineApplied
                        ? availableAtLocation(scanResult.inventory)
                        : undefined
                    }
                    step="1"
                    inputMode="numeric"
                    value={txForm.quantity}
                    onChange={(e) => setTxForm((p) => ({ ...p, quantity: e.target.value }))}
                  />
                </div>
                {(mode.key === 'issue-out') && (
                  <div className="bs-filter-col">
                    <label>CONDITION</label>
                    <select
                      value={txForm.condition}
                      onChange={(e) => setTxForm((p) => ({ ...p, condition: e.target.value }))}
                    >
                      {['Good', 'Fair', 'Damaged', 'Defective'].map((c) => <option key={c} value={c}>{c}</option>)}
                    </select>
                  </div>
                )}
                {(mode.key === 'issue-out' || mode.key === 'adjust') && (
                  <div className="bs-filter-col">
                    <label>LOCATION</label>
                    {isStrictLocation ? (
                      <LocationBadge locationName={enforcedLocationName} locationType={enforcedLocationType} />
                    ) : (
                      <LocationSelector
                        value={txForm.location_id ? Number(txForm.location_id) : null}
                        onChange={(val) => setTxForm((p) => ({ ...p, location_id: val != null ? String(val) : '' }))}
                        allowedLocations={allowedLocations.length ? allowedLocations : locations.map(l => ({ location_id: l.id ?? l.location_id, location_name: l.location_name || l.name, location_type: l.location_type ?? '', location_code: l.location_code ?? '' }))}
                      />
                    )}
                  </div>
                )}
                {mode.key === 'transfer' && transferSubmode === 'out' && (
                  <div className="bs-filter-col">
                    <label>FROM LOCATION (warehouse)</label>
                    {isStrictLocation ? (
                      <LocationBadge locationName={enforcedLocationName} locationType={enforcedLocationType} />
                    ) : (
                      <LocationSelector
                        value={txForm.location_id ? Number(txForm.location_id) : null}
                        onChange={(val) => setTxForm((p) => ({ ...p, location_id: val != null ? String(val) : '' }))}
                        allowedLocations={allowedLocations.length ? allowedLocations : locations.map(l => ({ location_id: l.id ?? l.location_id, location_name: l.location_name || l.name, location_type: l.location_type ?? '', location_code: l.location_code ?? '' }))}
                      />
                    )}
                  </div>
                )}
                {mode.key === 'transfer' && transferSubmode === 'out' && (
                  <div className="bs-filter-col" style={{ gridColumn: '1 / -1' }}>
                    <label style={{ display: 'flex', alignItems: 'center', gap: 8, cursor: 'pointer', fontWeight: 500 }}>
                      <input
                        type="checkbox"
                        checked={!!txForm.ship_transfer_first}
                        onChange={(e) => setTxForm((p) => ({ ...p, ship_transfer_first: e.target.checked }))}
                      />
                      Ship first — showroom must scan to receive (recommended)
                    </label>
                  </div>
                )}
                {mode.key === 'adjust' && (
                  <div className="bs-filter-col">
                    <label>ADJUSTMENT DIRECTION</label>
                    <select
                      value={txForm.adjustment_direction}
                      onChange={(e) => setTxForm((p) => ({ ...p, adjustment_direction: e.target.value }))}
                    >
                      <option value="deduct">Deduct (wrong scan / overcount)</option>
                      <option value="add">Add (missing stock / undercount)</option>
                    </select>
                  </div>
                )}
                {(mode.key === 'issue-out' || (mode.key === 'transfer' && transferSubmode === 'out')) && (
                  <div className="bs-filter-col" style={{ gridColumn: '1 / -1' }}>
                    <p style={{ margin: 0, fontSize: 13, color: '#475569' }}>
                      Available at selected location:{' '}
                      <strong style={{ color: '#0f172a' }}>
                        {scanResult.inventory ? availableAtLocation(scanResult.inventory) : '—'}
                      </strong>
                      {scanResult.inventory ? ' units (issue/transfer cannot exceed this)' : ' — select a location with stock'}
                    </p>
                  </div>
                )}
                {mode.key === 'transfer' && transferSubmode === 'in' && (
                  <div className="bs-filter-col" style={{ gridColumn: '1 / -1' }}>
                    <p style={{ margin: 0, fontSize: 13, color: '#475569' }}>
                      Receiving matches the scanned barcode to this transfer line. If qty at showroom is still 0, receipt will add stock.
                    </p>
                  </div>
                )}
              </div>
              ) : null}
              <div className="bs-action-footer">
                <button
                  type="button"
                  className="bs-action-btn"
                  onClick={executeModeAction}
                  disabled={
                    txSubmitting
                    || scanResult.transactionEngineApplied
                    || mode.key === 'audit'
                  }
                >
                  {txSubmitting
                    ? 'Processing...'
                    : scanResult.transactionEngineApplied
                      ? 'Already posted'
                      : mode.key === 'audit'
                        ? 'Audit — scan only'
                        : mode.key === 'issue-out'
                          ? 'Issue stock out'
                          : (mode.key === 'transfer' && transferSubmode === 'in' ? 'Record showroom receipt' : `Execute ${mode.label}`)}
                </button>
              </div>
              {txLogs.length > 0 && (
                <div className="bs-tx-log">
                  <span className="bs-tx-title">Transaction Success Log</span>
                  <div className="bs-tx-list">
                    {txLogs.map((log) => (
                      <div key={log.id} className="bs-tx-item">
                        <span className="bs-tx-main">{log.mode} · {log.ref}</span>
                        <span className="bs-tx-sub">{log.at} · Qty {log.qty} · {log.location}</span>
                        {log.receivingId && (
                          <button
                            type="button"
                            className="ak-btn ak-btn--ghost ak-btn--sm"
                            style={{ marginTop: 4, alignSelf: 'flex-start' }}
                            onClick={() => openReceipt(log.receivingId)}
                          >
                            View / print receipt
                          </button>
                        )}
                      </div>
                    ))}
                  </div>
                </div>
              )}
            </div>
            </div>
          );
        })()}

        {/* ── session scan history (scan view) ── */}
        {scanHistory.length > 0 && (
          <div className="bs-home-card" style={{ marginTop: 16 }}>
            <div className="bs-home-card-head">
              <span className="bs-home-card-title">Session History</span>
              <span className="bs-home-card-sub">{scanHistory.length} scan{scanHistory.length !== 1 ? 's' : ''} this session</span>
            </div>
            <div className="bs-history-list">
              {scanHistory.map((h, i) => (
                <div
                  key={i}
                  className="bs-history-item"
                  onClick={() => setScanResult(h)}
                  style={{ opacity: scanResult?.barcode === h.barcode && scanResult?.scannedAt === h.scannedAt ? 1 : 0.75 }}
                >
                  <div className="bs-hi-left">
                    <span className="bs-hi-name">{h.item.product_name || h.item.name}</span>
                    <span className="bs-hi-code">{h.barcode}</span>
                    <span className="bs-hi-time">{h.scannedAt}</span>
                  </div>
                  <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'flex-end', gap: 4 }}>
                    <span style={{ fontSize: '0.6rem', fontWeight: 700, color: MODES.find(m => m.key === h.modeKey)?.color || '#6b7280', letterSpacing: '0.04em' }}>
                      {h.mode}
                    </span>
                    {h.inventory ? (() => {
                      const st = getStatusChipDisplay(h.modeKey, h.inventory, h.transactionEngineApplied);
                      return (
                        <span className={`bs-status-chip${st.variant ? ` ${st.variant}` : ''}`}>
                          {st.label}
                        </span>
                      );
                    })() : (
                      <span className="bs-status-chip">—</span>
                    )}
                  </div>
                </div>
              ))}
            </div>
          </div>
        )}

        <Modal
          isOpen={notFoundPromptOpen}
          onClose={() => {
            setNotFoundPromptOpen(false);
            setPendingRegisterBarcode('');
            setTimeout(() => inputRef.current?.focus(), 50);
          }}
          title="Barcode not found"
        >
          <p style={{ marginBottom: 16, color: '#374151', lineHeight: 1.45 }}>
            Barcode not found. Do you want to register this product?
          </p>
          <div className="im-modal-footer" style={{ justifyContent: 'flex-end', gap: 10 }}>
            <button
              type="button"
              className="im-modal-btn-cancel"
              onClick={() => {
                setNotFoundPromptOpen(false);
                setPendingRegisterBarcode('');
                setTimeout(() => inputRef.current?.focus(), 50);
              }}
            >
              Cancel
            </button>
            <button
              type="button"
              className="im-modal-btn-confirm"
              onClick={() => {
                setNotFoundPromptOpen(false);
                setRegisterProductOpen(true);
              }}
            >
              Yes — register product
            </button>
          </div>
        </Modal>

        <ProductRegisterModal
          isOpen={registerProductOpen}
          onClose={() => {
            setRegisterProductOpen(false);
            setPendingRegisterBarcode('');
            setTimeout(() => inputRef.current?.focus(), 50);
          }}
          editingItem={null}
          initialBarcode={pendingRegisterBarcode}
          lockBarcode
          showPricingFields={showPricingFields}
          onSuccess={() => {
            setRegisterProductOpen(false);
            setPendingRegisterBarcode('');
            toast.success('Product saved. Scan the barcode again to receive into stock.');
            setTimeout(() => inputRef.current?.focus(), 50);
          }}
        />
      </div>

      <ReceivingReceipt
        receiving={receiptReceiving}
        isOpen={!!receiptReceiving}
        onClose={() => setReceiptReceiving(null)}
      />
    </AdminLayout>
  );
};

export default BarcodeScan;
