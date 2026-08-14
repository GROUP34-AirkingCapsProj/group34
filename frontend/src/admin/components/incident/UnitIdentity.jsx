import React from 'react';
import { itemStatusMeta, unitRoleLabel } from '../../utils/incidentItems';
import { formatPieces } from '../../utils/packageFormat';

/**
 * WHICH physical unit this is, and where it currently stands.
 *
 * Owned by neither the editable half (InspectionFields) nor the read-only half
 * (InspectionSummary), because both render it and neither may drift from the
 * other. The inspector choosing an outcome and the admin confirming it are
 * looking at two views of the SAME unit; if the two views name it differently —
 * a different product, a different serial, a different status — the admin
 * cannot tell whether they are confirming the unit the inspector decided about.
 * Drift between the renderings is drift in what the admin believes.
 *
 * Deliberately has no `mode` prop and no state. It renders one set of facts one
 * way, which is the entire point of it existing. If it ever needs to know which
 * half is calling it, that is the signal this extraction was wrong and the two
 * headers were never really the same thing.
 *
 * MUST be rendered as a direct child of a `<fieldset>`. It returns a fragment
 * whose first node is a `<legend>`, and a legend outside a fieldset is invalid
 * DOM that no lint rule and no test here would catch — it simply renders
 * inconsistently between browsers.
 */
export function UnitIdentity({ item }) {
  const status = itemStatusMeta(item.status);
  const qtyLabel = formatPieces(item.quantity, item.pieces_per_package, item.package_unit);

  return (
    <>
      <legend className="incdoc__unit-legend">
        {unitRoleLabel(item.unit_role)}{' '}
        <span className={`ak-badge ${status.badge}`}>{status.label}</span>
      </legend>

      <div style={{ fontSize: '0.86rem', marginBottom: '0.5rem' }}>
        <strong>{item.product_name || `#${item.product_id}`}</strong> · {qtyLabel}
        {item.batch_id ? ` · batch #${item.batch_id}` : ''}
        {item.model_code ? ` · model ${item.model_code}` : ''}
        {item.serial_number ? ` · SN ${item.serial_number}` : ''}
      </div>
    </>
  );
}

export default UnitIdentity;
