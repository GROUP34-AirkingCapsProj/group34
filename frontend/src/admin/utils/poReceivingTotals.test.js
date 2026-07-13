import {
  linesForLocation,
  lineMoney,
  locationAmount,
  locationItemCount,
  warehouseBreakdown,
} from './poReceivingTotals';

// A multi-warehouse PO: 2 lines for warehouse "10", 1 line for warehouse "20".
const multiPo = {
  location_id: 10,
  total_amount: 300,
  details: [
    { po_detail_id: 1, location_id: 10, location_name: 'Warehouse A', quantity_ordered: 2, unit_price: 50, subtotal: 100 },
    { po_detail_id: 2, location_id: 10, location_name: 'Warehouse A', quantity_ordered: 1, unit_price: 50, subtotal: 50 },
    { po_detail_id: 3, location_id: 20, location_name: 'Warehouse B', quantity_ordered: 3, unit_price: 50, subtotal: 150 },
  ],
};

// Legacy single-warehouse PO: lines have no location_id, fall back to header.
const legacyPo = {
  location_id: 10,
  total_amount: 80,
  details: [
    { po_detail_id: 9, quantity_ordered: 4, unit_price: 20, subtotal: 80 },
  ],
};

describe('poReceivingTotals', () => {
  test('lineMoney uses subtotal, falls back to qty * unit_price', () => {
    expect(lineMoney({ subtotal: 100 })).toBe(100);
    expect(lineMoney({ quantity_ordered: 3, unit_price: 50 })).toBe(150);
    expect(lineMoney({})).toBe(0);
  });

  test('linesForLocation filters to the given warehouse', () => {
    expect(linesForLocation(multiPo, '10')).toHaveLength(2);
    expect(linesForLocation(multiPo, '20')).toHaveLength(1);
  });

  test('linesForLocation returns all lines when locationId is null (admin)', () => {
    expect(linesForLocation(multiPo, null)).toHaveLength(3);
  });

  test('legacy lines without location_id fall back to header location_id', () => {
    expect(linesForLocation(legacyPo, '10')).toHaveLength(1);
    expect(locationAmount(legacyPo, '10')).toBe(80);
  });

  test('locationAmount sums only the warehouse slice', () => {
    expect(locationAmount(multiPo, '10')).toBe(150);
    expect(locationAmount(multiPo, '20')).toBe(150);
    expect(locationAmount(multiPo, null)).toBe(300);
  });

  test('locationItemCount counts only the warehouse slice', () => {
    expect(locationItemCount(multiPo, '10')).toBe(2);
    expect(locationItemCount(multiPo, null)).toBe(3);
  });

  test('warehouseBreakdown groups and sums per warehouse', () => {
    const b = warehouseBreakdown(multiPo);
    expect(b).toHaveLength(2);
    const a = b.find((x) => x.locationName === 'Warehouse A');
    const c = b.find((x) => x.locationName === 'Warehouse B');
    expect(a).toMatchObject({ amount: 150, count: 2 });
    expect(c).toMatchObject({ amount: 150, count: 1 });
  });

  test('warehouseBreakdown yields one entry for a single-warehouse PO', () => {
    expect(warehouseBreakdown(legacyPo)).toHaveLength(1);
  });
});
