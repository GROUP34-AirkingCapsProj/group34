import { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
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
  itemsAPI, productsAPI, inventoryAPI, categoriesAPI, brandsAPI, modelsAPI,
  receivingsAPI, issuancesAPI, transfersAPI, adjustmentsAPI, barcodeScanAPI, locationsAPI, purchaseOrdersAPI,
} from '../services/api';
import { toast } from '../utils/toast';
import { useAuth } from '../context/AuthContext';
import { getApprovalQueuePurchaseOrders, APPROVAL_QUEUE_STORAGE_KEY } from '../utils/approvalNotifications';
import { getRoleKey, ROLES } from '../utils/roles';
import '../styles/barcode_scan.css';
import '../styles/item_management.css';

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
    key: 'transfer',
    label: 'Transfer',
    apiLabel: 'TRANSFER',
    color: '#f59e0b',
    bg: '#fffbeb',
    icon: (
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="28" height="28">
        <path d="M17 3l4 4-4 4"/><path d="M3 7h18"/>
        <path d="M7 21l-4-4 4-4"/><path d="M21 17H3"/>
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
    const label = new Date(expMs).toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' });
    return `Receiving is blocked until the expected arrival date (${label}, in ${days} day(s)).`;
  }
  return null;
};

/** Merge API PO list with locally synced approved POs (so Receive PO shows them even if GET lags or misses a row). */
const mergeSyncedLocalPurchaseOrders = (apiRows) => {
  const list = Array.isArray(apiRows) ? [...apiRows] : [];
  const byId = new Map(
    list
      .map((r) => [String(r.po_id ?? r.id ?? ''), r])
      .filter(([k]) => k && k !== 'undefined'),
  );
  try {
    getApprovalQueuePurchaseOrders().forEach((p) => {
      const st = String(p.status || '').toLowerCase();
      if (st !== 'authorized' || !p.synced_to_api) return;
      const bid = p.backend_po_id ?? p.po_id;
      if (bid == null || bid === '') return;
      const sid = String(bid);
      if (byId.has(sid)) return;
      byId.set(sid, {
        po_id: Number(bid),
        po_number: p.po_number,
        expected_delivery_date: p.expected_delivery_date ?? null,
        status: { status_name: 'Authorized' },
        supplier: typeof p.supplier === 'string' ? { supplier_name: p.supplier } : (p.supplier || null),
      });
    });
  } catch {
    /* ignore */
  }
  return Array.from(byId.values());
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
  const { user: _user } = useAuth();
  const roleKey = useMemo(() => getRoleKey(_user || {}), [_user]);
  const isAdminRole = roleKey === ROLES.ADMIN;
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
  const [models,         setModels]         = useState([]);
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
  const [transferSubmode, setTransferSubmode] = useState('out'); // 'out' = ship from warehouse, 'in' = receive at showroom
  const [inTransitTransfers, setInTransitTransfers] = useState([]);
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

  const showPricingFields = roleKey === ROLES.ADMIN || roleKey === ROLES.BRANCH_MANAGER;
  const [notFoundPromptOpen, setNotFoundPromptOpen] = useState(false);
  const [pendingRegisterBarcode, setPendingRegisterBarcode] = useState('');
  const [registerProductOpen, setRegisterProductOpen] = useState(false);

  const transferDestinationLocations = useMemo(
    () => sortLocationsCarmenFirst(locations),
    [locations],
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

  /* ── load filter data ── */
  useEffect(() => {
    categoriesAPI.getAll().then((r) => setCategories(Array.isArray(r.data) ? r.data : [])).catch(() => {});
    brandsAPI.getAll().then((r)     => setBrands(Array.isArray(r.data) ? r.data : [])).catch(() => {});
    modelsAPI.getAll({ per_page: 200 }).then((r) => setModels(Array.isArray(r.data) ? r.data : [])).catch(() => {});
    locationsAPI
      .getAll({ per_page: 500 })
      .then((r) => setLocations(extractApiDataArray(r)))
      .catch((e) => {
        toast.error(e?.message || 'Could not load locations. Check API URL and network.');
      });
  }, []);

  useEffect(() => {
    loadPurchaseOrders();
    const onApproval = () => loadPurchaseOrders();
    window.addEventListener('approval-notifications-updated', onApproval);
    const onStorage = (e) => {
      if (e.key === APPROVAL_QUEUE_STORAGE_KEY) loadPurchaseOrders();
    };
    window.addEventListener('storage', onStorage);
    return () => {
      window.removeEventListener('approval-notifications-updated', onApproval);
      window.removeEventListener('storage', onStorage);
    };
  }, [loadPurchaseOrders]);

  const effectiveModeKey = (scanMode || MODES[modeIndex])?.key;
  useEffect(() => {
    if (effectiveModeKey !== 'receive-po') return;
    loadPurchaseOrders();
  }, [effectiveModeKey, loadPurchaseOrders]);

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
          : modeEarly?.key === 'transfer' && transferSubmode === 'out'
            ? 'transfer'
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
        if (engineTransactionType === 'transfer') {
          if (!txForm.location_id || !txForm.to_location_id) {
            toast.error('Select source and destination locations before scanning.');
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
        if (engineTransactionType === 'transfer') {
          body.from_location_id = Number(txForm.location_id);
          body.to_location_id = Number(txForm.to_location_id);
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
            if (engineTransactionType === 'receiving') loadPurchaseOrders();
            if (engineTransactionType === 'transfer') {
              transfersAPI.getAll({ per_page: 100 }).then((r) => {
                setInTransitTransfers(Array.isArray(r?.data) ? r.data : []);
              }).catch(() => {});
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

  useEffect(() => { handleScanRef.current = handleScan; }, [handleScan]);
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
  };

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

  useEffect(() => {
    if (currentModeKey !== 'transfer') {
      setTransferSubmode('out');
      return;
    }
    transfersAPI.getAll({ per_page: 100 }).then((r) => {
      setInTransitTransfers(Array.isArray(r?.data) ? r.data : []);
    }).catch(() => setInTransitTransfers([]));
  }, [currentModeKey]);

  const transfersToReceive = useMemo(() => {
    if (!txForm.to_location_id) return [];
    return inTransitTransfers.filter(
      (t) => t.status?.status_name === 'In Transit' && String(t.to_location_id) === String(txForm.to_location_id),
    );
  }, [inTransitTransfers, txForm.to_location_id]);

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
    const isTransferIn = mode.key === 'transfer' && transferSubmode === 'in';
    if ((mode.key === 'issue-out' || (mode.key === 'transfer' && !isTransferIn))) {
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
      } else if (mode.key === 'transfer' && isTransferIn) {
        if (!txForm.to_location_id) {
          toast.error('Select the showroom (destination) where stock is arriving.');
          return;
        }
        if (!txForm.pending_transfer_id) {
          toast.error('Select the in-transit transfer to receive against.');
          return;
        }
        const res = await transfersAPI.receive(txForm.pending_transfer_id, {
          barcode: scanResult.barcode,
          quantity: qtyNum,
        });
        toast.success('Showroom receipt recorded — destination stock updated.');
        setTxLogs((prev) => [{
          id: Date.now(),
          mode: `${mode.label} (IN)`,
          ref: res?.data?.transfer_number || res?.data?.transfer_id || 'Received',
          barcode: scanResult.barcode,
          qty: qtyNum,
          location: getLocationName(txForm.to_location_id),
          at: new Date().toLocaleTimeString(),
        }, ...prev].slice(0, 8));
        try {
          const trRes = await transfersAPI.getAll({ per_page: 100 });
          setInTransitTransfers(Array.isArray(trRes?.data) ? trRes.data : []);
        } catch { /* ignore */ }
      } else if (mode.key === 'transfer') {
        if (!locationId) {
          toast.error('Select source location.');
          return;
        }
        if (!txForm.to_location_id) {
          toast.error('Select destination showroom/location.');
          return;
        }
        if (String(locationId) === String(txForm.to_location_id)) {
          toast.error('From and To locations must be different.');
          return;
        }
        const res = await transfersAPI.create({
          from_location_id: locationId,
          to_location_id: txForm.to_location_id,
          transfer_number: `TR-${Date.now()}`,
          transfer_date: today,
          ship_only: !!txForm.ship_transfer_first,
          details: [
            {
              product_id: productId,
              quantity_transferred: qtyNum,
            },
          ],
        });
        toast.success(txForm.ship_transfer_first
          ? 'Transfer shipped — showroom must scan to receive stock at destination.'
          : 'Transfer posted successfully.');
        setTxLogs((prev) => [{
          id: Date.now(),
          mode: mode.label,
          ref: res?.data?.transfer_number || res?.transfer_number || 'Posted',
          barcode: scanResult.barcode,
          qty: qtyNum,
          location: `${getLocationName(locationId)} -> ${getLocationName(txForm.to_location_id)}`,
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
      if (mode.key === 'transfer' && !isTransferIn) {
        try {
          const trRes = await transfersAPI.getAll({ per_page: 100 });
          setInTransitTransfers(Array.isArray(trRes?.data) ? trRes.data : []);
        } catch { /* ignore */ }
      }
    } catch (err) {
      toast.error(err.message || 'Failed to execute inventory action.');
    } finally {
      setTxSubmitting(false);
    }
  }, [scanResult, scanMode, modeIndex, txForm, scannedByName, getLocationName, mergeFreshInventoryForSession, reloadRecentMovement, loadPurchaseOrders, transferSubmode, purchaseOrders, receivePoProductIds]);

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
        <select
          value={txForm.location_id}
          onChange={(e) => setTxForm((p) => ({ ...p, location_id: e.target.value }))}
        >
          <option value="">Select</option>
          {locations.map((l) => (
            <option key={l.id ?? l.location_id} value={l.id ?? l.location_id}>
              {l.location_name || l.name}
            </option>
          ))}
        </select>
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
        <select
          className={!txForm.location_id ? 'bs-select-invalid' : undefined}
          value={txForm.location_id}
          onChange={(e) => setTxForm((p) => ({ ...p, location_id: e.target.value }))}
          aria-invalid={!txForm.location_id}
        >
          <option value="">Select</option>
          {locations.map((l) => (
            <option key={l.id ?? l.location_id} value={l.id ?? l.location_id}>
              {l.location_name || l.name}
            </option>
          ))}
        </select>
        {!txForm.location_id && (
          <p className="bs-field-hint bs-field-hint--error">Required before scanning — choose where stock is issued from.</p>
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
        <div className="bs-page">
          <div className="bs-page-header">
            <h1>Inventory Operation</h1>
            <p>Scan barcode or QR code to view item details and perform inventory lookups</p>
          </div>

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
                <button className="bs-viewall" type="button">VIEW ALL</button>
              </div>
              <div className="bs-movement-list">
                {recentMovement.map((m, i) => {
                  const ref = m.receiving_code || m.issuance_code || m.transfer_code || m.reference_number || `#${m.id || i}`;
                  const label = `${m._type} ${ref}`.trim();
                  const when  = m.created_at
                    ? new Date(m.created_at).toLocaleString()
                    : m.transaction_date || '';
                  return (
                    <div key={i} className="bs-movement-item">
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
     RENDER: SCAN VIEW
  ───────────────────────────────────────────── */
  return (
    <AdminLayout>
      <div className="bs-page">
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

          {cameraActive && (
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
                <select
                  value={txForm.location_id}
                  onChange={(e) => setTxForm((p) => ({ ...p, location_id: e.target.value }))}
                >
                  <option value="">Select</option>
                  {locations.map((l) => (
                    <option key={l.id ?? l.location_id} value={l.id ?? l.location_id}>
                      {l.location_name || l.name}
                    </option>
                  ))}
                </select>
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
          <button className={`bs-type-btn${activeType === 'consumable' ? ' active' : ''}`} onClick={() => setActiveType('consumable')} type="button">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="13" height="13"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
            PART / CONSUMABLE
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
            <button type="button" className="btn-link" style={{ padding: 0, marginTop: 10, fontSize: 13 }} onClick={() => navigate('/admin/transfer-scan')}>
              Open full checklist (print DR) →
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
            <select value={filterModel} onChange={(e) => setFilterModel(e.target.value)}>
              <option value="">All</option>
              {models.map((m) => <option key={m.model_id ?? m.id} value={m.model_id ?? m.id}>{m.model_name ?? m.name}</option>)}
            </select>
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
                    <span className="bs-rk">SKU</span>
                    <span className="bs-rv">{scanResult.item.product_code || scanResult.item.code || '—'}</span>
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
                      {scanResult.item.brand?.brand_name
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
                    <select
                      value={txForm.location_id}
                      onChange={(e) => setTxForm((p) => ({ ...p, location_id: e.target.value }))}
                    >
                      <option value="">Select</option>
                      {locations.map((l) => (
                        <option key={l.id ?? l.location_id} value={l.id ?? l.location_id}>
                          {l.location_name || l.name}
                        </option>
                      ))}
                    </select>
                  </div>
                )}
                {mode.key === 'transfer' && transferSubmode === 'out' && (
                  <div className="bs-filter-col">
                    <label>FROM LOCATION (warehouse)</label>
                    <select
                      value={txForm.location_id}
                      onChange={(e) => setTxForm((p) => ({ ...p, location_id: e.target.value }))}
                    >
                      <option value="">Select</option>
                      {locations.map((l) => (
                        <option key={l.id ?? l.location_id} value={l.id ?? l.location_id}>
                          {l.location_name || l.name}
                        </option>
                      ))}
                    </select>
                  </div>
                )}
                {mode.key === 'transfer' && (
                  <div className="bs-filter-col">
                    <label>{transferSubmode === 'in' ? 'SHOWROOM (receiving here)' : 'TO SHOWROOM / LOCATION'}</label>
                    <select
                      value={txForm.to_location_id}
                      onChange={(e) => setTxForm((p) => ({ ...p, to_location_id: e.target.value, pending_transfer_id: '' }))}
                    >
                      <option value="">Select showroom / location</option>
                      {transferDestinationLocations.map((l) => (
                        <option key={l.id ?? l.location_id} value={l.id ?? l.location_id}>
                          {l.location_name || l.name}
                        </option>
                      ))}
                    </select>
                  </div>
                )}
                {mode.key === 'transfer' && transferSubmode === 'in' && (
                  <div className="bs-filter-col" style={{ gridColumn: '1 / -1' }}>
                    <label>IN-TRANSIT TRANSFER #</label>
                    <select
                      value={txForm.pending_transfer_id}
                      onChange={(e) => setTxForm((p) => ({ ...p, pending_transfer_id: e.target.value }))}
                    >
                      <option value="">Select transfer (In Transit)</option>
                      {transfersToReceive.map((t) => (
                        <option key={t.transfer_id} value={t.transfer_id}>
                          {t.transfer_number} — {t.from_location?.location_name || '—'} → {t.to_location?.location_name || '—'}
                        </option>
                      ))}
                    </select>
                    {transfersToReceive.length === 0 && txForm.to_location_id && (
                      <p style={{ margin: '6px 0 0', fontSize: 12, color: '#b45309' }}>No in-transit transfers for this showroom. Create one using Transfer OUT (with Ship first) or Stock Transfer.</p>
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
    </AdminLayout>
  );
};

export default BarcodeScan;
