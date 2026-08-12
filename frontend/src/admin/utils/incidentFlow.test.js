import { toBackendInstant, totalPiecesFrom } from './incidentFlow';

/**
 * The two conversions that were live production bugs, and are still live code:
 * IncidentDocument sends the same UTC instant and does the same pack
 * arithmetic.
 *
 * A bare datetime-local value made the UTC server read a Manila "now" as eight
 * hours ahead and reject every filing that carried a timestamp; reporting "1"
 * for a damaged box wrote off a single PIECE. The five-step wizard these once
 * also covered is gone, and its stepsFor block went with it.
 */

describe('toBackendInstant', () => {
  // A bare `datetime-local` value has no zone. Sent verbatim it made the UTC
  // server read a Manila "now" as eight hours ahead, so before_or_equal:now
  // rejected every filing that carried a timestamp.
  it('turns a bare local wall-time into a UTC instant', () => {
    const out = toBackendInstant('2026-08-09T16:02');
    // Zone-marked, which the bare input was not.
    expect(out).toMatch(/Z$/);
    // …and it still means the same moment: read back in the device's zone it
    // is the wall-time that was typed. Asserting against
    // `new Date(input).toISOString()` would only recompute the implementation
    // and would pass even if the conversion were wrong.
    const back = new Date(out);
    expect(back.getFullYear()).toBe(2026);
    expect(back.getMonth()).toBe(7);   // August, zero-indexed
    expect(back.getDate()).toBe(9);
    expect(back.getHours()).toBe(16);
    expect(back.getMinutes()).toBe(2);
  });

  it('returns null for an empty value rather than an Invalid Date', () => {
    expect(toBackendInstant('')).toBeNull();
    expect(toBackendInstant(null)).toBeNull();
  });
});

describe('totalPiecesFrom', () => {
  // Stock is stored in PIECES everywhere. Reporting "1" for a damaged box used
  // to write off a single piece.
  it('converts packs plus loose pieces to pieces', () => {
    expect(totalPiecesFrom({ packs: 2, loosePieces: 5, quantity: 1, piecesPerPackage: 10 }))
      .toBe(25);
  });

  it('uses the plain quantity when the product has no pack size', () => {
    expect(totalPiecesFrom({ packs: 9, loosePieces: 9, quantity: 3, piecesPerPackage: 1 }))
      .toBe(3);
  });

  it('reads empty and non-numeric inputs as zero, never NaN', () => {
    expect(totalPiecesFrom({ packs: '', loosePieces: '', quantity: '', piecesPerPackage: 10 }))
      .toBe(0);
    expect(totalPiecesFrom({ packs: 'x', loosePieces: '', quantity: '', piecesPerPackage: 0 }))
      .toBe(0);
  });

  it('never returns a negative total', () => {
    expect(totalPiecesFrom({ packs: 0, loosePieces: 0, quantity: -5, piecesPerPackage: 1 }))
      .toBe(0);
  });
});
