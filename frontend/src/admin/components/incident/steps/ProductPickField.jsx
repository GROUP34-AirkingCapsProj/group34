import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { incidentReportsAPI } from '../../../services/api';
import CameraScanner from '../../CameraScanner';

/**
 * The three-ways-in product control: scan it, type the barcode, or pick it out
 * of the list.
 *
 * Lifted out of WhereAndWhatStep once the filing flow gained an items step,
 * which needs the same control once per physical unit — a split system is an
 * indoor unit and an outdoor unit with two different stickers, and each is
 * found the same way. Two copies of the lookup would have drifted, and the
 * lookup is the part with the location-scoping subtlety below.
 *
 * All of its state is the MECHANICS of choosing a product (what was typed into
 * the scan box, whether the camera is open, the list filter). Only `productId`
 * and the scan hit leave, so only those are props.
 *
 * `idPrefix` must be unique per instance: it namespaces the input ids, the
 * camera's mount element and the remembered-camera storage key, all three of
 * which would collide if two of these rendered at once.
 */
export default function ProductPickField({
  label = 'Product *',
  products,
  locationId,
  productId,
  setProductId,
  scanHit,
  onScanHit,
  idPrefix = 'incident-flow',
  disabled,
}) {
  const [scanInput, setScanInput] = useState('');
  const [scanning, setScanning] = useState(false);
  const [scanError, setScanError] = useState(null);
  const [camOpen, setCamOpen] = useState(false);   // barcode camera, not the photo camera
  const [productFilter, setProductFilter] = useState('');

  // Held in a ref so the reset effect below depends on the location ALONE. A
  // caller that builds its handler per row (the items step does) would otherwise
  // hand over a new function every render and re-fire the reset in a loop.
  const onScanHitRef = useRef(onScanHit);
  useEffect(() => { onScanHitRef.current = onScanHit; }, [onScanHit]);

  // The lookup is location-scoped, so its verdict expires the moment the
  // location does: "Matched serial SN-4471 — In Stock" was a claim about the OLD
  // location, and leaving it on screen is misinformation on a compliance record.
  // The product is kept — correcting a mis-selected location shouldn't wipe the
  // picker.
  useEffect(() => { onScanHitRef.current?.(null); setScanError(null); }, [locationId]);

  const filteredProducts = useMemo(() => {
    const term = productFilter.trim().toLowerCase();
    if (!term) return products.slice(0, 200);
    return products
      .filter((p) => `${p.product_name || ''} ${p.product_code || ''} ${p.barcode || ''}`
        .toLowerCase().includes(term))
      .slice(0, 200);
  }, [products, productFilter]);

  /**
   * `rawCode` lets the camera pass its decode straight in. Going through
   * setScanInput first would read a stale value from this closure. A click passes
   * an event object, hence the string guard.
   */
  const runLookup = useCallback(async (rawCode) => {
    const code = (typeof rawCode === 'string' ? rawCode : scanInput).trim();
    if (!code) return;
    if (!locationId) { setScanError('Choose a location first.'); return; }
    setScanning(true);
    setScanError(null);
    try {
      const res = await incidentReportsAPI.lookup({ barcode: code, locationId });
      const d = res?.data;
      if (!d?.product?.product_id) throw new Error('No product matches that barcode at this location.');
      // Scanning SETS the product; it does not lock it. A mislabelled barcode is
      // itself a reportable incident (wrong_type), so the picker stays live.
      setProductId(String(d.product.product_id));
      onScanHitRef.current?.({
        matched_by: d.matched_by, serial: d.serial, batches: d.batches || [], product: d.product,
      });
    } catch (err) {
      onScanHitRef.current?.(null);
      setScanError(err?.message || 'No product matches that barcode at this location.');
    } finally {
      setScanning(false);
    }
  }, [scanInput, locationId, setProductId]);

  return (
    <div className="ak-field">
      <label className="ak-label" htmlFor={`${idPrefix}-scan`}>{label}</label>

      <div style={{ display: 'flex', gap: '0.4rem', flexWrap: 'wrap', alignItems: 'center' }}>
        <input
          id={`${idPrefix}-scan`}
          className="ak-input"
          type="text"
          value={scanInput}
          onChange={(e) => setScanInput(e.target.value)}
          onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); runLookup(); } }}
          placeholder="Type a barcode / serial, or use a hardware scanner…"
          style={{ flex: '1 1 200px' }}
          disabled={disabled}
        />
        <button
          type="button"
          className="ak-btn"
          onClick={runLookup}
          disabled={disabled || scanning || !scanInput.trim()}
        >
          {scanning ? 'Finding…' : 'Find'}
        </button>
        <button
          type="button"
          className="ak-btn ak-btn--primary"
          onClick={() => { setScanError(null); setCamOpen((v) => !v); }}
          disabled={disabled || scanning}
        >
          {camOpen ? 'Close camera' : '📷 Scan'}
        </button>
      </div>

      {/* The app's existing barcode camera (html5-qrcode), the same one
          transfer dispatch and receive use — so a label that scans at
          the shelf scans here. Needs a secure context: https:// or
          localhost. The decode is handed straight to runLookup rather
          than routed through state, which would read a stale value. */}
      {camOpen && (
        <div style={{ marginTop: '0.5rem' }}>
          <CameraScanner
            mountId={`${idPrefix}-cam`}
            storageKey="incidentScanCameraId"
            busy={scanning || disabled}
            hint="Point at the product barcode or the unit's serial label."
            onDecode={(code) => {
              setScanInput(code);
              setCamOpen(false);
              runLookup(code);
            }}
          />
        </div>
      )}

      {scanError && (
        <div className="ak-state ak-state--error" style={{ fontSize: '0.82rem', marginTop: '0.35rem' }}>
          {scanError} You can still choose the product below.
        </div>
      )}

      {scanHit?.serial && (
        <div className="ak-muted" style={{ fontSize: '0.82rem', marginTop: '0.35rem' }}>
          Matched serial <strong>{scanHit.serial.serial_number}</strong>
          {' — '}{scanHit.serial.status_label}
          {scanHit.serial.in_stock ? '' : ' (not in stock)'}
        </div>
      )}

      {scanHit && !scanHit.serial && (
        <div className="ak-muted" style={{ fontSize: '0.82rem', marginTop: '0.35rem' }}>
          Matched the product, but not a specific unit — you can name a batch if you know it.
        </div>
      )}

      {/* Always present: theft and damage are often WHY there is no
          barcode left to scan, so a report must never be blocked by a
          missing label. */}
      <input
        className="ak-input"
        type="text"
        value={productFilter}
        onChange={(e) => setProductFilter(e.target.value)}
        placeholder="…or filter the list by name, code or barcode"
        style={{ marginTop: '0.45rem' }}
        disabled={disabled}
      />
      <select
        className="ak-select"
        aria-label={label}
        value={productId}
        style={{ marginTop: '0.4rem' }}
        disabled={disabled}
        onChange={(e) => setProductId(e.target.value)}
      >
        <option value="">Select a product…</option>
        {filteredProducts.map((p) => (
          <option key={p.product_id} value={p.product_id}>
            {p.product_name}{p.product_code ? ` (${p.product_code})` : ''}
          </option>
        ))}
      </select>
    </div>
  );
}
