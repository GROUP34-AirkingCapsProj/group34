import { toOrderQty } from './restockUnits';

describe('toOrderQty', () => {
  it('converts pieces to packages for installation materials, rounding up', () => {
    const prod = { type: 'installation_material', piecesPerPackage: 50 };
    expect(toOrderQty(135, prod)).toBe(3); // 135/50 = 2.7 -> ceil = 3
  });

  it('rounds up on a non-exact multiple (the reported bug scenario)', () => {
    // Real dev-data case: 135 pieces needed, 35 pieces/package -> ceil(135/35) = 4
    const prod = { type: 'installation_material', piecesPerPackage: 35 };
    expect(toOrderQty(135, prod)).toBe(4);
  });

  it('does not round up when it divides evenly', () => {
    const prod = { type: 'installation_material', piecesPerPackage: 50 };
    expect(toOrderQty(300, prod)).toBe(6);
  });

  it('passes pieces through unchanged for appliances', () => {
    const prod = { type: 'appliance', piecesPerPackage: 1 };
    expect(toOrderQty(4, prod)).toBe(4);
  });

  it('passes pieces through unchanged when pieces_per_package is 1 or less', () => {
    const prod = { type: 'installation_material', piecesPerPackage: 1 };
    expect(toOrderQty(50, prod)).toBe(50);
  });

  it('passes pieces through unchanged when pieces_per_package is missing', () => {
    const prod = { type: 'installation_material' };
    expect(toOrderQty(50, prod)).toBe(50);
  });

  it('passes pieces through unchanged when prod is missing entirely', () => {
    expect(toOrderQty(50, undefined)).toBe(50);
  });
});
