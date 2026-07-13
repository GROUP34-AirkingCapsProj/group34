import { distinctDeliverLocations } from './poExport';

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
