import React, { useCallback, useMemo, useState } from 'react';
import DocumentSection, { DocField } from './DocumentSection';
import ProductPickField from '../steps/ProductPickField';
import BatchSelect from '../steps/BatchSelect';

/**
 * The lean block a zone mismatch gets instead of the units list.
 *
 * A zone mismatch creates no `incident_items` — it mirrors
 * IncidentService::INSPECTION_EXEMPT_REASONS — because nothing is quarantined:
 * the record is right and the item is simply in the wrong aisle. There are no
 * physical units to enumerate, inspect or settle, so asking for a units list
 * would be a page of boxes that go nowhere.
 *
 * What it does need is which batch is in the wrong place and where it actually
 * is, so somebody can go and move it back.
 */
export default function StockSection({
  state, form, patch, products, batches, batchesLoading, disabled,
}) {
  const readOnly = state !== 'editable';
  const locked = disabled || readOnly;
  const [scanHit, setScanHit] = useState(null);

  const setProductId = useCallback((v) => patch({ productId: v }), [patch]);
  const handleScanHit = useCallback((hit) => setScanHit(hit), []);

  const { productId, locationId } = form;
  const availableBatches = useMemo(() => {
    if (!Array.isArray(batches) || !productId) return [];
    return batches.filter((b) => String(b.product_id) === String(productId)
      && (!locationId || String(b.location_id) === String(locationId)));
  }, [batches, productId, locationId]);

  const selected = availableBatches.find((b) => String(b.batch_id) === String(form.batchId));

  return (
    <DocumentSection
      id="incdoc-stock"
      title="What and where"
      state={state}
      hint="Nothing is quarantined and nothing moves in the system — the record is right and the goods are physically in the wrong place. Reporting it tells someone to go and move them back."
    >
      <ProductPickField
        label="Product *"
        products={products}
        locationId={form.locationId}
        productId={form.productId}
        setProductId={setProductId}
        scanHit={scanHit}
        onScanHit={handleScanHit}
        idPrefix="incdoc-stock"
        disabled={locked}
      />

      <BatchSelect
        label="Which batch is in the wrong place? *"
        emptyLabel="Choose a batch…"
        batchId={form.batchId}
        setBatchId={(v) => patch({ batchId: v })}
        availableBatches={availableBatches}
        batchesLoading={batchesLoading}
        productId={form.productId}
        fieldId="incdoc-stock-batch"
        disabled={locked}
        note={selected?.zone_label ? (
          <span className="ak-muted" style={{ fontSize: '0.8rem' }}>
            The record says this batch is in <strong>{selected.zone_label}</strong>.
          </span>
        ) : null}
      />

      <DocField
        id="incdoc-actual-zone"
        label="Where is it actually? *"
        maxLength={100}
        placeholder="e.g. Aisle B, rack 3"
        value={form.actualZone}
        onChange={(v) => patch({ actualZone: v })}
        disabled={locked}
      />
    </DocumentSection>
  );
}
