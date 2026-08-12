import React, { useCallback, useMemo, useState } from 'react';
import { totalPiecesFrom } from '../../utils/incidentFlow';
import { UNIT_ROLES } from '../../utils/incidentItems';
import ProductPickField from './steps/ProductPickField';
import QuantityInPieces from './steps/QuantityInPieces';
import BatchSelect from './steps/BatchSelect';

/**
 * The physical things one report is about.
 *
 * A report used to be one product and one number. It is not: a split-system
 * aircon is an indoor unit and an outdoor unit — two model codes, two serials,
 * one incident — and the inspector may well scrap one half and restock the
 * other. So the payload carries a row per unit and the backend quarantines,
 * inspects and settles each independently.
 *
 * **Unit 1 is not editable here.** It is whatever was entered on the previous
 * two steps, shown read-only. The flat `product_id`/`quantity`/`batch_id` on the
 * payload are that same entry — they are still what the picking modal sends and
 * what IncidentService falls back to — so letting this step edit them too would
 * create a second source of truth for one value and a Back button that silently
 * un-does the edit. Only the sticker fields (role, model code, serial) are asked
 * for here, because the earlier steps have nowhere to ask them.
 *
 * `model_code` and `serial_number` are free text stored ALONGSIDE the product
 * and serial foreign keys, not derived from them: the sticker on a defective
 * unit may match nothing in the catalogue, and that mismatch is very often the
 * incident itself.
 */

let nextUid = 1;
const newRow = (unitRole) => ({
  uid: `unit-${nextUid++}`,
  product_id: '',
  unit_role: unitRole,
  model_code: '',
  serial_number: '',
  packs: 0,
  loose_pieces: 0,
  quantity: 1,
  batch_id: '',
});

/** The pack size and package noun for a product, or the no-packs defaults. */
const packingOf = (product) => {
  const per = Number(product?.pieces_per_package) || 0;
  return {
    piecesPerPackage: per,
    usesPacks: per > 1,
    packageUnit: product?.consumable_detail?.package_unit || product?.package_unit || 'pkg',
  };
};

export default function IncidentItemsStep({
  // Unit 1 — read-only, owned by the earlier steps.
  primaryProductName,
  primaryTotalPieces,
  primaryBatchId,
  primaryUnitRole, setPrimaryUnitRole,
  primaryModelCode, setPrimaryModelCode,
  primarySerialNumber, setPrimarySerialNumber,
  // Units 2..n.
  extraItems, setExtraItems,
  // Shared lookups.
  products, batches, batchesLoading, locationId,
  disabled,
}) {
  const addUnit = useCallback(() => {
    setExtraItems((rows) => {
      // The overwhelmingly common reason to add a second unit is a split
      // system, so the pair defaults to indoor + outdoor and saves two
      // dropdown interactions. Both remain changeable, and a third unit
      // onwards gets no guess at all.
      const role = rows.length === 0 ? 'outdoor' : 'single';
      return [...rows, newRow(role)];
    });
    setPrimaryUnitRole((role) => (role === 'single' ? 'indoor' : role));
  }, [setExtraItems, setPrimaryUnitRole]);

  const patchRow = useCallback((uid, patch) => {
    setExtraItems((rows) => rows.map((r) => (r.uid === uid ? { ...r, ...patch } : r)));
  }, [setExtraItems]);

  const removeRow = useCallback((uid) => {
    setExtraItems((rows) => rows.filter((r) => r.uid !== uid));
  }, [setExtraItems]);

  return (
    <>
      <div className="ak-muted" style={{ fontSize: '0.84rem' }}>
        One row per physical unit. A split system is two — an indoor unit and an
        outdoor unit — and each is inspected and settled on its own, so one half
        can be scrapped while the other goes back into stock.
      </div>

      <fieldset
        className="ak-card"
        style={{ border: '1px solid var(--ak-border, #ddd)', padding: '0.75rem', margin: 0 }}
      >
        <legend className="ak-label" style={{ padding: '0 0.35rem' }}>Unit 1</legend>

        <div className="ak-muted" style={{ fontSize: '0.84rem', marginBottom: '0.6rem' }}>
          <strong>{primaryProductName || 'No product chosen'}</strong>
          {` · ${primaryTotalPieces} pcs`}
          {primaryBatchId ? ` · batch #${primaryBatchId}` : ''}
          {' — change any of this on the previous steps.'}
        </div>

        <UnitRoleSelect
          fieldId="incident-item-role-primary"
          value={primaryUnitRole}
          onChange={setPrimaryUnitRole}
          disabled={disabled}
        />
        <StickerFields
          idPrefix="incident-item-primary"
          modelCode={primaryModelCode} setModelCode={setPrimaryModelCode}
          serialNumber={primarySerialNumber} setSerialNumber={setPrimarySerialNumber}
          disabled={disabled}
        />
      </fieldset>

      {extraItems.map((row, i) => (
        <ExtraUnitRow
          key={row.uid}
          row={row}
          index={i + 2}
          products={products}
          batches={batches}
          batchesLoading={batchesLoading}
          locationId={locationId}
          onPatch={patchRow}
          onRemove={removeRow}
          disabled={disabled}
        />
      ))}

      <div>
        <button type="button" className="ak-btn" onClick={addUnit} disabled={disabled}>
          + Add another unit
        </button>
      </div>
    </>
  );
}

/** The role a unit plays. `single` is a whole appliance; the other two are halves. */
function UnitRoleSelect({ fieldId, value, onChange, disabled }) {
  return (
    <div className="ak-field">
      <label className="ak-label" htmlFor={fieldId}>Unit</label>
      <select
        id={fieldId}
        className="ak-select"
        value={value}
        disabled={disabled}
        onChange={(e) => onChange(e.target.value)}
      >
        {UNIT_ROLES.map((r) => (
          <option key={r.value} value={r.value}>{r.label}</option>
        ))}
      </select>
    </div>
  );
}

/** What is printed on the unit, verbatim — see the note at the top of the file. */
function StickerFields({ idPrefix, modelCode, setModelCode, serialNumber, setSerialNumber, disabled }) {
  return (
    <div style={{ display: 'flex', gap: '0.6rem', flexWrap: 'wrap' }}>
      <div className="ak-field" style={{ flex: '1 1 180px' }}>
        <label className="ak-label" htmlFor={`${idPrefix}-model`}>Model code on the unit</label>
        <input
          id={`${idPrefix}-model`}
          className="ak-input"
          type="text"
          maxLength={100}
          value={modelCode}
          onChange={(e) => setModelCode(e.target.value)}
          placeholder="As printed on the sticker"
          disabled={disabled}
        />
      </div>
      <div className="ak-field" style={{ flex: '1 1 180px' }}>
        <label className="ak-label" htmlFor={`${idPrefix}-serial`}>Serial number on the unit</label>
        <input
          id={`${idPrefix}-serial`}
          className="ak-input"
          type="text"
          maxLength={100}
          value={serialNumber}
          onChange={(e) => setSerialNumber(e.target.value)}
          placeholder="As printed on the sticker"
          disabled={disabled}
        />
      </div>
    </div>
  );
}

/**
 * A unit beyond the first: fully editable, because nothing earlier in the flow
 * described it.
 */
function ExtraUnitRow({ row, index, products, batches, batchesLoading, locationId, onPatch, onRemove, disabled }) {
  const { uid, product_id: productId } = row;
  const [scanHit, setScanHit] = useState(null);

  // Stable per row: ProductPickField resets the hit whenever the location
  // changes, and an inline arrow would make that effect re-fire every render.
  const setProductId = useCallback((v) => onPatch(uid, { product_id: v }), [onPatch, uid]);
  const handleScanHit = useCallback((hit) => {
    setScanHit(hit);
    // A scan already read the sticker — prefill rather than make the reporter
    // copy it out by hand. Only when the field is still empty, so a correction
    // typed before scanning is never overwritten.
    if (!hit) return;
    const patch = {};
    if (hit.serial?.serial_number) patch.serial_number = hit.serial.serial_number;
    if (hit.product?.product_code) patch.model_code = hit.product.product_code;
    onPatch(uid, patch);
  }, [onPatch, uid]);

  const product = products.find((p) => String(p.product_id) === String(productId));
  const { piecesPerPackage, usesPacks, packageUnit } = packingOf(product);
  const totalPieces = totalPiecesFrom({
    packs: row.packs, loosePieces: row.loose_pieces, quantity: row.quantity, piecesPerPackage,
  });

  const availableBatches = useMemo(() => {
    if (!Array.isArray(batches) || !productId) return [];
    return batches.filter((b) => String(b.product_id) === String(productId)
      && (!locationId || String(b.location_id) === String(locationId)));
  }, [batches, productId, locationId]);

  return (
    <fieldset
      className="ak-card"
      style={{ border: '1px solid var(--ak-border, #ddd)', padding: '0.75rem', margin: 0 }}
    >
      <legend className="ak-label" style={{ padding: '0 0.35rem' }}>Unit {index}</legend>

      <ProductPickField
        label={`Product for unit ${index} *`}
        products={products}
        locationId={locationId}
        productId={productId}
        setProductId={setProductId}
        scanHit={scanHit}
        onScanHit={handleScanHit}
        idPrefix={`incident-item-${uid}`}
        disabled={disabled}
      />

      <UnitRoleSelect
        fieldId={`incident-item-role-${uid}`}
        value={row.unit_role}
        onChange={(v) => onPatch(uid, { unit_role: v })}
        disabled={disabled}
      />

      <StickerFields
        idPrefix={`incident-item-${uid}`}
        modelCode={row.model_code}
        setModelCode={(v) => onPatch(uid, { model_code: v })}
        serialNumber={row.serial_number}
        setSerialNumber={(v) => onPatch(uid, { serial_number: v })}
        disabled={disabled}
      />

      <QuantityInPieces
        label="Quantity"
        packs={row.packs} setPacks={(v) => onPatch(uid, { packs: v })}
        loosePieces={row.loose_pieces} setLoosePieces={(v) => onPatch(uid, { loose_pieces: v })}
        quantity={row.quantity} setQuantity={(v) => onPatch(uid, { quantity: v })}
        usesPacks={usesPacks} piecesPerPackage={piecesPerPackage}
        packageUnit={packageUnit} totalPieces={totalPieces}
        packNote="Stock is recorded in pieces."
        fieldId={`incident-item-qty-${uid}`}
        disabled={disabled}
      />

      <BatchSelect
        label="Batch (optional)"
        emptyLabel="Not batch-specific"
        batchId={row.batch_id} setBatchId={(v) => onPatch(uid, { batch_id: v })}
        availableBatches={availableBatches} batchesLoading={batchesLoading}
        productId={productId}
        fieldId={`incident-item-batch-${uid}`}
        disabled={disabled}
      />

      <button
        type="button"
        className="ak-btn ak-btn--sm"
        onClick={() => onRemove(uid)}
        disabled={disabled}
      >
        Remove unit {index}
      </button>
    </fieldset>
  );
}
