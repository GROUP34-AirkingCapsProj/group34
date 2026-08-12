import React from 'react';
import ProductPickField from './ProductPickField';

/**
 * Step 2 — where it happened and which product.
 *
 * Three ways in on purpose. Scanning is fastest, but theft and damage are often
 * WHY there is no barcode left to scan, so the picker is always present and a
 * failed lookup never blocks the report. All of that now lives in
 * ProductPickField, because the items step needs the identical control once per
 * physical unit.
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
        <ProductPickField
          label="Product *"
          products={products}
          locationId={locationId}
          productId={productId}
          setProductId={setProductId}
          scanHit={scanHit}
          onScanHit={onScanHit}
          idPrefix="incident-flow"
          disabled={disabled}
        />
      )}
    </>
  );
}
