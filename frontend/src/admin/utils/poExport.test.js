import { distinctDeliverLocations, buildPODocumentHtml } from './poExport';

describe('distinctDeliverLocations', () => {
  test('dedupes enriched PO details by location, first-seen order, joined address', () => {
    const details = [
      { location_id: 5, location: { location_name: 'Alpha', address: '1 A St', city: 'Cebu', province: 'Cebu', region: 'VII', contact_number: '111', email: 'a@w', manager_name: 'MA' } },
      { location_id: 9, location: { location_name: 'Bravo', address: '2 B St', city: 'Mandaue' } },
      { location_id: 5, location: { location_name: 'Alpha' } }, // duplicate of first
    ];
    const out = distinctDeliverLocations(details);
    expect(out).toHaveLength(2);
    expect(out[0]).toEqual({
      location_id: '5', name: 'Alpha', address: '1 A St, Cebu, Cebu, VII',
      contact_number: '111', email: 'a@w', manager_name: 'MA',
    });
    expect(out[1].name).toBe('Bravo');
    expect(out[1].address).toBe('2 B St, Mandaue');
  });

  test('resolves preview line items via locationsById lookup', () => {
    const items = [
      { location_id: 9, warehouse_name: 'Bravo' },
      { location_id: 9, warehouse_name: 'Bravo' },
    ];
    const byId = { 9: { location_name: 'Bravo', address: '2 B St', city: 'Mandaue', contact_number: '222', email: 'b@w', manager_name: 'MB' } };
    const out = distinctDeliverLocations(items, byId);
    expect(out).toHaveLength(1);
    expect(out[0]).toEqual({
      location_id: '9', name: 'Bravo', address: '2 B St, Mandaue',
      contact_number: '222', email: 'b@w', manager_name: 'MB',
    });
  });
});

const twoWarehousePayload = () => ({
  po_number: 'PO-1',
  company: { name: 'Airking', address: 'HQ Rd', email: 'hq@air' },
  supplier: { name: 'Acme', address: 'Acme Rd' },
  ship_to: { name: 'Airking CDO Branch', address: 'Zone 5, Bulua, CDO' },
  ship_to_locations: [
    { location_id: '5', name: 'Alpha', address: '1 A St, Cebu', contact_number: '111', email: 'a@w', manager_name: 'MA' },
    { location_id: '9', name: 'Bravo', address: '2 B St, Mandaue', contact_number: '222', email: 'b@w', manager_name: 'MB' },
  ],
  items: [
    { location_id: '5', warehouse_name: 'Alpha', product_code: 'A1', name: 'Item A', qty: 2, unit: 'pc', unit_price: 100, subtotal: 200 },
    { location_id: '9', warehouse_name: 'Bravo', product_code: 'B1', name: 'Item B', qty: 3, unit: 'pc', unit_price: 100, subtotal: 300 },
  ],
  total_amount: 500,
});

describe('buildPODocumentHtml', () => {
  test('emits one page per warehouse with its own address and items, plus a page break', () => {
    const html = buildPODocumentHtml(twoWarehousePayload());
    const pages = html.split('class="po-page"').length - 1;
    expect(pages).toBe(2);
    expect(html).toContain('page-break-after');
    expect(html).toContain('1 A St, Cebu');
    expect(html).toContain('2 B St, Mandaue');
    expect(html).toContain('Airking CDO Branch');
    expect(html).toContain('Zone 5, Bulua, CDO');
    expect(html).toContain('Deliver To (Warehouse): Alpha');
    expect(html).toContain('Deliver To (Warehouse): Bravo');
  });

  test('single warehouse produces one page and no trailing page break', () => {
    const p = twoWarehousePayload();
    p.ship_to_locations = [p.ship_to_locations[0]];
    p.items = [p.items[0]];
    p.total_amount = 200;
    const html = buildPODocumentHtml(p);
    expect(html.split('class="po-page"').length - 1).toBe(1);
  });
});
