import React, { useCallback, useMemo, useState } from 'react';
import DocumentSection, { DocField, DocSelect } from './DocumentSection';
import ProductPickField from '../steps/ProductPickField';
import QuantityInPieces from '../steps/QuantityInPieces';
import BatchSelect from '../steps/BatchSelect';
import { UNIT_ROLES } from '../../../utils/incidentItems';
import { packingOf, piecesOnRow } from '../../../utils/incidentUnits';

/**
 * The physical things one report is about — the client's "Model ID / Serial /
 * DOR, Model OD / Serial" block.
 *
 * A report is not one product and one number: a split-system aircon is an
 * indoor unit and an outdoor unit — two model codes, two serials, one incident
 * — and the inspector may well scrap one half and restock the other. So the
 * payload carries a row per unit and the backend quarantines, inspects and
 * settles each independently.
 *
 * Unlike the wizard this replaces, unit 1 is fully editable HERE and nowhere
 * else. The wizard collected it two steps earlier and re-displayed it read-only
 * to avoid two editable copies of one value; on a single sheet there is only
 * one place to put it, so the flat `product_id`/`batch_id`/`quantity` on the
 * payload are simply read back off unit 1 when the document is submitted.
 *
 * `model_code` and `serial_number` are free text stored ALONGSIDE the product
 * and serial foreign keys, not derived from them: the sticker on a defective
 * unit may match nothing in the catalogue, and that mismatch is very often the
 * incident itself.
 */
export default function UnitsSection({
  state, form, patch, patchUnit, addUnit, removeUnit,
  products, batches, batchesLoading, disabled,
}) {
  const readOnly = state !== 'editable';
  const locked = disabled || readOnly;

  return (
    <DocumentSection
      id="incdoc-units"
      title="Units"
      state={state}
      hint="One row per physical unit. A split system is two — an indoor unit and an outdoor unit — and each is inspected and settled on its own, so one half can be scrapped while the other goes back into stock."
    >
      <DocField
        id="incdoc-received-dor"
        label="Date received (DOR)"
        type="date"
        value={form.receivedAtDor}
        onChange={(v) => patch({ receivedAtDor: v })}
        disabled={locked}
        hint="The delivery receipt this stock arrived on. One date for the whole report."
      />

      {form.units.map((row, i) => (
        <UnitRow
          key={row.uid}
          row={row}
          index={i + 1}
          onlyUnit={form.units.length === 1}
          returnReasonCode={form.returnReasonCode}
          locationId={form.locationId}
          products={products}
          batches={batches}
          batchesLoading={batchesLoading}
          onPatch={patchUnit}
          onRemove={removeUnit}
          disabled={locked}
        />
      ))}

      {!readOnly && (
        <button type="button" className="ak-btn" onClick={addUnit} disabled={disabled}>
          + Add unit
        </button>
      )}
    </DocumentSection>
  );
}

function UnitRow({
  row, index, onlyUnit, returnReasonCode, locationId,
  products, batches, batchesLoading, onPatch, onRemove, disabled,
}) {
  const { uid, product_id: productId } = row;
  const [scanHit, setScanHit] = useState(null);

  // Stable per row: ProductPickField resets its hit whenever the location
  // changes, and an inline arrow would make that effect re-fire every render.
  const setProductId = useCallback((v) => onPatch(uid, { product_id: v }), [onPatch, uid]);

  const handleScanHit = useCallback((hit) => {
    setScanHit(hit);
    if (!hit) return;
    // A scan already read the sticker — prefill rather than make the reporter
    // copy it out by hand, and bind the serial FK while we have it. Only the
    // sticker fields are guarded against overwriting, because a correction
    // typed before scanning is a deliberate answer; serial_id is an id, not an
    // answer, so it always follows the scan.
    const p = {};
    if (hit.serial?.serial_number) p.serial_number = hit.serial.serial_number;
    if (hit.product?.product_code) p.model_code = hit.product.product_code;
    p.serial_id = hit.serial?.serial_id ?? null;
    onPatch(uid, p);
  }, [onPatch, uid]);

  const product = products.find((x) => String(x.product_id) === String(productId));
  const { piecesPerPackage, usesPacks, packageUnit } = packingOf(product);
  const totalPieces = piecesOnRow(row, product);

  const availableBatches = useMemo(() => {
    if (!Array.isArray(batches) || !productId) return [];
    return batches.filter((b) => String(b.product_id) === String(productId)
      && (!locationId || String(b.location_id) === String(locationId)));
  }, [batches, productId, locationId]);

  return (
    <fieldset className="incdoc__unit">
      <legend className="incdoc__unit-legend">Unit {index}</legend>

      <ProductPickField
        label={`Product for unit ${index} *`}
        products={products}
        locationId={locationId}
        productId={productId}
        setProductId={setProductId}
        scanHit={scanHit}
        onScanHit={handleScanHit}
        idPrefix={`incdoc-unit-${uid}`}
        disabled={disabled}
      />

      <div className="incdoc__row">
        <DocSelect
          id={`incdoc-unit-role-${uid}`}
          label="Unit"
          value={row.unit_role}
          onChange={(v) => onPatch(uid, { unit_role: v })}
          options={UNIT_ROLES}
          disabled={disabled}
        />
        <DocField
          id={`incdoc-unit-model-${uid}`}
          label="Model code on the unit"
          maxLength={100}
          placeholder="As printed on the sticker"
          value={row.model_code}
          onChange={(v) => onPatch(uid, { model_code: v })}
          disabled={disabled}
        />
        <DocField
          id={`incdoc-unit-serial-${uid}`}
          label="Serial number on the unit"
          maxLength={100}
          placeholder="As printed on the sticker"
          value={row.serial_number}
          onChange={(v) => onPatch(uid, { serial_number: v })}
          disabled={disabled}
        />
      </div>

      <QuantityInPieces
        label="Quantity"
        packs={row.packs} setPacks={(v) => onPatch(uid, { packs: v })}
        loosePieces={row.loose_pieces} setLoosePieces={(v) => onPatch(uid, { loose_pieces: v })}
        quantity={row.quantity} setQuantity={(v) => onPatch(uid, { quantity: v })}
        usesPacks={usesPacks} piecesPerPackage={piecesPerPackage}
        packageUnit={packageUnit} totalPieces={totalPieces}
        packNote="Stock is recorded in pieces."
        fieldId={`incdoc-unit-qty-${uid}`}
        disabled={disabled}
      />

      <BatchSelect
        label="Batch"
        emptyLabel="Not batch-specific"
        batchId={row.batch_id} setBatchId={(v) => onPatch(uid, { batch_id: v })}
        availableBatches={availableBatches} batchesLoading={batchesLoading}
        productId={productId}
        fieldId={`incdoc-unit-batch-${uid}`}
        disabled={disabled}
      />

      {/*
        The wrong/correct pair. Recorded and printed; nothing is raised from it
        — getting the right unit to the customer is a normal issuance someone
        raises separately, and a report that silently created one would move
        stock nobody agreed to.
      */}
      {returnReasonCode === 'wrong_product' && (
        <DocSelect
          id={`incdoc-unit-expected-product-${uid}`}
          label="What should have been delivered? *"
          value={row.expected_product_id}
          onChange={(v) => onPatch(uid, { expected_product_id: v })}
          placeholder="Select the correct product…"
          /* A plain picker, not the scan control above: the correct product was
             never delivered, so there is no sticker here to scan. */
          options={products.map((p) => ({
            value: String(p.product_id),
            label: `${p.product_name}${p.product_code ? ` (${p.product_code})` : ''}`,
          }))}
          disabled={disabled}
          hint="Recorded on the report. Nothing is shipped from it — raise the replacement issuance separately."
        />
      )}

      {returnReasonCode === 'wrong_quantity' && (
        <DocField
          id={`incdoc-unit-expected-qty-${uid}`}
          label="How many were ordered? *"
          type="number"
          value={row.expected_quantity}
          onChange={(v) => onPatch(uid, { expected_quantity: v })}
          disabled={disabled}
          hint={`In pieces. ${totalPieces} arrived, so the discrepancy is written down rather than implied.`}
        />
      )}

      {!onlyUnit && (
        <button
          type="button"
          className="ak-btn ak-btn--sm"
          onClick={() => onRemove(uid)}
          disabled={disabled}
        >
          Remove unit {index}
        </button>
      )}
    </fieldset>
  );
}
