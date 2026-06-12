import { pickDashboardWidgets } from './dashboardWidgets';

test('PO widget appears only when user can read PO status', () => {
  const withPO = pickDashboardWidgets({ 'po_status_tracking.read': true });
  expect(withPO).toContain('po_summary');
  const without = pickDashboardWidgets({});
  expect(without).not.toContain('po_summary');
});

test('low_stock widget appears only with automated_inventory_monitoring.read', () => {
  const with_ = pickDashboardWidgets({ 'automated_inventory_monitoring.read': true });
  expect(with_).toContain('low_stock');
  const without = pickDashboardWidgets({});
  expect(without).not.toContain('low_stock');
});

test('ist_queue widget appears only with inventory_transfers.read', () => {
  const with_ = pickDashboardWidgets({ 'inventory_transfers.read': true });
  expect(with_).toContain('ist_queue');
  const without = pickDashboardWidgets({});
  expect(without).not.toContain('ist_queue');
});

test('sales_summary widget appears only with pos_sales_recording.read', () => {
  const with_ = pickDashboardWidgets({ 'pos_sales_recording.read': true });
  expect(with_).toContain('sales_summary');
  const without = pickDashboardWidgets({});
  expect(without).not.toContain('sales_summary');
});

test('receiving_summary widget appears only with delivery_receiving.read', () => {
  const with_ = pickDashboardWidgets({ 'delivery_receiving.read': true });
  expect(with_).toContain('receiving_summary');
  const without = pickDashboardWidgets({});
  expect(without).not.toContain('receiving_summary');
});

test('multiple permissions yield multiple widgets', () => {
  const perms = {
    'po_status_tracking.read': true,
    'automated_inventory_monitoring.read': true,
  };
  const result = pickDashboardWidgets(perms);
  expect(result).toContain('po_summary');
  expect(result).toContain('low_stock');
  expect(result).not.toContain('ist_queue');
});

test('false permission does not grant widget', () => {
  const result = pickDashboardWidgets({ 'po_status_tracking.read': false });
  expect(result).not.toContain('po_summary');
});

test('null/undefined permissions returns empty array', () => {
  expect(pickDashboardWidgets(null)).toEqual([]);
  expect(pickDashboardWidgets(undefined)).toEqual([]);
});
