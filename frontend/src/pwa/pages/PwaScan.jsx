import { useCallback, useEffect, useRef, useState } from 'react';
import { Html5Qrcode, Html5QrcodeSupportedFormats } from 'html5-qrcode';
import { useSupabaseAuth } from '../context/SupabaseAuthContext';
import { enqueuePendingScan, listPendingScans, removePendingScan } from '../utils/offlineScanQueue';
import '../styles/pwa.css';

const READER_ID = 'pwa-qr-reader';
const DEBOUNCE_MS = 1400;

const FORMATS = [
  Html5QrcodeSupportedFormats.EAN_13,
  Html5QrcodeSupportedFormats.EAN_8,
  Html5QrcodeSupportedFormats.CODE_128,
  Html5QrcodeSupportedFormats.CODE_39,
  Html5QrcodeSupportedFormats.UPC_A,
  Html5QrcodeSupportedFormats.UPC_E,
  Html5QrcodeSupportedFormats.QR_CODE,
];

export default function PwaScan() {
  const { client, user } = useSupabaseAuth();
  const [cameraOn, setCameraOn] = useState(false);
  const [busy, setBusy] = useState(false);
  const [lastMessage, setLastMessage] = useState(null);
  const [error, setError] = useState('');
  const scannerRef = useRef(null);
  const lastScanRef = useRef({ code: '', at: 0 });

  const ingestBarcode = useCallback(
    async (rawCode, { silent } = {}) => {
      const code = String(rawCode || '').trim();
      if (!code || !client || !user) return false;

      if (!navigator.onLine) {
        enqueuePendingScan({ barcode: code, createdAt: new Date().toISOString() });
        if (!silent) setLastMessage({ type: 'queued', code });
        return true;
      }

      if (!silent) {
        setBusy(true);
        setError('');
      }
      try {
        const { data: existing, error: selErr } = await client
          .from('products')
          .select('*')
          .eq('barcode', code)
          .maybeSingle();
        if (selErr) throw selErr;

        if (existing) {
          await client.from('scan_events').insert({
            barcode: code,
            product_id: existing.id,
            action: 'found',
            user_id: user.id,
          });
          if (!silent) setLastMessage({ type: 'found', product: existing });
          return true;
        }

        const insertRow = {
          name: `Item ${code}`,
          barcode: code,
          quantity: 0,
          price: 0,
          category: 'Uncategorized',
        };
        const { data: inserted, error: insErr } = await client.from('products').insert(insertRow).select().single();
        if (insErr) {
          if (insErr.code === '23505') {
            const { data: again } = await client.from('products').select('*').eq('barcode', code).maybeSingle();
            if (again) {
              await client.from('scan_events').insert({
                barcode: code,
                product_id: again.id,
                action: 'found',
                user_id: user.id,
              });
              if (!silent) setLastMessage({ type: 'found', product: again });
              return true;
            }
          }
          throw insErr;
        }

        await client.from('scan_events').insert({
          barcode: code,
          product_id: inserted.id,
          action: 'created',
          user_id: user.id,
        });
        if (!silent) setLastMessage({ type: 'created', product: inserted });
        return true;
      } catch (e) {
        if (!silent) {
          setError(e.message || 'Scan failed.');
          setLastMessage(null);
        }
        return false;
      } finally {
        if (!silent) setBusy(false);
      }
    },
    [client, user]
  );

  const onCameraDecode = useCallback(
    (decoded) => {
      const code = String(decoded || '').trim();
      if (!code) return;
      const now = Date.now();
      if (code === lastScanRef.current.code && now - lastScanRef.current.at < DEBOUNCE_MS) return;
      lastScanRef.current = { code, at: now };
      ingestBarcode(code, { silent: false });
    },
    [ingestBarcode]
  );

  const flushOfflineQueue = useCallback(async () => {
    if (!navigator.onLine || !client || !user) return;
    const pending = listPendingScans();
    for (const item of pending) {
      const ok = await ingestBarcode(item.barcode, { silent: true });
      if (ok) removePendingScan(item.id);
      else break;
    }
  }, [client, user, ingestBarcode]);

  useEffect(() => {
    flushOfflineQueue();
  }, [flushOfflineQueue]);

  const startCamera = async () => {
    setError('');
    if (scannerRef.current) return;
    const html5 = new Html5Qrcode(READER_ID, { formatsToSupport: FORMATS, verbose: false });
    scannerRef.current = html5;
    try {
      await html5.start(
        { facingMode: 'environment' },
        {
          fps: 10,
          qrbox: (w, h) => {
            const m = Math.min(w, h);
            return { width: Math.floor(m * 0.9), height: Math.floor(m * 0.35) };
          },
        },
        onCameraDecode,
        () => {}
      );
      setCameraOn(true);
    } catch (e) {
      scannerRef.current = null;
      setError(e.message || 'Camera failed. Allow permission or use HTTPS.');
    }
  };

  const stopCamera = async () => {
    const s = scannerRef.current;
    scannerRef.current = null;
    if (!s) {
      setCameraOn(false);
      return;
    }
    try {
      await s.stop();
      await s.clear();
    } catch {
      /* ignore */
    }
    setCameraOn(false);
  };

  useEffect(() => {
    return () => {
      const s = scannerRef.current;
      scannerRef.current = null;
      if (s) {
        s.stop().catch(() => {});
        s.clear().catch(() => {});
      }
    };
  }, []);

  return (
    <div>
      <h1 style={{ fontSize: '1.25rem', margin: '0 0 16px', color: '#111827' }}>Barcode scan</h1>
      <p className="pwa-muted" style={{ marginBottom: 12 }}>
        Point the camera at a barcode. Unknown codes create a product automatically with default values.
      </p>

      {error ? <div className="pwa-login-error">{error}</div> : null}

      <div className="pwa-scan-reader-wrap">
        <div id={READER_ID} style={{ minHeight: 220 }} />
      </div>

      <div style={{ display: 'flex', gap: 10 }}>
        {!cameraOn ? (
          <button type="button" className="pwa-btn pwa-btn-primary" onClick={startCamera} disabled={busy}>
            Start camera
          </button>
        ) : (
          <button type="button" className="pwa-btn pwa-btn-secondary" onClick={stopCamera}>
            Stop camera
          </button>
        )}
      </div>

      {busy ? <p className="pwa-muted" style={{ marginTop: 10 }}>Processing…</p> : null}

      {lastMessage ? (
        <div className={`pwa-scan-result ${lastMessage.type === 'created' || lastMessage.type === 'queued' ? 'new' : ''}`}>
          {lastMessage.type === 'found' && lastMessage.product ? (
            <>
              <strong>Found</strong> — {lastMessage.product.name}
              <div className="pwa-muted" style={{ marginTop: 6 }}>
                Qty {lastMessage.product.quantity} · ₱{Number(lastMessage.product.price).toFixed(2)} ·{' '}
                {lastMessage.product.category}
              </div>
            </>
          ) : null}
          {lastMessage.type === 'created' && lastMessage.product ? (
            <>
              <strong>Registered</strong> — {lastMessage.product.name}
              <div className="pwa-muted" style={{ marginTop: 6 }}>
                Edit details under Products. Barcode {lastMessage.product.barcode}
              </div>
            </>
          ) : null}
          {lastMessage.type === 'queued' ? (
            <>
              <strong>Offline</strong> — saved “{lastMessage.code}” to sync when you are back online.
            </>
          ) : null}
        </div>
      ) : null}
    </div>
  );
}
