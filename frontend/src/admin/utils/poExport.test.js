import { distinctDeliverLocations, buildPODocumentHtml } from './poExport';
import * as XLSX from 'xlsx';
import { buildPOWorkbook } from './poExport';

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

    // Per-page isolation: each warehouse page carries only its own item + subtotal.
    const [, alpha, bravo] = html.split('class="po-page"');
    expect(alpha).toContain('A1');
    expect(alpha).not.toContain('B1');
    expect(alpha).toContain('200.00');   // Alpha page total
    expect(bravo).toContain('B1');
    expect(bravo).not.toContain('A1');
    expect(bravo).toContain('300.00');   // Bravo page total
  });

  test('single warehouse produces one page with all its items', () => {
    const p = twoWarehousePayload();
    p.ship_to_locations = [p.ship_to_locations[0]];
    p.items = [p.items[0]];
    p.total_amount = 200;
    const html = buildPODocumentHtml(p);
    const pages = html.split('class="po-page"');
    expect(pages.length - 1).toBe(1);
    const [, only] = pages;
    expect(only).toContain('A1');
    expect(only).toContain('200.00');   // single-page total
  });
});

describe('buildPOWorkbook', () => {
  const payload = {
    po_number: 'PO-1',
    company: { name: 'Airking' },
    supplier: { name: 'Acme' },
    ship_to: { name: 'Airking CDO Branch', address: 'Zone 5, Bulua, CDO' },
    ship_to_locations: [
      { location_id: '5', name: 'Alpha', address: '1 A St, Cebu' },
      { location_id: '9', name: 'Bravo', address: '2 B St, Mandaue' },
    ],
    items: [
      { location_id: '5', warehouse_name: 'Alpha', product_code: 'A1', qty: 2, unit: 'pc', unit_price: 100, subtotal: 200 },
      { location_id: '9', warehouse_name: 'Bravo', product_code: 'B1', qty: 3, unit: 'pc', unit_price: 100, subtotal: 300 },
    ],
    total_amount: 500,
  };

  test('creates one sheet per warehouse, each carrying its own address', () => {
    const wb = buildPOWorkbook(payload);
    expect(wb.SheetNames).toHaveLength(2);
    expect(wb.SheetNames).toEqual(expect.arrayContaining(['Alpha', 'Bravo']));
    const alphaCsv = XLSX.utils.sheet_to_csv(wb.Sheets['Alpha']);
    expect(alphaCsv).toContain('1 A St, Cebu');
    expect(alphaCsv).toContain('A1');
    expect(alphaCsv).not.toContain('B1'); // Bravo's item not on Alpha's sheet
  });

  test('single warehouse yields a single sheet', () => {
    const p = { ...payload, ship_to_locations: [payload.ship_to_locations[0]], items: [payload.items[0]], total_amount: 200 };
    const wb = buildPOWorkbook(p);
    expect(wb.SheetNames).toHaveLength(1);
  });
});
