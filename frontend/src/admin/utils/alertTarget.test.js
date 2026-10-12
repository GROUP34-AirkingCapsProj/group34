// frontend/src/admin/utils/alertTarget.test.js
import {
  pendingPoTarget,
  pendingPrTarget,
  pendingRegistrationTarget,
  lowStockTarget,
  auditTarget,
  targetHref,
} from './alertTarget';

describe('alertTarget', () => {
  it('maps a pending PO to the status tab', () => {
    expect(pendingPoTarget({ id: 42 })).toEqual({
      path: '/draft-po-creator', focusKey: 'po:42',
    });
    expect(pendingPoTarget({ po_id: 7 })).toEqual({
      path: '/draft-po-creator', focusKey: 'po:7',
    });
    expect(pendingPoTarget({})).toBeNull();
  });

  it('maps a pending PR to the approvals page', () => {
    expect(pendingPrTarget({ id: 5 })).toEqual({
      path: '/purchase-requests', focusKey: 'pr:5',
    });
  });

  it('returns null for pending registrations (no destination yet)', () => {
    expect(pendingRegistrationTarget({ id: 3 })).toBeNull();
  });

  it('maps low stock to purchase requests', () => {
    expect(lowStockTarget({ inventory_id: 8, product_id: 2 })).toEqual({
      path: '/purchase-requests', query: 'tab=create',
    });
    expect(lowStockTarget({ inventory_id: 8 })).toEqual({
      path: '/purchase-requests', query: 'tab=create',
    });
    expect(lowStockTarget({})).toBeNull();
  });

  it('maps audit entries only for mappable tables', () => {
    expect(auditTarget({ table_name: 'purchase_orders', record_id: 12, action: 'update' }))
      .toEqual({ path: '/draft-po-creator', focusKey: 'po:12' });
    expect(auditTarget({ table_name: 'transfers', record_id: 4, action: 'create' }))
      .toEqual({ path: '/transfers', focusKey: 'transfer:4' });
    expect(auditTarget({ table_name: 'products', record_id: 9, action: 'update' }))
      .toEqual({ path: '/items', focusKey: 'product:9' });
    expect(auditTarget({ table_name: 'inventory', record_id: 3, action: 'update' }))
      .toEqual({ path: '/inventory', focusKey: 'inv:3' });
  });

  it('returns null for unmappable audit entries', () => {
    expect(auditTarget({ table_name: 'users', record_id: 1, action: 'update' })).toBeNull();
    expect(auditTarget({ table_name: 'sales', record_id: 1, action: 'create' })).toBeNull();
    expect(auditTarget({ table_name: 'purchase_orders', record_id: 1, action: 'delete' })).toBeNull();
    expect(auditTarget({ table_name: 'purchase_orders', action: 'update' })).toBeNull();
    expect(auditTarget(null)).toBeNull();
  });

  it('builds an href from a target', () => {
    expect(targetHref({ path: '/inventory', focusKey: 'inv:8' }))
      .toBe('/inventory?focus=inv%3A8');
    expect(targetHref({ path: '/purchase-requests', query: 'tab=create' }))
      .toBe('/purchase-requests?tab=create');
  });
});
