import { useState, useRef, useCallback, useEffect } from 'react';
import {
  BinaryBitmap,
  DecodeHintType,
  HTMLCanvasElementLuminanceSource,
  HybridBinarizer,
  MultiFormatReader,
  NotFoundException,
} from '@zxing/library';
import { Html5Qrcode, Html5QrcodeSupportedFormats } from 'html5-qrcode';
import { toast } from '../utils/toast';

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

const CAMERA_SAME_CODE_MS = 450;

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
  // getUserMedia reports an OS-level block and a browser-level block as the
  // same NotAllowedError, so name both. On Windows a machine-wide "Camera
  // access: off" (or "Let desktop apps access your camera") overrides the
  // browser entirely, and someone hunting through site settings will not find
  // it. Say "site permissions" rather than "the lock icon" — there is no lock
  // on http://localhost, which is a valid secure context for the camera.
  if (name === 'NotAllowedError' || /denied|not allowed|Permission/i.test(msg))
    return 'Camera permission denied. Check this site\'s permissions in your browser '
         + '(the icon at the left of the address bar → Camera → Allow, then reload), '
         + 'and your operating system\'s camera privacy settings — on Windows, '
         + 'Settings → Privacy & security → Camera, including "Let desktop apps access your camera".';
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

// assertCameraEnvironment / cameraAccessErrorMessage are shared with
// components/CameraCapture.jsx so the barcode scanner and the incident camera
// give identical wording for identical failures.
export { playScanFeedback, isLikelyVirtualCameraLabel, assertCameraEnvironment, cameraAccessErrorMessage };

export function useBarcodeCamera(onDecode, { mountId, storageKey }) {
  const [cameraActive, setCameraActive]         = useState(false);
  const [cameraError, setCameraError]           = useState('');
  const [cameraDevices, setCameraDevices]       = useState([]);
  const [selectedCameraId, setSelectedCameraId] = useState(() => {
    try { return localStorage.getItem(storageKey) || ''; } catch { return ''; }
  });

  const html5QrRef  = useRef(null);
  const lastCodeRef = useRef('');
  const lastTimeRef = useRef(0);
  const onDecodeRef = useRef(onDecode);
  useEffect(() => { onDecodeRef.current = onDecode; }, [onDecode]);

  // ── Camera cleanup on unmount ────────────────────────────────
  useEffect(() => () => {
    const h = html5QrRef.current;
    html5QrRef.current = null;
    if (h) { h.stop().catch(() => {}); }
  }, []);

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
    onDecodeRef.current?.(code);
  }, []);

  const startHtml5OnDevice = useCallback(async (deviceId) => {
    await stopReaderAndTracks();
    await new Promise((r) => setTimeout(r, 80));
    const mount = document.getElementById(mountId);
    if (!mount) throw new Error('Scanner mount not found.');
    const h5 = new Html5Qrcode(mountId, {
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
  }, [applyDecodedBarcode, stopReaderAndTracks, mountId]);

  const startCamera = async () => {
    setCameraError('');
    if (!document.getElementById(mountId)) {
      setCameraError('Scanner not ready. Try closing and reopening this page.');
      return;
    }
    try {
      const savedId = localStorage.getItem(storageKey) || '';
      if (savedId) {
        const known = cameraDevices.find((d) => d.deviceId === savedId);
        if (known && isLikelyVirtualCameraLabel(known.label)) {
          localStorage.removeItem(storageKey);
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
      try { localStorage.setItem(storageKey, resolvedId); } catch { /* ignore */ }

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
    try { localStorage.setItem(storageKey, id); } catch { /* ignore */ }
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
    const mountEl = document.getElementById(mountId);
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
    onDecodeRef.current?.(code);
  }, [cameraActive, mountId]);

  const cameraSelectValue = cameraDevices.length > 0
    ? (cameraDevices.some((d) => d.deviceId === selectedCameraId) ? selectedCameraId : cameraDevices[0].deviceId)
    : '';

  return {
    cameraActive,
    cameraError,
    cameraDevices,
    selectedCameraId,
    cameraSelectValue,
    startCamera,
    stopCamera,
    onCameraDeviceChange,
    manualFrameScan,
    isLikelyVirtualCameraLabel,
  };
}
