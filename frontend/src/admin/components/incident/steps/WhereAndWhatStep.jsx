import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { incidentReportsAPI } from '../../../services/api';
import CameraScanner from '../../CameraScanner';

/**
 * Step 2 — where it happened and which product.
 *
 * Three ways in on purpose. Scanning is fastest, but theft and damage are often
 * WHY there is no barcode left to scan, so the picker below is always present
 * and a failed lookup never blocks the report — it shows the error and leaves
 * the picker usable.
 *
 * Product is hidden entirely when the reason moves no stock (`other`).
 */
export default function WhereAndWhatStep({
  locations, products,
  locationId, setLocationId,
  productId, setProductId,
  needsStock, scanHit, onScanHit,
  disabled,
}) {
  // None of this is part of the submitted payload — it is the mechanics of
  // choosing a product, so it lives with the picker rather than in the flow.
  const [scanInput, setScanInput] = useState('');
  const [scanning, setScanning] = useState(false);
  const [scanError, setScanError] = useState(null);
  const [camOpen, setCamOpen] = useState(false);   // barcode camera, not the photo camera
  const [productFilter, setProductFilter] = useState('');

  // The lookup is location-scoped, so its verdict expires the moment the location
  // does: "Matched serial SN-4471 — In Stock" was a claim about the OLD location,
  // and leaving it on screen is misinformation on a compliance record. The
  // product is kept — correcting a mis-selected location shouldn't wipe the picker.
  useEffect(() => { onScanHit(null); setScanError(null); }, [locationId, onScanHit]);

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
      onScanHit({ matched_by: d.matched_by, serial: d.serial, batches: d.batches || [], product: d.product });
    } catch (err) {
      onScanHit(null);
      setScanError(err?.message || 'No product matches that barcode at this location.');
    } finally {
      setScanning(false);
    }
  }, [scanInput, locationId, setProductId, onScanHit]);

  return (
    <>
      <div className="ak-field">
        <label className="ak-label" htmlFor="incident-flow-location">Location *</label>
        <select
          id="incident-flow-location"
          className="ak-select"
          value={locationId}
          disabled={disabled}
          onChange={(e) => setLocationId(e.target.value)}
        >
          <option value="">Select a location…</option>
          {locations.map((l) => (
            <option key={l.location_id ?? l.id} value={l.location_id ?? l.id}>
              {l.location_name || l.name}
            </option>
          ))}
        </select>
      </div>

      {needsStock && (
        <div className="ak-field">
          <label className="ak-label" htmlFor="incident-flow-scan">Product *</label>

          <div style={{ display: 'flex', gap: '0.4rem', flexWrap: 'wrap', alignItems: 'center' }}>
            <input
              id="incident-flow-scan"
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
                mountId="incident-scan-cam"
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
              Matched the product, but not a specific unit — you can name a batch on the next step if you know it.
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
      )}
    </>
  );
}
