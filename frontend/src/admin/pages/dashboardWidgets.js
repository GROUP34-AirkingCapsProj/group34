/**
 * Permission-driven dashboard widget registry.
 * Each widget is shown only when the user has the corresponding permission.
 */
export const DASHBOARD_WIDGETS = [
  { id: 'po_summary',        perm: 'po_status_tracking.read' },
  { id: 'low_stock',         perm: 'automated_inventory_monitoring.read' },
  { id: 'ist_queue',         perm: 'inventory_transfers.read' },
  { id: 'sales_summary',     perm: 'pos_sales_recording.read' },
  { id: 'receiving_summary', perm: 'delivery_receiving.read' },
];

/**
 * Returns the list of widget IDs the user is allowed to see,
 * based on their flat permissions map ({ "feature.action": bool }).
 *
 * @param {Object|null|undefined} permissions
 * @returns {string[]}
 */
export function pickDashboardWidgets(permissions) {
  if (!permissions) return [];
  return DASHBOARD_WIDGETS
    .filter((w) => permissions[w.perm] === true)
    .map((w) => w.id);
}
